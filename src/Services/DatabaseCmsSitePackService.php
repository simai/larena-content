<?php

declare(strict_types=1);

namespace Larena\Content\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Larena\Content\Access\ContentAuthorizer;
use Larena\Content\Audit\ContentAuditEmitter;
use Larena\Content\Audit\ContentAuditPayload;
use Larena\Content\Contracts\CmsSitePackService;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Persistence\DatabaseSiteStructureRepository;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\SitePack\CmsSitePackArchive;
use Larena\Content\SitePack\CmsSitePackCodec;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\CmsSitePackReport;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;
use Larena\Filesystem\Contracts\PortableLogicalFileStore;
use Larena\Filesystem\Exceptions\PortableLogicalFileFailed;
use Larena\Filesystem\ValueObjects\PortableLogicalFileDescriptor;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Throwable;

final readonly class DatabaseCmsSitePackService implements CmsSitePackService
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private DatabaseContentRepository $repository,
        private DatabaseSiteStructureRepository $siteStructures,
        private ContentAuthorizer $authorizer,
        private ContentParticipantGuard $participants,
        private ContentStorageGateway $storage,
        private ContentSchemaMapper $schemas,
        private ContentInputGuard $input,
        private ContentTypeService $types,
        private PortableLogicalFileStore $files,
        private CmsSitePackArchive $archive,
        private CmsSitePackCodec $codec,
        private ContentAuditEmitter $audit,
    ) {
    }

    public function export(ActorContext $actor): CmsSitePackReport
    {
        $connection = $this->preflight($actor, 'content.sitepack.export');
        try {
            $snapshot = $this->exportSnapshot($actor);
            $stored = $this->archive->store($this->codec->build(
                $snapshot['entities'],
                $snapshot['assets'],
                $snapshot['blobs'],
                $snapshot['created_at'],
            ));
            $report = new CmsSitePackReport(
                $stored['package_ref'],
                $stored['digest'],
                'exported',
                $snapshot['counts'],
            );
            $this->auditReport($connection, 'content.sitepack.exported', 'content.sitepack.export', $actor, $report);

            return $report;
        } catch (ContentRejected $exception) {
            $this->auditFailure($connection, $actor, 'content.sitepack.export', null, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $rejected = new ContentRejected('sitepack_export_failed', previous: $exception);
            $this->auditFailure($connection, $actor, 'content.sitepack.export', null, $rejected);
            throw $rejected;
        }
    }

    public function verify(string $packageRef, ActorContext $actor): CmsSitePackReport
    {
        $connection = $this->preflight($actor, 'content.sitepack.verify');
        try {
            $loaded = $this->load($packageRef);
            $report = new CmsSitePackReport($packageRef, $loaded['digest'], 'verified', $loaded['counts']);
            $this->auditReport($connection, 'content.sitepack.verified', 'content.sitepack.verify', $actor, $report);

            return $report;
        } catch (ContentRejected $exception) {
            $this->auditFailure($connection, $actor, 'content.sitepack.verify', $packageRef, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $rejected = new ContentRejected('sitepack_verify_failed', previous: $exception);
            $this->auditFailure($connection, $actor, 'content.sitepack.verify', $packageRef, $rejected);
            throw $rejected;
        }
    }

    public function dryRun(string $packageRef, ActorContext $actor): CmsSitePackReport
    {
        $connection = $this->preflight($actor, 'content.sitepack.import.dry_run');
        try {
            $loaded = $this->load($packageRef);
            $plan = $this->plan($loaded['document'], $actor);
            $report = new CmsSitePackReport($packageRef, $loaded['digest'], 'planned', $plan);
            $this->auditReport(
                $connection,
                'content.sitepack.import_planned',
                'content.sitepack.import.dry_run',
                $actor,
                $report,
            );

            return $report;
        } catch (ContentRejected $exception) {
            $this->auditFailure($connection, $actor, 'content.sitepack.import.dry_run', $packageRef, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $rejected = new ContentRejected('sitepack_import_plan_failed', previous: $exception);
            $this->auditFailure($connection, $actor, 'content.sitepack.import.dry_run', $packageRef, $rejected);
            throw $rejected;
        }
    }

    public function import(string $packageRef, ActorContext $actor): CmsSitePackReport
    {
        $connection = $this->preflight($actor, 'content.sitepack.import.apply');
        $compensators = [];
        try {
            $loaded = $this->load($packageRef);
            $plan = $this->plan($loaded['document'], $actor);
            $connection->transaction(function () use ($loaded, $actor, &$compensators): void {
                foreach ($loaded['document']['assets'] as $asset) {
                    if ($asset['action'] !== 'create') {
                        continue;
                    }
                    $stream = fopen('php://temp', 'w+b');
                    if (!is_resource($stream)) {
                        throw new ContentRejected('sitepack_asset_stream_failed');
                    }
                    fwrite($stream, $asset['bytes']);
                    rewind($stream);
                    try {
                        $result = $this->files->import($asset['descriptor'], $stream);
                    } finally {
                        fclose($stream);
                    }
                    $compensators[] = $result;
                }
                $this->importTypes($loaded['document']['types'], $actor);
                $this->importItems($loaded['document']['items'], $loaded['document']['types'], $actor);
                $this->importSiteStructure($loaded['document']['structure']);
                $this->importRedirects($loaded['document']['redirects']);
                $report = new CmsSitePackReport(
                    $loaded['package_ref'],
                    $loaded['digest'],
                    'imported',
                    $loaded['counts'] + $this->actionCounts($loaded['document']),
                );
                $this->audit->emit(
                    'content.sitepack.imported',
                    $actor,
                    $loaded['package_ref'],
                    $this->auditPayload('content.sitepack.import.apply', $report),
                );
            }, 3);

            return new CmsSitePackReport(
                $packageRef,
                $loaded['digest'],
                'imported',
                $loaded['counts'] + $plan,
            );
        } catch (Throwable $exception) {
            foreach (array_reverse($compensators) as $result) {
                $result->compensate();
            }
            if ($exception instanceof ContentRejected) {
                $this->auditFailure($connection, $actor, 'content.sitepack.import.apply', $packageRef, $exception);
                throw $exception;
            }
            $rejected = new ContentRejected('sitepack_import_failed', previous: $exception);
            $this->auditFailure($connection, $actor, 'content.sitepack.import.apply', $packageRef, $rejected);
            throw $rejected;
        }
    }

    /**
     * @return array{entities:list<array<string,mixed>>,assets:list<array<string,mixed>>,blobs:array<string,string>,created_at:string,counts:array<string,int>}
     */
    private function exportSnapshot(ActorContext $actor): array
    {
        $this->repository->assertCompleteCompatible();
        $entities = [];
        $fileRefs = [];
        $latest = '1970-01-01 00:00:00.000000';
        $typeCount = 0;
        $revisionCount = 0;
        $typeFields = [];

        foreach ($this->allTypeRows() as $typeRow) {
            $typeKey = (string) $typeRow['type_key'];
            $typeCount++;
            $latest = max($latest, (string) $typeRow['updated_at']);
            $versions = [];
            foreach ($this->allTypeVersionRows($typeKey) as $versionRow) {
                $schema = $this->storage->schemaVersion(new StorageSchemaVersionRef(
                    (string) $versionRow['storage_schema_ref'],
                    (int) $versionRow['storage_schema_version'],
                ));
                $fields = $this->schemas->fieldDefinitions($schema);
                $typeFields[$typeKey][(int) $versionRow['version']] = $fields;
                $versions[] = 'content-type-version:'.$typeKey.':'.(int) $versionRow['version'];
                $entities[] = [
                    'id' => 'content-type-version:'.$typeKey.':'.(int) $versionRow['version'],
                    'type' => 'larena.content.type_version',
                    'attributes' => [
                        'type_key' => $typeKey,
                        'version' => (int) $versionRow['version'],
                        'storage_schema_version' => (int) $versionRow['storage_schema_version'],
                        'schema_hash' => (string) $versionRow['schema_hash'],
                        'fields' => array_map($this->fieldToArray(...), $fields),
                        'projection' => $this->decodeObject((string) $versionRow['projection_contract']),
                        'safe_metadata' => $this->decodeObject((string) $versionRow['safe_metadata']),
                    ],
                ];
                $latest = max($latest, (string) $versionRow['created_at']);
            }
            $entities[] = [
                'id' => 'content-type:'.$typeKey,
                'type' => 'larena.content.type',
                'attributes' => ['type_key' => $typeKey, 'current_version' => (int) $typeRow['current_version']],
                'relations' => ['versions' => $versions],
            ];
        }

        foreach ($this->allItemRows() as $itemRow) {
            $itemRef = (string) $itemRow['item_ref'];
            $uuid = (new ContentItemRef($itemRef))->uuid();
            $revisionIds = [];
            foreach ($this->allRevisionRows($itemRef) as $revisionRow) {
                $revisionCount++;
                $revision = (int) $revisionRow['revision'];
                $revisionIds[] = 'content-revision:'.$uuid.':'.str_pad((string) $revision, 10, '0', STR_PAD_LEFT);
                $storageVersion = $this->storage->readAdminVersion(new StorageRecordVersionRef(
                    (string) $revisionRow['storage_schema_ref'],
                    (string) $revisionRow['storage_record_ref'],
                    (int) $revisionRow['storage_record_version'],
                ), $actor);
                $attachments = array_map(
                    static fn (array $row): array => [
                        'logical_file_ref' => (string) $row['logical_file_ref'],
                        'role' => (string) $row['role'],
                        'position' => (int) $row['position'],
                    ],
                    $this->repository->attachmentRows($itemRef, $revision),
                );
                $relations = [
                    'type_version' => ['content-type-version:'.(string) $revisionRow['type_key'].':'.(int) $revisionRow['type_version']],
                ];
                $fields = $typeFields[(string) $revisionRow['type_key']][(int) $revisionRow['type_version']] ?? [];
                foreach ($fields as $field) {
                    $value = $storageVersion->values[$field->key] ?? null;
                    if (!is_string($value)) {
                        continue;
                    }
                    if ($field->propertyType === 'relation') {
                        $relations['relation.'.$field->key] = ['content-item:'.ContentItemRef::fromUuid($value)->uuid()];
                    } elseif ($field->propertyType === 'file') {
                        $relations['file.'.$field->key] = ['asset:'.$value];
                        $fileRefs[$value] = true;
                    }
                }
                if ($attachments !== []) {
                    $relations['attachments'] = array_map(
                        static fn (array $attachment): string => 'asset:'.$attachment['logical_file_ref'],
                        $attachments,
                    );
                    foreach ($attachments as $attachment) {
                        $fileRefs[$attachment['logical_file_ref']] = true;
                    }
                }
                ksort($relations, SORT_STRING);
                $entities[] = [
                    'id' => end($revisionIds),
                    'type' => 'larena.content.revision',
                    'attributes' => [
                        'item_ref' => $uuid,
                        'revision' => $revision,
                        'type_key' => (string) $revisionRow['type_key'],
                        'locale' => (string) $revisionRow['locale'],
                        'type_version' => (int) $revisionRow['type_version'],
                        'slug' => (string) $revisionRow['slug'],
                        'status' => (string) $revisionRow['status'],
                        'visibility' => (string) $revisionRow['visibility'],
                        'created_at' => (string) $revisionRow['created_at'],
                        'values' => $storageVersion->values,
                        'attachments' => $attachments,
                    ],
                    'relations' => $relations,
                ];
                $latest = max($latest, (string) $revisionRow['created_at']);
            }
            $entities[] = [
                'id' => 'content-item:'.$uuid,
                'type' => 'larena.content.item',
                'attributes' => [
                    'item_ref' => $uuid,
                    'type_key' => (string) $itemRow['type_key'],
                    'locale' => (string) $itemRow['locale'],
                    'current_revision' => (int) $itemRow['current_revision'],
                    'current_slug' => (string) $itemRow['current_slug'],
                    'current_status' => (string) $itemRow['current_status'],
                    'current_visibility' => (string) $itemRow['current_visibility'],
                    'published_revision' => $itemRow['published_revision'],
                    'published_slug' => $itemRow['published_slug'],
                    'published_at' => $itemRow['published_at'],
                    'created_at' => (string) $itemRow['created_at'],
                    'updated_at' => (string) $itemRow['updated_at'],
                ],
                'relations' => ['revisions' => $revisionIds],
            ];
            $latest = max($latest, (string) $itemRow['updated_at']);
        }

        $structureRevisionCount = 0;
        $seoCount = 0;
        $structureHead = $this->siteStructures->head();
        if ($structureHead !== null) {
            $structureRevisionIds = [];
            foreach ($this->siteStructures->revisions() as $structureRevision) {
                $revision = (int) $structureRevision['revision'];
                $structureRevisionCount++;
                $revisionId = 'content-site-structure-revision:primary:'.str_pad((string) $revision, 10, '0', STR_PAD_LEFT);
                $structureRevisionIds[] = $revisionId;
                $nodes = $this->siteStructureNodes((string) $structureRevision['nodes_json']);
                $seo = $this->siteStructureSeo((string) $structureRevision['seo_json']);
                $seoCount += count($seo);
                $targets = [];
                foreach ($nodes as $node) {
                    if ($node->contentItemRef !== null) {
                        $targets['content-item:'.$node->contentItemRef->uuid()] = true;
                    }
                }
                foreach ($seo as $metadata) {
                    $targets['content-item:'.$metadata->itemRef->uuid()] = true;
                }
                $targetRelations = array_keys($targets);
                sort($targetRelations, SORT_STRING);
                $entities[] = [
                    'id' => $revisionId,
                    'type' => 'larena.content.site_structure_revision',
                    'attributes' => [
                        'structure_ref' => 'primary',
                        'revision' => $revision,
                        'status' => (string) $structureRevision['status'],
                        'nodes' => array_map(static fn (SiteStructureNode $node): array => $node->toArray(), $nodes),
                        'seo' => array_map(static fn (SiteSeoMetadata $metadata): array => $metadata->toArray(), $seo),
                        'created_by' => (string) $structureRevision['created_by'],
                        'correlation_id' => (string) $structureRevision['correlation_id'],
                        'created_at' => (string) $structureRevision['created_at'],
                    ],
                    'relations' => ['content_items' => $targetRelations],
                ];
                $latest = max($latest, (string) $structureRevision['created_at']);
            }
            $entities[] = [
                'id' => 'content-site-structure:primary',
                'type' => 'larena.content.site_structure',
                'attributes' => [
                    'structure_ref' => 'primary',
                    'current_revision' => (int) $structureHead['current_revision'],
                    'current_status' => (string) $structureHead['current_status'],
                    'published_revision' => $structureHead['published_revision'] === null ? null : (int) $structureHead['published_revision'],
                    'created_at' => (string) $structureHead['created_at'],
                    'updated_at' => (string) $structureHead['updated_at'],
                ],
                'relations' => ['revisions' => $structureRevisionIds],
            ];
            $latest = max($latest, (string) $structureHead['updated_at']);
        }

        $redirectCount = 0;
        foreach ($this->siteStructures->redirects() as $redirect) {
            $redirectCount++;
            $item = new ContentItemRef((string) $redirect['item_ref']);
            $entities[] = [
                'id' => $this->redirectEntityId((string) $redirect['type_key'], (string) $redirect['locale'], (string) $redirect['source_slug']),
                'type' => 'larena.content.redirect',
                'attributes' => [
                    'type_key' => (string) $redirect['type_key'],
                    'locale' => (string) $redirect['locale'],
                    'source_slug' => (string) $redirect['source_slug'],
                    'item_ref' => $item->uuid(),
                    'created_at' => (string) $redirect['created_at'],
                    'updated_at' => (string) $redirect['updated_at'],
                ],
                'relations' => ['content_item' => ['content-item:'.$item->uuid()]],
            ];
            $latest = max($latest, (string) $redirect['updated_at']);
        }

        $assets = [];
        $blobs = [];
        ksort($fileRefs, SORT_STRING);
        foreach (array_keys($fileRefs) as $logicalRef) {
            $snapshot = $this->files->snapshot($logicalRef);
            $descriptor = $snapshot->descriptor;
            $path = 'artifacts/assets/blobs/sha256/'.$descriptor->sha256;
            $stream = $snapshot->openStream();
            try {
                $bytes = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
            if (!is_string($bytes) || strlen($bytes) !== $descriptor->sizeBytes || hash('sha256', $bytes) !== $descriptor->sha256) {
                throw new ContentRejected('sitepack_asset_snapshot_integrity_failed');
            }
            $blobs[$path] = $bytes;
            $assets[] = [
                'id' => 'asset:'.$descriptor->logicalRef,
                'logicalRef' => $descriptor->logicalRef,
                'publicId' => $descriptor->publicId,
                'displayName' => $descriptor->displayName,
                'originalName' => $descriptor->originalName,
                'mime' => $descriptor->mimeType,
                'extension' => $descriptor->extension,
                'size' => $descriptor->sizeBytes,
                'sha256' => $descriptor->sha256,
                'visibility' => $descriptor->visibility,
                'altText' => $descriptor->altText,
                'path' => $path,
            ];
        }

        return [
            'entities' => $entities,
            'assets' => $assets,
            'blobs' => $blobs,
            'created_at' => $this->rfc3339($latest),
            'counts' => [
                'file_count' => count($assets),
                'item_count' => count($this->allItemRows()),
                'revision_count' => $revisionCount,
                'site_structure_count' => $structureHead === null ? 0 : 1,
                'site_structure_revision_count' => $structureRevisionCount,
                'seo_count' => $seoCount,
                'redirect_count' => $redirectCount,
                'type_count' => $typeCount,
            ],
        ];
    }

    /**
     * @return array{package_ref:string,digest:string,counts:array<string,int>,document:array<string,mixed>}
     */
    private function load(string $packageRef): array
    {
        try {
            $archive = $this->archive->read($packageRef);
            $decoded = $this->codec->parse($archive['entries']);
            $document = $this->document($decoded);
        } catch (ContentRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentRejected('sitepack_document_invalid', previous: $exception);
        }

        return [
            'package_ref' => $packageRef,
            'digest' => $archive['digest'],
            'counts' => [
                'file_count' => count($document['assets']),
                'item_count' => count($document['items']),
                'revision_count' => array_sum(array_map(static fn (array $item): int => count($item['revisions']), $document['items'])),
                'site_structure_count' => $document['structure'] === null ? 0 : 1,
                'site_structure_revision_count' => $document['structure'] === null ? 0 : count($document['structure']['revisions']),
                'seo_count' => $document['structure'] === null ? 0 : array_sum(array_map(
                    static fn (array $revision): int => count($revision['attributes']['seo']),
                    $document['structure']['revisions'],
                )),
                'redirect_count' => count($document['redirects']),
                'type_count' => count($document['types']),
            ],
            'document' => $document,
        ];
    }

    /**
     * @param array{entities:list<array<string,mixed>>,assets:list<array<string,mixed>>,blobs:array<string,string>,package_id:string} $decoded
     * @return array{types:array<string,array<string,mixed>>,items:array<string,array<string,mixed>>,assets:array<string,array<string,mixed>>,structure:?array<string,mixed>,redirects:array<string,array<string,mixed>>}
     */
    private function document(array $decoded): array
    {
        $types = [];
        $items = [];
        $structure = null;
        $redirects = [];
        foreach ($decoded['entities'] as $entity) {
            $type = $entity['type'] ?? null;
            $attributes = $entity['attributes'] ?? null;
            if (!is_string($type) || !is_array($attributes) || array_is_list($attributes)) {
                throw new ContentRejected('sitepack_entity_invalid');
            }
            if ($type === 'larena.content.type') {
                $typeKey = new ContentTypeKey($this->string($attributes, 'type_key'));
                $this->assertEntityId($entity, 'content-type:'.$typeKey->value);
                $types[$typeKey->value]['head'] = $attributes;
            } elseif ($type === 'larena.content.type_version') {
                $typeKey = new ContentTypeKey($this->string($attributes, 'type_key'));
                $version = $this->positiveInt($attributes, 'version');
                $this->assertEntityId($entity, 'content-type-version:'.$typeKey->value.':'.$version);
                $fields = $this->fields($attributes['fields'] ?? null);
                $projection = ContentProjectionContract::fromArray($this->objectValue($attributes, 'projection'), $fields);
                $safeMetadata = $this->scalarObject($attributes, 'safe_metadata');
                $types[$typeKey->value]['versions'][$version] = [
                    'attributes' => $attributes,
                    'fields' => $fields,
                    'projection' => $projection,
                    'safe_metadata' => $safeMetadata,
                ];
            } elseif ($type === 'larena.content.item') {
                $item = ContentItemRef::fromUuid($this->string($attributes, 'item_ref'));
                $this->assertEntityId($entity, 'content-item:'.$item->uuid());
                $items[$item->value]['head'] = $attributes;
            } elseif ($type === 'larena.content.revision') {
                $item = ContentItemRef::fromUuid($this->string($attributes, 'item_ref'));
                $revision = $this->positiveInt($attributes, 'revision');
                $this->assertEntityId($entity, 'content-revision:'.$item->uuid().':'.str_pad((string) $revision, 10, '0', STR_PAD_LEFT));
                $items[$item->value]['revisions'][$revision] = [
                    'attributes' => $attributes,
                    'relations' => $this->relations($entity),
                ];
            } elseif ($type === 'larena.content.site_structure') {
                $this->assertEntityId($entity, 'content-site-structure:primary');
                if ($structure !== null && isset($structure['head'])) {
                    throw new ContentRejected('sitepack_site_structure_duplicate');
                }
                $structure ??= ['revisions' => []];
                $structure['head'] = $attributes;
                $structure['relations'] = $this->relations($entity);
            } elseif ($type === 'larena.content.site_structure_revision') {
                $revision = $this->positiveInt($attributes, 'revision');
                $this->assertEntityId($entity, 'content-site-structure-revision:primary:'.str_pad((string) $revision, 10, '0', STR_PAD_LEFT));
                $structure ??= ['revisions' => []];
                if (isset($structure['revisions'][$revision])) {
                    throw new ContentRejected('sitepack_site_structure_revision_duplicate');
                }
                $structure['revisions'][$revision] = [
                    'attributes' => $attributes,
                    'relations' => $this->relations($entity),
                ];
            } elseif ($type === 'larena.content.redirect') {
                $typeKey = new ContentTypeKey($this->string($attributes, 'type_key'));
                $locale = new ContentLocale($this->string($attributes, 'locale'));
                $slug = new ContentSlug($this->string($attributes, 'source_slug'));
                $this->assertEntityId($entity, $this->redirectEntityId($typeKey->value, $locale->value, $slug->value));
                $key = $typeKey->value."\0".$locale->value."\0".$slug->value;
                if (isset($redirects[$key])) {
                    throw new ContentRejected('sitepack_redirect_duplicate');
                }
                $redirects[$key] = [
                    'attributes' => $attributes,
                    'relations' => $this->relations($entity),
                    'action' => 'unknown',
                ];
            } else {
                throw new ContentRejected('sitepack_entity_type_incompatible');
            }
        }
        if ($types === [] && $items !== []) {
            throw new ContentRejected('sitepack_type_catalog_missing');
        }
        ksort($types, SORT_STRING);
        ksort($items, SORT_STRING);
        foreach ($types as $typeKey => &$type) {
            if (!array_key_exists('head', $type) || !array_key_exists('versions', $type)) {
                throw new ContentRejected('sitepack_type_incomplete');
            }
            ksort($type['versions'], SORT_NUMERIC);
            $current = $this->positiveInt($type['head'], 'current_version');
            if (array_keys($type['versions']) !== range(1, $current)) {
                throw new ContentRejected('sitepack_type_version_chain_invalid');
            }
            foreach ($type['versions'] as $version => $definition) {
                if ($this->string($definition['attributes'], 'schema_hash') !== $this->schemas->schemaHash(
                    $this->schemas->definition(new ContentTypeKey($typeKey), $definition['fields']),
                )) {
                    throw new ContentRejected('sitepack_type_schema_hash_mismatch');
                }
                $this->positiveInt($definition['attributes'], 'storage_schema_version');
                if ($version !== (int) $definition['attributes']['storage_schema_version']) {
                    throw new ContentRejected('sitepack_storage_schema_version_incompatible');
                }
            }
        }
        unset($type);

        $assets = [];
        foreach ($decoded['assets'] as $asset) {
            $descriptor = $this->codec->assetDescriptor($asset);
            $path = $this->string($asset, 'path');
            $assets[$descriptor->logicalRef] = [
                'descriptor' => $descriptor,
                'bytes' => $decoded['blobs'][$path],
                'action' => 'unknown',
            ];
        }
        ksort($assets, SORT_STRING);

        foreach ($items as $itemRef => &$item) {
            if (!array_key_exists('head', $item) || !array_key_exists('revisions', $item)) {
                throw new ContentRejected('sitepack_item_incomplete');
            }
            ksort($item['revisions'], SORT_NUMERIC);
            $head = $item['head'];
            $current = $this->positiveInt($head, 'current_revision');
            if (array_keys($item['revisions']) !== range(1, $current)) {
                throw new ContentRejected('sitepack_revision_chain_invalid');
            }
            $typeKey = new ContentTypeKey($this->string($head, 'type_key'));
            $locale = new ContentLocale($this->string($head, 'locale'));
            new ContentSlug($this->string($head, 'current_slug'));
            ContentStatus::from($this->string($head, 'current_status'));
            ContentVisibility::from($this->string($head, 'current_visibility'));
            $publishedRevision = $this->nullablePositiveInt($head, 'published_revision');
            $publishedSlug = $this->nullableString($head, 'published_slug');
            $publishedAt = $this->nullableString($head, 'published_at');
            if (($publishedRevision === null) !== ($publishedSlug === null) || ($publishedRevision === null) !== ($publishedAt === null)) {
                throw new ContentRejected('sitepack_published_pointer_invalid');
            }
            $this->timestamp($this->string($head, 'created_at'));
            $this->timestamp($this->string($head, 'updated_at'));
            if ($publishedAt !== null) {
                $this->timestamp($publishedAt);
            }
            foreach ($item['revisions'] as $revision => &$revisionData) {
                $attributes = $revisionData['attributes'];
                if ($this->string($attributes, 'type_key') !== $typeKey->value || $this->string($attributes, 'locale') !== $locale->value) {
                    throw new ContentRejected('sitepack_revision_item_mismatch');
                }
                $typeVersion = $this->positiveInt($attributes, 'type_version');
                $definition = $types[$typeKey->value]['versions'][$typeVersion] ?? null;
                if (!is_array($definition)) {
                    throw new ContentRejected('sitepack_revision_type_version_missing');
                }
                new ContentSlug($this->string($attributes, 'slug'));
                ContentStatus::from($this->string($attributes, 'status'));
                ContentVisibility::from($this->string($attributes, 'visibility'));
                $this->timestamp($this->string($attributes, 'created_at'));
                $values = $this->scalarObject($attributes, 'values');
                $normalized = $this->schemas->normalizeValues($definition['fields'], $values);
                if ($this->canonical($normalized) !== $this->canonical($values)) {
                    throw new ContentRejected('sitepack_revision_values_not_canonical');
                }
                $attachments = $this->attachments($attributes['attachments'] ?? null, $assets);
                $revisionData['attributes']['values'] = $normalized;
                $revisionData['attributes']['attachments'] = $attachments;
                $this->assertRelations($revisionData['relations'], $definition['fields'], $normalized, $attachments, $items, $assets, $typeKey, $typeVersion);
            }
            unset($revisionData);
            $currentRevision = $item['revisions'][$current]['attributes'];
            if (
                $this->string($currentRevision, 'slug') !== $this->string($head, 'current_slug')
                || $this->string($currentRevision, 'status') !== $this->string($head, 'current_status')
                || $this->string($currentRevision, 'visibility') !== $this->string($head, 'current_visibility')
            ) {
                throw new ContentRejected('sitepack_item_head_mismatch');
            }
            if ($publishedRevision !== null) {
                $published = $item['revisions'][$publishedRevision]['attributes'] ?? null;
                if (!is_array($published) || $this->string($published, 'slug') !== $publishedSlug || $this->string($published, 'status') !== ContentStatus::Published->value) {
                    throw new ContentRejected('sitepack_published_pointer_invalid');
                }
            }
            $item['action'] = 'unknown';
        }
        unset($item);

        if ($structure !== null) {
            $structure = $this->normalizeSiteStructureDocument($structure, $items);
        }
        ksort($redirects, SORT_STRING);
        foreach ($redirects as &$redirect) {
            $this->normalizeRedirectDocument($redirect, $items);
        }
        unset($redirect);

        return ['types' => $types, 'items' => $items, 'assets' => $assets, 'structure' => $structure, 'redirects' => $redirects];
    }

    /**
     * @param array<string,mixed> $document
     * @return array{created_count:int,unchanged_count:int}
     */
    private function plan(array &$document, ActorContext $actor): array
    {
        $created = 0;
        $unchanged = 0;
        foreach ($document['types'] as $typeKey => &$type) {
            $existing = $this->repository->typeRow($typeKey);
            if ($existing === null) {
                $type['action'] = 'create';
                $created++;
                continue;
            }
            if ((int) $existing['current_version'] !== (int) $type['head']['current_version']) {
                throw new ContentRejected('sitepack_type_identity_conflict');
            }
            foreach ($type['versions'] as $version => $definition) {
                $row = $this->repository->typeVersionRow($typeKey, (int) $version);
                if (
                    $row === null
                    || (string) $row['schema_hash'] !== (string) $definition['attributes']['schema_hash']
                    || $this->canonical($this->decodeObject((string) $row['projection_contract'])) !== $this->canonical($definition['projection']->toArray())
                    || $this->canonical($this->decodeObject((string) $row['safe_metadata'])) !== $this->canonical($definition['safe_metadata'])
                ) {
                    throw new ContentRejected('sitepack_type_identity_conflict');
                }
            }
            $type['action'] = 'unchanged';
            $unchanged++;
        }
        unset($type);
        foreach ($document['assets'] as &$asset) {
            try {
                $existing = $this->files->snapshot($asset['descriptor']->logicalRef);
                if ($existing->descriptor->toArray() !== $asset['descriptor']->toArray()) {
                    throw new ContentRejected('sitepack_asset_identity_conflict');
                }
                $asset['action'] = 'unchanged';
                $unchanged++;
            } catch (PortableLogicalFileFailed $exception) {
                if ($exception->reasonCode !== 'filesystem_portable_file_not_found') {
                    throw new ContentRejected('sitepack_asset_identity_conflict', previous: $exception);
                }
                $asset['action'] = 'create';
                $created++;
            }
        }
        unset($asset);
        foreach ($document['items'] as $itemRef => &$item) {
            $existing = $this->repository->itemRow($itemRef);
            if ($existing === null) {
                $this->assertRoutesAvailable($itemRef, $item['head']);
                $item['action'] = 'create';
                $created++;
                continue;
            }
            if (!$this->existingItemMatches($itemRef, $item, $actor)) {
                throw new ContentRejected('sitepack_item_identity_conflict');
            }
            $item['action'] = 'unchanged';
            $unchanged++;
        }
        unset($item);
        if ($document['structure'] !== null) {
            $existing = $this->siteStructures->head();
            if ($existing === null) {
                $document['structure']['action'] = 'create';
                $created++;
            } elseif ($this->existingSiteStructureMatches($document['structure'])) {
                $document['structure']['action'] = 'unchanged';
                $unchanged++;
            } else {
                throw new ContentRejected('sitepack_site_structure_identity_conflict');
            }
        }
        foreach ($document['redirects'] as &$redirect) {
            $attributes = $redirect['attributes'];
            $itemRef = ContentItemRef::fromUuid($this->string($attributes, 'item_ref'))->value;
            $existing = $this->siteStructures->redirect(
                $this->string($attributes, 'type_key'),
                $this->string($attributes, 'locale'),
                $this->string($attributes, 'source_slug'),
            );
            if ($existing === null) {
                if ($this->repository->routeRow(
                    $this->string($attributes, 'type_key'),
                    $this->string($attributes, 'locale'),
                    $this->string($attributes, 'source_slug'),
                ) !== null) {
                    throw new ContentRejected('sitepack_redirect_route_conflict');
                }
                $redirect['action'] = 'create';
                $created++;
            } elseif (
                (string) $existing['item_ref'] === $itemRef
                && (string) $existing['created_at'] === $this->string($attributes, 'created_at')
                && (string) $existing['updated_at'] === $this->string($attributes, 'updated_at')
            ) {
                $redirect['action'] = 'unchanged';
                $unchanged++;
            } else {
                throw new ContentRejected('sitepack_redirect_identity_conflict');
            }
        }
        unset($redirect);

        return ['created_count' => $created, 'unchanged_count' => $unchanged];
    }

    /** @param array<string,array<string,mixed>> $types */
    private function importTypes(array $types, ActorContext $actor): void
    {
        foreach ($types as $typeKey => $type) {
            if ($type['action'] !== 'create') {
                continue;
            }
            $key = new ContentTypeKey($typeKey);
            foreach ($type['versions'] as $version => $definition) {
                if ((int) $version === 1) {
                    $this->types->create($key, $definition['fields'], $definition['projection'], $definition['safe_metadata'], $actor);
                } else {
                    $this->types->createVersion($key, (int) $version - 1, $definition['fields'], $definition['projection'], $definition['safe_metadata'], $actor);
                }
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $items
     * @param array<string,array<string,mixed>> $types
     */
    private function importItems(array $items, array $types, ActorContext $actor): void
    {
        foreach ($items as $itemRefValue => $item) {
            if ($item['action'] !== 'create') {
                continue;
            }
            $itemRef = new ContentItemRef($itemRefValue);
            $previous = null;
            foreach ($item['revisions'] as $revisionNumber => $revision) {
                $attributes = $revision['attributes'];
                $typeKey = $this->string($attributes, 'type_key');
                $typeVersion = $this->positiveInt($attributes, 'type_version');
                $typeRow = $this->repository->typeVersionRow($typeKey, $typeVersion, true);
                if ($typeRow === null) {
                    throw new ContentRejected('sitepack_revision_type_version_missing');
                }
                $schemaRef = new StorageSchemaVersionRef(
                    (string) $typeRow['storage_schema_ref'],
                    (int) $typeRow['storage_schema_version'],
                );
                $write = $previous === null
                    ? $this->storage->create($itemRef, $schemaRef, $attributes['values'], $actor)
                    : $this->storage->update($itemRef, $previous, $schemaRef, $attributes['values'], $actor);
                $previous = $write->ref();
                if ($previous->revision !== (int) $revisionNumber) {
                    throw new ContentIntegrationFailed('storage', 'sitepack_storage_revision_mismatch');
                }
                $this->repository->appendRevision([
                    'item_ref' => $itemRefValue,
                    'revision' => (int) $revisionNumber,
                    'type_key' => $typeKey,
                    'locale' => $this->string($attributes, 'locale'),
                    'type_version' => $typeVersion,
                    'storage_schema_ref' => $previous->schemaId,
                    'storage_schema_version' => $schemaRef->version,
                    'storage_record_ref' => $previous->recordId,
                    'storage_record_version' => $previous->revision,
                    'slug' => $this->string($attributes, 'slug'),
                    'status' => $this->string($attributes, 'status'),
                    'visibility' => $this->string($attributes, 'visibility'),
                    'attachment_count' => count($attributes['attachments']),
                    'created_by' => $actor->actorRef,
                    'correlation_id' => $actor->correlationId,
                    'created_at' => $this->timestamp($this->string($attributes, 'created_at')),
                ], $attributes['attachments']);
            }
            $head = $item['head'];
            $this->repository->insertImportedItemHead([
                'item_ref' => $itemRefValue,
                'type_key' => $this->string($head, 'type_key'),
                'locale' => $this->string($head, 'locale'),
                'current_revision' => $this->positiveInt($head, 'current_revision'),
                'current_slug' => $this->string($head, 'current_slug'),
                'current_status' => $this->string($head, 'current_status'),
                'current_visibility' => $this->string($head, 'current_visibility'),
                'published_revision' => $this->nullablePositiveInt($head, 'published_revision'),
                'published_slug' => $this->nullableString($head, 'published_slug'),
                'published_at' => $this->nullableTimestamp($head, 'published_at'),
                'created_at' => $this->timestamp($this->string($head, 'created_at')),
                'updated_at' => $this->timestamp($this->string($head, 'updated_at')),
            ]);
            $this->importRoutes($itemRefValue, $head);
        }
    }

    /** @param array<string,mixed>|null $structure */
    private function importSiteStructure(?array $structure): void
    {
        if ($structure === null || $structure['action'] !== 'create') {
            return;
        }
        $head = $structure['head'];
        $rows = [];
        foreach ($structure['revisions'] as $revision) {
            $attributes = $revision['attributes'];
            $rows[] = [
                'structure_ref' => 'primary',
                'revision' => $this->positiveInt($attributes, 'revision'),
                'status' => $this->string($attributes, 'status'),
                'nodes_json' => $this->canonical($attributes['nodes']),
                'seo_json' => $this->canonical($attributes['seo']),
                'created_by' => $this->string($attributes, 'created_by'),
                'correlation_id' => $this->string($attributes, 'correlation_id'),
                'created_at' => $this->timestamp($this->string($attributes, 'created_at')),
            ];
        }
        $this->siteStructures->insertImportedStructure([
            'structure_ref' => 'primary',
            'current_revision' => $this->positiveInt($head, 'current_revision'),
            'current_status' => $this->string($head, 'current_status'),
            'published_revision' => $this->nullablePositiveInt($head, 'published_revision'),
            'created_at' => $this->timestamp($this->string($head, 'created_at')),
            'updated_at' => $this->timestamp($this->string($head, 'updated_at')),
        ], $rows);
    }

    /** @param array<string,array<string,mixed>> $redirects */
    private function importRedirects(array $redirects): void
    {
        foreach ($redirects as $redirect) {
            if ($redirect['action'] !== 'create') {
                continue;
            }
            $attributes = $redirect['attributes'];
            $this->siteStructures->insertImportedRedirect([
                'type_key' => $this->string($attributes, 'type_key'),
                'locale' => $this->string($attributes, 'locale'),
                'source_slug' => $this->string($attributes, 'source_slug'),
                'item_ref' => ContentItemRef::fromUuid($this->string($attributes, 'item_ref'))->value,
                'created_at' => $this->timestamp($this->string($attributes, 'created_at')),
                'updated_at' => $this->timestamp($this->string($attributes, 'updated_at')),
            ]);
        }
    }

    /** @param array<string,mixed> $head */
    private function importRoutes(string $itemRef, array $head): void
    {
        $currentSlug = $this->string($head, 'current_slug');
        $publishedSlug = $this->nullableString($head, 'published_slug');
        $slugs = array_values(array_unique(array_filter([$currentSlug, $publishedSlug], 'is_string')));
        sort($slugs, SORT_STRING);
        foreach ($slugs as $slug) {
            $this->repository->setRoute([
                'type_key' => $this->string($head, 'type_key'),
                'locale' => $this->string($head, 'locale'),
                'slug' => $slug,
                'item_ref' => $itemRef,
                'current_revision' => $slug === $currentSlug ? $this->positiveInt($head, 'current_revision') : null,
                'published_revision' => $slug === $publishedSlug ? $this->nullablePositiveInt($head, 'published_revision') : null,
                'created_at' => $this->timestamp($this->string($head, 'created_at')),
                'updated_at' => $this->timestamp($this->string($head, 'updated_at')),
            ]);
        }
    }

    /** @param array<string,mixed> $item */
    private function existingItemMatches(string $itemRef, array $item, ActorContext $actor): bool
    {
        $row = $this->repository->itemRow($itemRef);
        if ($row === null) {
            return false;
        }
        $head = $item['head'];
        foreach ([
            'type_key', 'locale', 'current_revision', 'current_slug', 'current_status',
            'current_visibility', 'published_revision', 'published_slug', 'published_at',
            'created_at', 'updated_at',
        ] as $key) {
            if ($row[$key] !== $head[$key]) {
                return false;
            }
        }
        foreach ($item['revisions'] as $number => $revision) {
            $stored = $this->repository->revisionRow($itemRef, (int) $number);
            if ($stored === null) {
                return false;
            }
            $attributes = $revision['attributes'];
            foreach (['type_key', 'locale', 'type_version', 'slug', 'status', 'visibility', 'created_at'] as $key) {
                if ($stored[$key] !== $attributes[$key]) {
                    return false;
                }
            }
            $attachments = array_map(
                static fn (array $entry): array => [
                    'logical_file_ref' => $entry['logical_file_ref'],
                    'role' => $entry['role'],
                    'position' => $entry['position'],
                ],
                $this->repository->attachmentRows($itemRef, (int) $number),
            );
            if ($attachments !== $attributes['attachments']) {
                return false;
            }
            $storage = $this->storage->readAdminVersion(new StorageRecordVersionRef(
                (string) $stored['storage_schema_ref'],
                (string) $stored['storage_record_ref'],
                (int) $stored['storage_record_version'],
            ), $actor);
            if ($this->canonical($storage->values) !== $this->canonical($attributes['values'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $head */
    private function assertRoutesAvailable(string $itemRef, array $head): void
    {
        foreach (array_unique(array_filter([
            $this->string($head, 'current_slug'),
            $this->nullableString($head, 'published_slug'),
        ], 'is_string')) as $slug) {
            $existing = $this->repository->routeRow($this->string($head, 'type_key'), $this->string($head, 'locale'), $slug);
            if ($existing !== null && $existing['item_ref'] !== $itemRef) {
                throw new ContentRejected('sitepack_route_conflict');
            }
        }
    }

    /**
     * @param array<string,list<string>> $relations
     * @param list<ContentFieldDefinition> $fields
     * @param array<string,scalar|null> $values
     * @param list<array{logical_file_ref:string,role:string,position:int}> $attachments
     * @param array<string,mixed> $items
     * @param array<string,mixed> $assets
     */
    private function assertRelations(array $relations, array $fields, array $values, array $attachments, array $items, array $assets, ContentTypeKey $typeKey, int $typeVersion): void
    {
        $expected = ['type_version' => ['content-type-version:'.$typeKey->value.':'.$typeVersion]];
        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            if ($field->propertyType === 'relation') {
                $target = ContentItemRef::fromUuid($value)->value;
                if (!isset($items[$target]) && $this->repository->itemRow($target) === null) {
                    throw new ContentRejected('sitepack_relation_target_unavailable');
                }
                $expected['relation.'.$field->key] = ['content-item:'.$value];
            } elseif ($field->propertyType === 'file') {
                if (!isset($assets[$value])) {
                    throw new ContentRejected('sitepack_file_reference_unavailable');
                }
                $expected['file.'.$field->key] = ['asset:'.$value];
            }
        }
        if ($attachments !== []) {
            $expected['attachments'] = array_map(static fn (array $a): string => 'asset:'.$a['logical_file_ref'], $attachments);
        }
        ksort($expected, SORT_STRING);
        if ($relations !== $expected) {
            throw new ContentRejected('sitepack_relation_graph_mismatch');
        }
    }

    /**
     * @param array<string, mixed> $structure
     * @param array<string, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function normalizeSiteStructureDocument(array $structure, array $items): array
    {
        $head = $structure['head'] ?? null;
        $revisions = $structure['revisions'] ?? null;
        if (!is_array($head) || array_is_list($head) || !is_array($revisions) || $revisions === []) {
            throw new ContentRejected('sitepack_site_structure_incomplete');
        }
        if ($this->string($head, 'structure_ref') !== 'primary') {
            throw new ContentRejected('sitepack_site_structure_identity_mismatch');
        }
        ksort($revisions, SORT_NUMERIC);
        $current = $this->positiveInt($head, 'current_revision');
        if (array_keys($revisions) !== range(1, $current)) {
            throw new ContentRejected('sitepack_site_structure_revision_chain_invalid');
        }
        $currentStatus = $this->siteStructureStatus($this->string($head, 'current_status'));
        $published = $this->nullablePositiveInt($head, 'published_revision');
        $this->timestamp($this->string($head, 'created_at'));
        $this->timestamp($this->string($head, 'updated_at'));
        $expectedRevisionRelations = array_map(
            static fn (int $revision): string => 'content-site-structure-revision:primary:'.str_pad((string) $revision, 10, '0', STR_PAD_LEFT),
            array_keys($revisions),
        );
        if (($structure['relations'] ?? null) !== ['revisions' => $expectedRevisionRelations]) {
            throw new ContentRejected('sitepack_site_structure_relation_graph_mismatch');
        }
        foreach ($revisions as $number => &$revision) {
            $attributes = $revision['attributes'] ?? null;
            if (
                !is_array($attributes)
                || array_is_list($attributes)
                || $this->string($attributes, 'structure_ref') !== 'primary'
                || $this->positiveInt($attributes, 'revision') !== (int) $number
            ) {
                throw new ContentRejected('sitepack_site_structure_revision_invalid');
            }
            $status = $this->siteStructureStatus($this->string($attributes, 'status'));
            $nodes = $this->siteStructureNodesValue($attributes['nodes'] ?? null);
            $seo = $this->siteStructureSeoValue($attributes['seo'] ?? null);
            $createdBy = $this->string($attributes, 'created_by');
            $correlationId = $this->string($attributes, 'correlation_id');
            if ($createdBy === '' || strlen($createdBy) > 191 || $correlationId === '' || strlen($correlationId) > 191) {
                throw new ContentRejected('sitepack_site_structure_revision_invalid');
            }
            $this->timestamp($this->string($attributes, 'created_at'));
            $targets = [];
            foreach ($nodes as $node) {
                if ($node->contentItemRef !== null) {
                    if (!isset($items[$node->contentItemRef->value])) {
                        throw new ContentRejected('sitepack_site_structure_target_unavailable');
                    }
                    $targets['content-item:'.$node->contentItemRef->uuid()] = true;
                }
            }
            foreach ($seo as $metadata) {
                if (!isset($items[$metadata->itemRef->value])) {
                    throw new ContentRejected('sitepack_site_structure_target_unavailable');
                }
                $targets['content-item:'.$metadata->itemRef->uuid()] = true;
            }
            $expectedTargets = array_keys($targets);
            sort($expectedTargets, SORT_STRING);
            if (($revision['relations'] ?? null) !== ['content_items' => $expectedTargets]) {
                throw new ContentRejected('sitepack_site_structure_relation_graph_mismatch');
            }
            $attributes['nodes'] = array_map(static fn (SiteStructureNode $node): array => $node->toArray(), $nodes);
            $attributes['seo'] = array_map(static fn (SiteSeoMetadata $metadata): array => $metadata->toArray(), $seo);
            $attributes['status'] = $status;
            $revision['attributes'] = $attributes;
        }
        unset($revision);
        if ($revisions[$current]['attributes']['status'] !== $currentStatus) {
            throw new ContentRejected('sitepack_site_structure_head_mismatch');
        }
        if ($published !== null && (!isset($revisions[$published]) || $revisions[$published]['attributes']['status'] !== 'published')) {
            throw new ContentRejected('sitepack_site_structure_published_pointer_invalid');
        }

        return [
            'head' => $head,
            'relations' => $structure['relations'],
            'revisions' => $revisions,
            'action' => 'unknown',
        ];
    }

    /**
     * @param array<string, mixed> $redirect
     * @param array<string, array<string, mixed>> $items
     */
    private function normalizeRedirectDocument(array &$redirect, array $items): void
    {
        $attributes = $redirect['attributes'];
        $type = new ContentTypeKey($this->string($attributes, 'type_key'));
        $locale = new ContentLocale($this->string($attributes, 'locale'));
        $slug = new ContentSlug($this->string($attributes, 'source_slug'));
        $item = ContentItemRef::fromUuid($this->string($attributes, 'item_ref'));
        if (!isset($items[$item->value])) {
            throw new ContentRejected('sitepack_redirect_target_unavailable');
        }
        $head = $items[$item->value]['head'];
        if ($this->string($head, 'type_key') !== $type->value || $this->string($head, 'locale') !== $locale->value) {
            throw new ContentRejected('sitepack_redirect_target_mismatch');
        }
        foreach ($items as $candidate) {
            $candidateHead = $candidate['head'];
            if (
                $this->string($candidateHead, 'type_key') === $type->value
                && $this->string($candidateHead, 'locale') === $locale->value
                && in_array($slug->value, [
                    $this->string($candidateHead, 'current_slug'),
                    $this->nullableString($candidateHead, 'published_slug'),
                ], true)
            ) {
                throw new ContentRejected('sitepack_redirect_route_conflict');
            }
        }
        $this->timestamp($this->string($attributes, 'created_at'));
        $this->timestamp($this->string($attributes, 'updated_at'));
        if (($redirect['relations'] ?? null) !== ['content_item' => ['content-item:'.$item->uuid()]]) {
            throw new ContentRejected('sitepack_redirect_relation_graph_mismatch');
        }
        $redirect['action'] = 'unknown';
    }

    /** @param array<string,mixed> $structure */
    private function existingSiteStructureMatches(array $structure): bool
    {
        $existing = $this->siteStructures->head();
        if ($existing === null) {
            return false;
        }
        $head = $structure['head'];
        foreach (['structure_ref', 'current_revision', 'current_status', 'published_revision', 'created_at', 'updated_at'] as $key) {
            if ($existing[$key] !== $head[$key]) {
                return false;
            }
        }
        $rows = $this->siteStructures->revisions();
        if (count($rows) !== count($structure['revisions'])) {
            return false;
        }
        foreach ($rows as $row) {
            $revision = $structure['revisions'][(int) $row['revision']]['attributes'] ?? null;
            if (!is_array($revision)) {
                return false;
            }
            foreach (['structure_ref', 'revision', 'status', 'created_by', 'correlation_id', 'created_at'] as $key) {
                if ($row[$key] !== $revision[$key]) {
                    return false;
                }
            }
            if (
                $this->canonical(array_map(static fn (SiteStructureNode $node): array => $node->toArray(), $this->siteStructureNodes((string) $row['nodes_json']))) !== $this->canonical($revision['nodes'])
                || $this->canonical(array_map(static fn (SiteSeoMetadata $seo): array => $seo->toArray(), $this->siteStructureSeo((string) $row['seo_json']))) !== $this->canonical($revision['seo'])
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return list<SiteStructureNode> */
    private function siteStructureNodes(string $json): array
    {
        return $this->siteStructureNodesValue($this->decodeList($json));
    }

    /** @return list<SiteSeoMetadata> */
    private function siteStructureSeo(string $json): array
    {
        return $this->siteStructureSeoValue($this->decodeList($json));
    }

    /** @return list<SiteStructureNode> */
    private function siteStructureNodesValue(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 200) {
            throw new ContentRejected('sitepack_site_structure_nodes_invalid');
        }
        try {
            return array_map(static function (mixed $node): SiteStructureNode {
                if (!is_array($node) || array_is_list($node)) {
                    throw new \InvalidArgumentException('site_structure_node_invalid');
                }

                return SiteStructureNode::fromArray($node);
            }, $value);
        } catch (\InvalidArgumentException $exception) {
            throw new ContentRejected('sitepack_site_structure_nodes_invalid', previous: $exception);
        }
    }

    /** @return list<SiteSeoMetadata> */
    private function siteStructureSeoValue(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 500) {
            throw new ContentRejected('sitepack_site_structure_seo_invalid');
        }
        try {
            return array_map(static function (mixed $seo): SiteSeoMetadata {
                if (!is_array($seo) || array_is_list($seo)) {
                    throw new \InvalidArgumentException('site_structure_seo_invalid');
                }

                return SiteSeoMetadata::fromArray($seo);
            }, $value);
        } catch (\InvalidArgumentException $exception) {
            throw new ContentRejected('sitepack_site_structure_seo_invalid', previous: $exception);
        }
    }

    /** @return list<mixed> */
    private function decodeList(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ContentRejected('sitepack_site_structure_json_invalid', previous: $exception);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ContentRejected('sitepack_site_structure_json_invalid');
        }

        return $value;
    }

    private function siteStructureStatus(string $status): string
    {
        if (!in_array($status, ['draft', 'review', 'published'], true)) {
            throw new ContentRejected('sitepack_site_structure_status_invalid');
        }

        return $status;
    }

    private function redirectEntityId(string $typeKey, string $locale, string $sourceSlug): string
    {
        return 'content-redirect:'.hash('sha256', $typeKey."\0".$locale."\0".$sourceSlug);
    }

    /**
     * @param array<string,array<string,mixed>> $assets
     * @return list<array{logical_file_ref:string,role:string,position:int}>
     */
    private function attachments(mixed $value, array $assets): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new ContentRejected('sitepack_attachments_invalid');
        }
        $result = [];
        foreach ($value as $position => $attachment) {
            if (!is_array($attachment) || !is_string($attachment['logical_file_ref'] ?? null) || !is_string($attachment['role'] ?? null) || ($attachment['position'] ?? null) !== $position || !isset($assets[$attachment['logical_file_ref']])) {
                throw new ContentRejected('sitepack_attachments_invalid');
            }
            $result[] = [
                'logical_file_ref' => $attachment['logical_file_ref'],
                'role' => $attachment['role'],
                'position' => $position,
            ];
        }

        return $result;
    }

    /** @return list<ContentFieldDefinition> */
    private function fields(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 100) {
            throw new ContentRejected('sitepack_type_fields_invalid');
        }
        $fields = [];
        foreach ($value as $field) {
            if (!is_array($field) || array_is_list($field)) {
                throw new ContentRejected('sitepack_type_fields_invalid');
            }
            $fields[] = new ContentFieldDefinition(
                $this->string($field, 'key'),
                $this->string($field, 'property_type'),
                ContentFieldVisibility::from($this->string($field, 'visibility')),
                $this->boolean($field, 'required'),
                isset($field['constraints']) ? $this->intStringObject($field, 'constraints') : [],
            );
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    private function fieldToArray(ContentFieldDefinition $field): array
    {
        $result = [
            'key' => $field->key,
            'property_type' => $field->propertyType,
            'visibility' => $field->visibility->value,
            'required' => $field->required,
        ];
        if ($field->constraints !== []) {
            $result['constraints'] = $field->constraints;
        }

        return $result;
    }

    /** @return list<array<string,bool|int|string|null>> */
    private function allTypeRows(): array
    {
        $rows = [];
        $after = null;
        do {
            $page = $this->repository->typeRows($after, self::PAGE_SIZE);
            array_push($rows, ...$page);
            $last = end($page);
            $after = count($page) === self::PAGE_SIZE && is_array($last) ? (string) $last['type_key'] : null;
        } while ($after !== null);

        return $rows;
    }

    /** @return list<array<string,bool|int|string|null>> */
    private function allTypeVersionRows(string $typeKey): array
    {
        $rows = [];
        $after = null;
        do {
            $page = $this->repository->typeVersionRows($typeKey, $after, self::PAGE_SIZE);
            array_push($rows, ...$page);
            $last = end($page);
            $after = count($page) === self::PAGE_SIZE && is_array($last) ? (int) $last['version'] : null;
        } while ($after !== null);

        return $rows;
    }

    /** @return list<array<string,bool|int|string|null>> */
    private function allItemRows(): array
    {
        $rows = [];
        $after = null;
        do {
            $page = $this->repository->itemRows([], $after, self::PAGE_SIZE);
            array_push($rows, ...$page);
            $last = end($page);
            $after = count($page) === self::PAGE_SIZE && is_array($last) ? (string) $last['item_ref'] : null;
        } while ($after !== null);

        return $rows;
    }

    /** @return list<array<string,bool|int|string|null>> */
    private function allRevisionRows(string $itemRef): array
    {
        $rows = [];
        $after = null;
        do {
            $page = $this->repository->revisionRows($itemRef, $after, self::PAGE_SIZE);
            array_push($rows, ...$page);
            $last = end($page);
            $after = count($page) === self::PAGE_SIZE && is_array($last) ? (int) $last['revision'] : null;
        } while ($after !== null);

        return $rows;
    }

    private function preflight(ActorContext $actor, string $operation): ConnectionInterface
    {
        $connection = $this->participants->assertSharedConnection();
        $this->input->assertActor($actor);
        $this->authorizer->assertAllowed($actor, $operation, ContentAuthorizer::requiredStorageOperations($operation));

        return $connection;
    }

    private function auditReport(ConnectionInterface $connection, string $event, string $operation, ActorContext $actor, CmsSitePackReport $report): void
    {
        $connection->transaction(function () use ($event, $operation, $actor, $report): void {
            $this->audit->emit($event, $actor, $report->packageRef, $this->auditPayload($operation, $report));
        }, 1);
    }

    private function auditPayload(string $operation, CmsSitePackReport $report): ContentAuditPayload
    {
        return ContentAuditPayload::from([
            'operation' => $operation,
            'package_ref' => $report->packageRef,
            'package_digest' => $report->digest,
            'type_count' => $report->counts['type_count'] ?? 0,
            'item_count' => $report->counts['item_count'] ?? 0,
            'revision_count' => $report->counts['revision_count'] ?? 0,
            'site_structure_count' => $report->counts['site_structure_count'] ?? 0,
            'site_structure_revision_count' => $report->counts['site_structure_revision_count'] ?? 0,
            'seo_count' => $report->counts['seo_count'] ?? 0,
            'redirect_count' => $report->counts['redirect_count'] ?? 0,
            'file_count' => $report->counts['file_count'] ?? 0,
            'created_count' => $report->counts['created_count'] ?? 0,
            'unchanged_count' => $report->counts['unchanged_count'] ?? 0,
        ]);
    }

    private function auditFailure(ConnectionInterface $connection, ActorContext $actor, string $operation, ?string $packageRef, ContentRejected $rejection): void
    {
        $safeRef = is_string($packageRef) && preg_match('/\Acms-[a-f0-9]{64}\.sitepack\z/D', $packageRef) === 1
            ? $packageRef
            : 'content:sitepack';
        $connection->transaction(function () use ($actor, $operation, $safeRef, $rejection): void {
            $this->audit->emit('content.sitepack.failed', $actor, $safeRef, ContentAuditPayload::from([
                'operation' => $operation,
                'package_ref' => $safeRef,
                'reason_code' => $rejection->reasonCode(),
            ]));
        }, 1);
    }

    /**
     * @param array<string,mixed> $document
     * @return array{created_count:int,unchanged_count:int}
     */
    private function actionCounts(array $document): array
    {
        $created = 0;
        $unchanged = 0;
        foreach (['types', 'items', 'assets'] as $group) {
            foreach ($document[$group] as $entry) {
                $entry['action'] === 'create' ? $created++ : $unchanged++;
            }
        }
        if ($document['structure'] !== null) {
            $document['structure']['action'] === 'create' ? $created++ : $unchanged++;
        }
        foreach ($document['redirects'] as $redirect) {
            $redirect['action'] === 'create' ? $created++ : $unchanged++;
        }

        return ['created_count' => $created, 'unchanged_count' => $unchanged];
    }

    /** @param array<string,mixed> $entity */
    private function assertEntityId(array $entity, string $expected): void
    {
        if (($entity['id'] ?? null) !== $expected) {
            throw new ContentRejected('sitepack_entity_identity_mismatch');
        }
    }

    /**
     * @param array<string,mixed> $entity
     * @return array<string,list<string>>
     */
    private function relations(array $entity): array
    {
        $relations = $entity['relations'] ?? [];
        if (!is_array($relations) || ($relations !== [] && array_is_list($relations))) {
            throw new ContentRejected('sitepack_relation_graph_invalid');
        }
        $result = [];
        foreach ($relations as $key => $links) {
            if (!is_string($key) || !is_array($links) || !array_is_list($links)) {
                throw new ContentRejected('sitepack_relation_graph_invalid');
            }
            foreach ($links as $link) {
                if (!is_string($link)) {
                    throw new ContentRejected('sitepack_relation_graph_invalid');
                }
            }
            $result[$key] = $links;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ContentRejected('sitepack_persisted_json_invalid');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new ContentRejected('sitepack_field_invalid');
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        if (!array_key_exists($key, $row) || !is_bool($row[$key])) {
            throw new ContentRejected('sitepack_field_invalid');
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_int($row[$key]) || $row[$key] < 1) {
            throw new ContentRejected('sitepack_field_invalid');
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function nullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new ContentRejected('sitepack_field_invalid');
        }
        if ($row[$key] === null) {
            return null;
        }

        return $this->positiveInt($row, $key);
    }

    /** @param array<string,mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row)) {
            throw new ContentRejected('sitepack_field_invalid');
        }

        return $row[$key] === null ? null : $this->string($row, $key);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function objectValue(array $row, string $key): array
    {
        if (!isset($row[$key]) || !is_array($row[$key]) || array_is_list($row[$key])) {
            throw new ContentRejected('sitepack_field_invalid');
        }

        return $row[$key];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,scalar|null>
     */
    private function scalarObject(array $row, string $key): array
    {
        $value = $this->objectValue($row, $key);
        foreach ($value as $entry) {
            if (!is_scalar($entry) && $entry !== null) {
                throw new ContentRejected('sitepack_field_invalid');
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,int|string>
     */
    private function intStringObject(array $row, string $key): array
    {
        $value = $this->objectValue($row, $key);
        foreach ($value as $entry) {
            if (!is_int($entry) && !is_string($entry)) {
                throw new ContentRejected('sitepack_field_invalid');
            }
        }

        return $value;
    }

    private function timestamp(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        } catch (Throwable $exception) {
            throw new ContentRejected('sitepack_timestamp_invalid', previous: $exception);
        }
    }

    /** @param array<string,mixed> $row */
    private function nullableTimestamp(array $row, string $key): ?string
    {
        $value = $this->nullableString($row, $key);

        return $value === null ? null : $this->timestamp($value);
    }

    private function rfc3339(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function canonical(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map($this->canonicalize(...), $value);
    }
}

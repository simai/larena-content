<?php

declare(strict_types=1);

namespace Larena\Content\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Larena\Content\Access\ContentAuthorizer;
use Larena\Content\Audit\ContentAuditEmitter;
use Larena\Content\Audit\ContentAuditPayload;
use Larena\Content\Contracts\ContentClock;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentType;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypePage;
use Larena\Content\ValueObjects\ContentTypeQuery;
use Larena\Content\ValueObjects\ContentTypeSchemaCompatibilityReport;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Content\ValueObjects\ContentTypeVersionPage;
use Larena\Content\ValueObjects\ContentTypeVersionQuery;
use Larena\Storage\Contracts\StorageSchemaCompatibilityReport;
use Larena\Storage\Contracts\StorageSchemaMigrationPlan;
use Larena\Storage\Contracts\StorageSchemaMigrationRecordHead;
use Larena\Storage\Contracts\StorageSchemaMigrationResult;
use Larena\Storage\Contracts\StorageSchemaMigrationRecordResult;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Throwable;

final readonly class DatabaseContentTypeService implements ContentTypeService
{
    public function __construct(
        private DatabaseContentRepository $repository,
        private ContentAuthorizer $authorizer,
        private ContentParticipantGuard $participants,
        private ContentStorageGateway $storage,
        private ContentSchemaMapper $schemas,
        private ContentInputGuard $input,
        private ContentCanonicalJson $canonicalJson,
        private ContentAuditEmitter $audit,
        private ContentClock $clock,
    ) {
    }

    public function create(
        ContentTypeKey $typeKey,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
        ActorContext $actor,
    ): ContentType {
        $connection = $this->preflightProtected(
            $actor,
            'content.type.create',
            ['storage.schema.create'],
        );

        try {
            $this->input->assertFields($fields);
            $this->input->assertProjectionContract($projectionContract);
            try {
                $projectionContract->assertExactFieldDefinitions($fields);
            } catch (\InvalidArgumentException $exception) {
                throw new ContentRejected(
                    'projection_contract_invalid',
                    'The projection contract does not match the submitted Content fields.',
                    $exception,
                );
            }
            $this->input->assertSafeTypeMetadata($safeMetadata);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $typeKey,
                $fields,
                $projectionContract,
                $safeMetadata,
                $actor,
            ): ContentType {
                if ($this->repository->typeRow($typeKey->value, true) !== null) {
                    throw new ContentRejected(
                        'type_already_exists',
                        'The Content type already exists.',
                    );
                }

                $schema = $this->storage->registerTypeSchema($typeKey, $fields, $actor);
                $now = $this->timestamp($this->clock->now());

                $this->repository->insertTypeHead([
                    'type_key' => $typeKey->value,
                    'current_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->repository->insertTypeVersion([
                    'type_key' => $typeKey->value,
                    'version' => 1,
                    'storage_schema_ref' => $schema->ref->schemaId,
                    'storage_schema_version' => $schema->ref->version,
                    'schema_hash' => $schema->definitionHash,
                    'projection_contract' => $this->canonicalJson->encode($projectionContract->toArray()),
                    'safe_metadata' => $this->canonicalJson->encode($safeMetadata),
                    'created_by' => $actor->actorRef,
                    'correlation_id' => $actor->correlationId,
                    'created_at' => $now,
                ]);
                $this->audit->emit(
                    'content.type.created',
                    $actor,
                    $this->typeSubject($typeKey),
                    ContentAuditPayload::from([
                        'operation' => 'content.type.create',
                        'type_key' => $typeKey->value,
                        'new_revision' => 1,
                        'field_count' => count($fields),
                    ]),
                );

                return new ContentType($typeKey, 1);
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.create',
                $exception,
                $this->typeSubject($typeKey),
                ['type_key' => $typeKey->value],
            );

            throw $exception;
        }
    }

    public function read(ContentTypeKey $typeKey, ActorContext $actor): ContentType
    {
        $connection = $this->preflightProtected($actor, 'content.type.read');

        try {
            $this->repository->assertCompleteCompatible();
            $row = $this->repository->typeRow($typeKey->value);

            if ($row === null) {
                throw new ContentRejected('type_not_found', 'The Content type does not exist.');
            }

            return $this->hydrateType($row);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.read',
                $exception,
                $this->typeSubject($typeKey),
                ['type_key' => $typeKey->value],
            );

            throw $exception;
        }
    }

    public function version(
        ContentTypeKey $typeKey,
        int $version,
        ActorContext $actor,
    ): ContentTypeVersion {
        $connection = $this->preflightProtected($actor, 'content.type.read');

        try {
            $this->input->assertMutableRevision($version);
            $this->repository->assertCompleteCompatible();
            $row = $this->repository->typeVersionRow($typeKey->value, $version);

            if ($row === null) {
                throw new ContentRejected(
                    'type_version_not_found',
                    'The exact Content type version does not exist.',
                );
            }

            return $this->hydrateTypeVersion($row);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.read',
                $exception,
                $this->typeSubject($typeKey),
                [
                    'type_key' => $typeKey->value,
                    'current_revision' => max(0, $version),
                ],
            );

            throw $exception;
        }
    }

    public function versions(
        ContentTypeVersionQuery $query,
        ActorContext $actor,
    ): ContentTypeVersionPage {
        $connection = $this->preflightProtected($actor, 'content.type.read');

        try {
            $this->input->assertPageLimit($query->limit);
            $this->repository->assertCompleteCompatible();
            if ($this->repository->typeRow($query->typeKey->value) === null) {
                throw new ContentRejected('type_not_found', 'The Content type does not exist.');
            }

            $rows = $this->repository->typeVersionRows(
                $query->typeKey->value,
                $query->afterVersion,
                $query->limit,
            );
            $items = array_map(
                fn (array $row): ContentTypeVersion => $this->hydrateTypeVersion($row),
                $rows,
            );
            $last = end($items);
            $next = count($items) === $query->limit && $last instanceof ContentTypeVersion
                ? $last->version
                : null;

            return new ContentTypeVersionPage($query->typeKey, $items, $next);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.read',
                $exception,
                $this->typeSubject($query->typeKey),
                ['type_key' => $query->typeKey->value],
            );

            throw $exception;
        }
    }

    public function previewVersion(
        ContentTypeKey $typeKey,
        int $expectedVersion,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
        ActorContext $actor,
    ): ContentTypeSchemaCompatibilityReport {
        $connection = $this->preflightProtected(
            $actor,
            'content.type.version.preview',
            ['storage.schema_migration.diff', 'storage.record.read'],
        );
        $this->authorizer->assertAllowed($actor, 'content.type.read');

        try {
            $this->assertVersionCandidate(
                $expectedVersion,
                $fields,
                $projectionContract,
                $safeMetadata,
            );
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $typeKey,
                $expectedVersion,
                $fields,
                $projectionContract,
                $safeMetadata,
                $actor,
            ): ContentTypeSchemaCompatibilityReport {
                $source = $this->lockedSourceVersion($typeKey, $expectedVersion);
                $this->assertStrictlyAdditiveCandidate(
                    $source,
                    $fields,
                    $projectionContract,
                    $safeMetadata,
                );
                $heads = $this->migrationHeads($source, $actor, true);
                $storage = $this->storage->analyzeTypeSchema(
                    $typeKey,
                    new StorageSchemaVersionRef(
                        $source->storageSchemaRef,
                        $source->storageSchemaVersion,
                    ),
                    $fields,
                    $actor,
                    true,
                );
                $this->assertCompatibilityReport(
                    $source,
                    $fields,
                    $storage,
                    $heads,
                );
                $report = $this->compatibilityReport($source, $storage, $heads);
                $this->audit->emit(
                    'content.type.version.previewed',
                    $actor,
                    $this->typeSubject($typeKey),
                    ContentAuditPayload::from([
                        'operation' => 'content.type.version.preview',
                        'type_key' => $typeKey->value,
                        'source_version' => $report->sourceVersion,
                        'target_version' => $report->targetVersion,
                        'field_count' => count($fields),
                        'item_count' => $report->itemCount,
                        'added_optional_count' => $report->addedOptionalFieldCount,
                    ]),
                );

                return $report;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.version.preview',
                $exception,
                $this->typeSubject($typeKey),
                [
                    'type_key' => $typeKey->value,
                    'expected_revision' => max(0, $expectedVersion),
                ],
            );

            throw $exception;
        }
    }

    public function createVersion(
        ContentTypeKey $typeKey,
        int $expectedVersion,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
        ActorContext $actor,
    ): ContentType {
        $connection = $this->preflightProtected(
            $actor,
            'content.type.version.create',
            [
                'storage.schema_migration.diff',
                'storage.schema_migration.plan',
                'storage.schema_migration.dispatch',
                'storage.record.read',
            ],
        );
        $this->authorizer->assertAllowed($actor, 'content.type.read');

        try {
            $this->assertVersionCandidate(
                $expectedVersion,
                $fields,
                $projectionContract,
                $safeMetadata,
            );
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $typeKey,
                $expectedVersion,
                $fields,
                $projectionContract,
                $safeMetadata,
                $actor,
            ): ContentType {
                $source = $this->lockedSourceVersion($typeKey, $expectedVersion);
                $this->assertStrictlyAdditiveCandidate(
                    $source,
                    $fields,
                    $projectionContract,
                    $safeMetadata,
                );
                $heads = $this->migrationHeads($source, $actor, true);
                $report = $this->storage->analyzeTypeSchema(
                    $typeKey,
                    new StorageSchemaVersionRef(
                        $source->storageSchemaRef,
                        $source->storageSchemaVersion,
                    ),
                    $fields,
                    $actor,
                    true,
                );
                $this->assertCompatibilityReport($source, $fields, $report, $heads);
                if (!$report->compatible) {
                    throw new ContentRejected(
                        'type_schema_incompatible',
                        'The Content schema candidate is not strictly additive.',
                    );
                }

                return $this->storage->withinSchemaEvolution(
                    function ($scope) use (
                        $typeKey,
                        $source,
                        $fields,
                        $projectionContract,
                        $safeMetadata,
                        $actor,
                        $heads,
                        $report,
                    ): ContentType {
                        $plan = $this->storage->planTypeSchema(
                            $typeKey,
                            $report,
                            $fields,
                            $actor,
                            $scope,
                        );
                        $this->assertMigrationPlan($source, $report, $plan, $heads);
                        $result = $this->storage->applyTypeSchema($plan, $actor, $scope);
                        $records = $this->assertMigrationResult($plan, $result, $heads);
                        $targets = [];
                        foreach ($records as $itemRef => $record) {
                            $targets[$itemRef] = [
                                'schema_ref' => $record->after->schemaId,
                                'schema_version' => $result->target->version,
                                'record_ref' => $record->after->recordId,
                                'record_version' => $record->after->revision,
                            ];
                        }

                        return $this->commitVersion(
                            source: $source,
                            fields: $fields,
                            projectionContract: $projectionContract,
                            safeMetadata: $safeMetadata,
                            actor: $actor,
                            heads: $heads,
                            storageSchemaRef: $result->target->schemaId,
                            storageSchemaVersion: $result->target->version,
                            schemaHash: $result->targetHash,
                            storageTargets: $targets,
                            addedOptionalFieldCount: $report->addedOptionalFieldCount,
                        );
                    },
                );
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.version.create',
                $exception,
                $this->typeSubject($typeKey),
                [
                    'type_key' => $typeKey->value,
                    'expected_revision' => max(0, $expectedVersion),
                ],
            );

            throw $exception;
        }
    }

    public function list(ContentTypeQuery $query, ActorContext $actor): ContentTypePage
    {
        $connection = $this->participants->assertSharedConnection();
        $this->input->assertActor($actor);

        try {
            $scoped = $this->authorizer->scope(
                [
                    'after_type_key' => $query->afterTypeKey?->value,
                    'limit' => $query->limit,
                ],
                $actor,
                'content.type.list',
                'content.type',
            );
            $afterTypeKey = $scoped['after_type_key'] ?? null;
            $limit = $scoped['limit'] ?? null;

            if (
                ($afterTypeKey !== null && !is_string($afterTypeKey))
                || !is_int($limit)
                || $limit !== $query->limit
            ) {
                throw new ContentRejected(
                    'content_query_scope_invalid',
                    'Access returned an invalid Content type query scope.',
                );
            }

            $this->input->assertPageLimit($limit);
            $this->repository->assertCompleteCompatible();
            $rows = $this->repository->typeRows($afterTypeKey, $limit);
            $items = array_map(
                fn (array $row): ContentType => $this->hydrateType($row),
                $rows,
            );
            $last = end($items);
            $next = count($items) === $limit && $last instanceof ContentType
                ? $last->typeKey
                : null;

            return new ContentTypePage($items, $next);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.list',
                $exception,
                'content:type:list',
            );

            throw $exception;
        }
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     * @param array<string, scalar|null> $safeMetadata
     */
    private function assertVersionCandidate(
        int $expectedVersion,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
    ): void {
        $this->input->assertMutableRevision($expectedVersion);
        if ($expectedVersion < 1) {
            throw new ContentRejected('type_version_invalid');
        }
        $this->input->assertCanIncrementRevision($expectedVersion);
        $this->input->assertFields($fields);
        $this->input->assertProjectionContract($projectionContract);
        try {
            $projectionContract->assertExactFieldDefinitions($fields);
        } catch (\InvalidArgumentException $exception) {
            throw new ContentRejected(
                'projection_contract_invalid',
                'The projection contract does not match the submitted Content fields.',
                $exception,
            );
        }
        $this->input->assertSafeTypeMetadata($safeMetadata);
    }

    private function lockedSourceVersion(
        ContentTypeKey $typeKey,
        int $expectedVersion,
    ): ContentTypeVersion {
        $head = $this->repository->typeRow($typeKey->value, true);
        if ($head === null) {
            throw new ContentRejected('type_not_found', 'The Content type does not exist.');
        }
        $currentVersion = (int) $head['current_version'];
        if ($currentVersion !== $expectedVersion) {
            throw new ContentConflict(
                $expectedVersion,
                $currentVersion,
                'stale_type_version',
            );
        }
        $row = $this->repository->typeVersionRow(
            $typeKey->value,
            $expectedVersion,
            true,
        );
        if ($row === null) {
            throw new ContentIntegrationFailed('content', 'type_version_missing');
        }

        $source = $this->hydrateTypeVersion($row, true);
        if (
            $source->storageSchemaVersion !== $source->version
            || !hash_equals(
                $source->schemaHash,
                $this->schemas->schemaHash(
                    $this->schemas->definition($typeKey, $source->fieldDefinitions),
                ),
            )
        ) {
            throw new ContentIntegrationFailed(
                'content',
                'type_version_storage_contract_mismatch',
            );
        }

        return $source;
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     * @param array<string, scalar|null> $safeMetadata
     */
    private function assertStrictlyAdditiveCandidate(
        ContentTypeVersion $source,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
    ): void {
        if (count($fields) < count($source->fieldDefinitions)) {
            throw new ContentRejected(
                'type_schema_incompatible',
                'Existing Content fields cannot be removed.',
            );
        }

        foreach ($source->fieldDefinitions as $index => $existing) {
            $candidate = $fields[$index] ?? null;
            if (
                !$candidate instanceof ContentFieldDefinition
                || $this->fieldMaterial($candidate) !== $this->fieldMaterial($existing)
            ) {
                throw new ContentRejected(
                    'type_schema_incompatible',
                    'Existing Content fields cannot be renamed, reordered or changed.',
                );
            }
        }

        foreach (array_slice($fields, count($source->fieldDefinitions)) as $added) {
            if ($added->required) {
                throw new ContentRejected(
                    'type_schema_incompatible',
                    'Only optional Content fields may be appended.',
                );
            }
        }

        if (count($fields) === count($source->fieldDefinitions)) {
            throw new ContentRejected(
                'type_version_no_change',
                'A new Content type version must append at least one optional field.',
            );
        }
    }

    /**
     * @return array{
     *     key:string,
     *     property_type:string,
     *     visibility:string,
     *     required:bool,
     *     constraints:array<string,int>
     * }
     */
    private function fieldMaterial(ContentFieldDefinition $field): array
    {
        $constraints = $field->constraints;
        ksort($constraints, SORT_STRING);

        return [
            'key' => $field->key,
            'property_type' => $field->propertyType,
            'visibility' => $field->visibility->value,
            'required' => $field->required,
            'constraints' => $constraints,
        ];
    }

    /**
     * @return list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>,
     *     storage_material:array{
     *         record_id:string,
     *         owner_ref:string,
     *         expected_revision:int,
     *         expected_schema_version:int,
     *         expected_content_hash:string
     *     }
     * }>
     */
    private function migrationHeads(
        ContentTypeVersion $source,
        ActorContext $actor,
        bool $forUpdate,
    ): array {
        $heads = [];
        $items = $this->repository->itemRowsForType(
            $source->typeKey->value,
            $forUpdate,
        );

        foreach ($items as $item) {
            $itemRef = new ContentItemRef((string) $item['item_ref']);
            $currentRevision = (int) $item['current_revision'];
            $revision = $this->repository->revisionRow(
                $itemRef->value,
                $currentRevision,
                $forUpdate,
            );
            if (
                $revision === null
                || (string) $revision['type_key'] !== $source->typeKey->value
                || (int) $revision['type_version'] !== $source->version
                || (string) $revision['storage_schema_ref'] !== $source->storageSchemaRef
                || (int) $revision['storage_schema_version'] !== $source->storageSchemaVersion
            ) {
                throw new ContentIntegrationFailed(
                    'content',
                    'schema_migration_item_head_mismatch',
                );
            }

            $storage = $this->storage->readAdminCurrentVersion(
                $source->storageSchemaRef,
                $itemRef,
                $actor,
                $forUpdate,
            );
            if (
                $storage === null
                || $storage->ownerRef !== $itemRef->value
                || $storage->ref->recordId !== (string) $revision['storage_record_ref']
                || $storage->ref->revision !== (int) $revision['storage_record_version']
                || $storage->schema->key() !== (new StorageSchemaVersionRef(
                    $source->storageSchemaRef,
                    $source->storageSchemaVersion,
                ))->key()
            ) {
                throw new ContentRejected(
                    'type_storage_owner_set_mismatch',
                    'Content and Storage current owner sets do not match.',
                );
            }

            $attachments = array_map(
                static fn (array $attachment): array => [
                    'logical_file_ref' => (string) $attachment['logical_file_ref'],
                    'role' => (string) $attachment['role'],
                    'position' => (int) $attachment['position'],
                ],
                $this->repository->attachmentRows(
                    $itemRef->value,
                    $currentRevision,
                    $forUpdate,
                ),
            );
            if (count($attachments) !== (int) $revision['attachment_count']) {
                throw new ContentIntegrationFailed(
                    'content',
                    'schema_migration_attachment_manifest_mismatch',
                );
            }

            $material = [
                'item_ref' => $itemRef->value,
                'current_revision' => $currentRevision,
                'published_revision' => $item['published_revision'],
                'storage_record_ref' => (string) $revision['storage_record_ref'],
                'storage_record_version' => (int) $revision['storage_record_version'],
                'storage_schema_version' => (int) $revision['storage_schema_version'],
            ];
            $storageMaterial = [
                'record_id' => $storage->ref->recordId,
                'owner_ref' => $storage->ownerRef,
                'expected_revision' => $storage->ref->revision,
                'expected_schema_version' => $storage->schema->version,
                'expected_content_hash' => $storage->contentHash,
            ];
            $heads[] = [
                'item' => $item,
                'revision' => $revision,
                'attachments' => $attachments,
                'material' => $material,
                'storage_material' => $storageMaterial,
            ];
        }

        return $heads;
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     * @param list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>,
     *     storage_material:array{
     *         record_id:string,
     *         owner_ref:string,
     *         expected_revision:int,
     *         expected_schema_version:int,
     *         expected_content_hash:string
     *     }
     * }> $heads
     */
    private function assertCompatibilityReport(
        ContentTypeVersion $source,
        array $fields,
        StorageSchemaCompatibilityReport $report,
        array $heads,
    ): void {
        $expectedTargetHash = $this->schemas->schemaHash(
            $this->schemas->definition($source->typeKey, $fields),
        );

        if (
            $report->source->key() !== (new StorageSchemaVersionRef(
                $source->storageSchemaRef,
                $source->storageSchemaVersion,
            ))->key()
            || !hash_equals($report->sourceHash, $source->schemaHash)
            || $report->target->schemaId !== $source->storageSchemaRef
            || $report->target->version !== $source->storageSchemaVersion + 1
            || !hash_equals($report->targetHash, $expectedTargetHash)
            || $report->recordCount !== count($heads)
            || !hash_equals(
                $report->recordHeadsHash,
                $this->storageRecordHeadsHash($heads),
            )
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_report_mismatch',
            );
        }
    }

    /**
     * @param list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>,
     *     storage_material:array{
     *         record_id:string,
     *         owner_ref:string,
     *         expected_revision:int,
     *         expected_schema_version:int,
     *         expected_content_hash:string
     *     }
     * }> $heads
     */
    private function compatibilityReport(
        ContentTypeVersion $source,
        StorageSchemaCompatibilityReport $storage,
        array $heads,
    ): ContentTypeSchemaCompatibilityReport {
        return new ContentTypeSchemaCompatibilityReport(
            typeKey: $source->typeKey,
            sourceVersion: $source->version,
            sourceSchemaHash: $source->schemaHash,
            targetVersion: $source->version + 1,
            targetSchemaHash: $storage->targetHash,
            compatible: $storage->compatible,
            compatibilityClass: $storage->compatibilityClass,
            addedOptionalFieldCount: $storage->addedOptionalFieldCount,
            itemCount: count($heads),
            itemHeadsHash: $this->itemHeadsHash($heads),
            storageRecordHeadsHash: $storage->recordHeadsHash,
            reasonCodes: $storage->reasonCodes,
        );
    }

    /**
     * @param list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>,
     *     storage_material:array{
     *         record_id:string,
     *         owner_ref:string,
     *         expected_revision:int,
     *         expected_schema_version:int,
     *         expected_content_hash:string
     *     }
     * }> $heads
     */
    private function itemHeadsHash(array $heads): string
    {
        return hash(
            'sha256',
            $this->canonicalJson->encode(array_column($heads, 'material')),
        );
    }

    /**
     * @param list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>,
     *     storage_material:array{
     *         record_id:string,
     *         owner_ref:string,
     *         expected_revision:int,
     *         expected_schema_version:int,
     *         expected_content_hash:string
     *     }
     * }> $heads
     */
    private function storageRecordHeadsHash(array $heads): string
    {
        $storageMaterial = array_column($heads, 'storage_material');
        usort(
            $storageMaterial,
            static fn (array $left, array $right): int => $left['record_id']
                <=> $right['record_id'],
        );

        return hash(
            'sha256',
            $this->canonicalJson->encode($storageMaterial),
        );
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     * @param array<string, scalar|null> $safeMetadata
     * @param list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>,
     *     storage_material:array{
     *         record_id:string,
     *         owner_ref:string,
     *         expected_revision:int,
     *         expected_schema_version:int,
     *         expected_content_hash:string
     *     }
     * }> $heads
     * @param array<string, array{
     *     schema_ref:string,
     *     schema_version:int,
     *     record_ref:string,
     *     record_version:int
     * }> $storageTargets
     */
    private function commitVersion(
        ContentTypeVersion $source,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
        ActorContext $actor,
        array $heads,
        string $storageSchemaRef,
        int $storageSchemaVersion,
        string $schemaHash,
        array $storageTargets,
        int $addedOptionalFieldCount,
    ): ContentType {
        $targetStorage = new StorageSchemaVersionRef(
            $storageSchemaRef,
            $storageSchemaVersion,
        );
        if (
            $targetStorage->schemaId !== $source->storageSchemaRef
            || $targetStorage->version !== $source->storageSchemaVersion + 1
            || $targetStorage->version !== $source->version + 1
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_result_mismatch',
            );
        }

        $timestamp = $this->timestamp($this->clock->now());
        $targetVersion = $source->version + 1;
        $remainingTargets = $storageTargets;

        foreach ($heads as $head) {
            $itemRef = (string) $head['item']['item_ref'];
            $target = $remainingTargets[$itemRef] ?? null;
            $currentStorageVersion = (int) $head['revision']['storage_record_version'];
            if (
                !is_array($target)
                || $target['schema_ref'] !== $targetStorage->schemaId
                || $target['schema_version'] !== $targetStorage->version
                || $target['record_ref'] !== (string) $head['revision']['storage_record_ref']
                || $target['record_version'] !== $currentStorageVersion + 1
            ) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_evolution_result_mismatch',
                );
            }
            unset($remainingTargets[$itemRef]);

            $currentRevision = (int) $head['item']['current_revision'];
            $nextRevision = $currentRevision + 1;
            $this->input->assertCanIncrementRevision($currentRevision);
            $attachments = $head['attachments'];

            $this->repository->appendRevision([
                'item_ref' => $itemRef,
                'revision' => $nextRevision,
                'type_key' => $source->typeKey->value,
                'locale' => (string) $head['item']['locale'],
                'type_version' => $targetVersion,
                'storage_schema_ref' => $target['schema_ref'],
                'storage_schema_version' => $target['schema_version'],
                'storage_record_ref' => $target['record_ref'],
                'storage_record_version' => $target['record_version'],
                'slug' => (string) $head['item']['current_slug'],
                'status' => ContentStatus::Draft->value,
                'visibility' => (string) $head['item']['current_visibility'],
                'attachment_count' => count($attachments),
                'created_by' => $actor->actorRef,
                'correlation_id' => $actor->correlationId,
                'created_at' => $timestamp,
            ], $attachments);

            if (!$this->repository->compareAndSwapItemHead(
                $itemRef,
                $currentRevision,
                [
                    'current_revision' => $nextRevision,
                    'current_slug' => (string) $head['item']['current_slug'],
                    'current_status' => ContentStatus::Draft->value,
                    'current_visibility' => (string) $head['item']['current_visibility'],
                    'published_revision' => $head['item']['published_revision'],
                    'published_slug' => $head['item']['published_slug'],
                    'published_at' => $head['item']['published_at'],
                    'updated_at' => $timestamp,
                ],
            )) {
                throw new ContentConflict(
                    $currentRevision,
                    $currentRevision + 1,
                    'type_item_head_stale',
                );
            }

            $this->refreshVersionedRoutes($head['item'], $nextRevision, $timestamp);
        }

        if ($remainingTargets !== []) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_result_mismatch',
            );
        }

        $this->repository->insertTypeVersion([
            'type_key' => $source->typeKey->value,
            'version' => $targetVersion,
            'storage_schema_ref' => $targetStorage->schemaId,
            'storage_schema_version' => $targetStorage->version,
            'schema_hash' => $schemaHash,
            'projection_contract' => $this->canonicalJson->encode(
                $projectionContract->toArray(),
            ),
            'safe_metadata' => $this->canonicalJson->encode($safeMetadata),
            'created_by' => $actor->actorRef,
            'correlation_id' => $actor->correlationId,
            'created_at' => $timestamp,
        ]);
        if (!$this->repository->compareAndSwapTypeHead(
            $source->typeKey->value,
            $source->version,
            $targetVersion,
            $timestamp,
        )) {
            throw new ContentConflict(
                $source->version,
                $source->version + 1,
                'stale_type_version',
            );
        }

        $this->audit->emit(
            'content.type.versioned',
            $actor,
            $this->typeSubject($source->typeKey),
            ContentAuditPayload::from([
                'operation' => 'content.type.version.create',
                'type_key' => $source->typeKey->value,
                'source_version' => $source->version,
                'target_version' => $targetVersion,
                'field_count' => count($fields),
                'item_count' => count($heads),
                'added_optional_count' => $addedOptionalFieldCount,
            ]),
        );

        return new ContentType($source->typeKey, $targetVersion);
    }

    /**
     * @param list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>,
     *     storage_material:array{
     *         record_id:string,
     *         owner_ref:string,
     *         expected_revision:int,
     *         expected_schema_version:int,
     *         expected_content_hash:string
     *     }
     * }> $heads
     */
    private function assertMigrationPlan(
        ContentTypeVersion $source,
        StorageSchemaCompatibilityReport $report,
        StorageSchemaMigrationPlan $plan,
        array $heads,
    ): void {
        if (
            $plan->source->key() !== $report->source->key()
            || !hash_equals($plan->sourceHash, $source->schemaHash)
            || $plan->target->key() !== $report->target->key()
            || !hash_equals($plan->targetHash, $report->targetHash)
            || !hash_equals($plan->recordHeadsHash, $report->recordHeadsHash)
            || $plan->recordCount !== count($heads)
            || count($plan->records) !== count($heads)
        ) {
            throw new ContentRejected(
                'type_storage_owner_set_mismatch',
                'The Storage migration plan does not match the Content owner set.',
            );
        }

        $byOwner = [];
        foreach ($plan->records as $record) {
            if (isset($byOwner[$record->ownerRef])) {
                throw new ContentRejected('type_storage_owner_set_mismatch');
            }
            $byOwner[$record->ownerRef] = $record;
        }
        foreach ($heads as $head) {
            $itemRef = (string) $head['item']['item_ref'];
            $record = $byOwner[$itemRef] ?? null;
            if (
                $record === null
                || $record->before->schemaId !== (string) $head['revision']['storage_schema_ref']
                || $record->before->recordId !== (string) $head['revision']['storage_record_ref']
                || $record->before->revision !== (int) $head['revision']['storage_record_version']
                || $record->schemaVersion !== (int) $head['revision']['storage_schema_version']
            ) {
                throw new ContentRejected('type_storage_owner_set_mismatch');
            }
            unset($byOwner[$itemRef]);
        }
        if ($byOwner !== []) {
            throw new ContentRejected('type_storage_owner_set_mismatch');
        }
    }

    /**
     * @param list<array{
     *     item:array<string,bool|int|string|null>,
     *     revision:array<string,bool|int|string|null>,
     *     attachments:list<array{logical_file_ref:string,role:string,position:int}>,
     *     material:array<string,bool|int|string|null>
     * }> $heads
     * @return array<string, StorageSchemaMigrationRecordResult>
     */
    private function assertMigrationResult(
        StorageSchemaMigrationPlan $plan,
        StorageSchemaMigrationResult $result,
        array $heads,
    ): array {
        if (
            $result->planRef !== $plan->planRef
            || $result->target->key() !== $plan->target->key()
            || !hash_equals($result->targetHash, $plan->targetHash)
            || $result->migratedRecordCount !== count($heads)
            || count($result->records) !== count($heads)
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_result_mismatch',
            );
        }

        $plannedByOwner = [];
        foreach ($plan->records as $planned) {
            if (isset($plannedByOwner[$planned->ownerRef])) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_evolution_result_mismatch',
                );
            }
            $plannedByOwner[$planned->ownerRef] = $planned;
        }

        $byOwner = [];
        foreach ($result->records as $record) {
            if (isset($byOwner[$record->ownerRef])) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_evolution_result_mismatch',
                );
            }
            $byOwner[$record->ownerRef] = $record;
        }
        foreach ($heads as $head) {
            $itemRef = (string) $head['item']['item_ref'];
            $record = $byOwner[$itemRef] ?? null;
            $planned = $plannedByOwner[$itemRef] ?? null;
            if (
                !$record instanceof StorageSchemaMigrationRecordResult
                || !$planned instanceof StorageSchemaMigrationRecordHead
                || $record->before->schemaId !== (string) $head['revision']['storage_schema_ref']
                || $record->before->recordId !== (string) $head['revision']['storage_record_ref']
                || $record->before->revision !== (int) $head['revision']['storage_record_version']
                || $record->before->key() !== $planned->before->key()
                || !hash_equals($record->contentHash, $planned->contentHash)
                || $record->after->recordId !== $record->before->recordId
                || $record->after->revision !== $record->before->revision + 1
                || $record->after->schemaId !== $result->target->schemaId
            ) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_evolution_result_mismatch',
                );
            }
        }

        return $byOwner;
    }

    /**
     * @param array<string, bool|int|string|null> $item
     */
    private function refreshVersionedRoutes(
        array $item,
        int $nextRevision,
        string $timestamp,
    ): void {
        $slugs = [(string) $item['current_slug'] => true];
        if ($item['published_slug'] !== null) {
            $slugs[(string) $item['published_slug']] = true;
        }
        ksort($slugs, SORT_STRING);

        foreach (array_keys($slugs) as $slug) {
            $this->repository->setRoute([
                'type_key' => (string) $item['type_key'],
                'locale' => (string) $item['locale'],
                'slug' => $slug,
                'item_ref' => (string) $item['item_ref'],
                'current_revision' => $slug === (string) $item['current_slug']
                    ? $nextRevision
                    : null,
                'published_revision' => $slug === (string) $item['published_slug']
                    ? $item['published_revision']
                    : null,
                'created_at' => (string) $item['created_at'],
                'updated_at' => $timestamp,
            ]);
        }
    }

    /**
     * @param list<string> $storageOperations
     */
    private function preflightProtected(
        ActorContext $actor,
        string $operation,
        array $storageOperations = [],
    ): ConnectionInterface {
        $connection = $this->participants->assertSharedConnection();
        $this->input->assertActor($actor);
        $this->authorizer->assertAllowed($actor, $operation, $storageOperations);

        return $connection;
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateType(array $row): ContentType
    {
        try {
            return new ContentType(
                new ContentTypeKey((string) $row['type_key']),
                (int) $row['current_version'],
            );
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_type_head_invalid',
                $exception,
            );
        }
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateTypeVersion(
        array $row,
        bool $forUpdate = false,
    ): ContentTypeVersion
    {
        try {
            $typeKey = new ContentTypeKey((string) $row['type_key']);
            $storageRef = new StorageSchemaVersionRef(
                (string) $row['storage_schema_ref'],
                (int) $row['storage_schema_version'],
            );
            try {
                $schema = $this->storage->schemaVersion($storageRef, $forUpdate);
            } catch (ContentIntegrationFailed $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_version_read_failed',
                    $exception,
                );
            }
            if (!hash_equals((string) $row['schema_hash'], $schema->definitionHash)) {
                throw new ContentIntegrationFailed(
                    'content',
                    'type_version_storage_hash_mismatch',
                );
            }

            $fields = $this->schemas->fieldDefinitions($schema);
            $projection = ContentProjectionContract::fromArray(
                $this->decodeObject((string) $row['projection_contract']),
                $fields,
            );
            $safeMetadata = $this->decodeObject((string) $row['safe_metadata']);

            return new ContentTypeVersion(
                typeKey: $typeKey,
                version: (int) $row['version'],
                storageSchemaRef: $storageRef->schemaId,
                storageSchemaVersion: $storageRef->version,
                schemaHash: (string) $row['schema_hash'],
                fieldDefinitions: $fields,
                projectionContract: $projection,
                safeMetadata: $safeMetadata,
                createdBy: (string) $row['created_by'],
                correlationId: (string) $row['correlation_id'],
                createdAt: $this->dateTime((string) $row['created_at']),
            );
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_type_version_invalid',
                $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_json_invalid',
                $exception,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_json_invalid',
            );
        }

        return $decoded;
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                $value,
                new DateTimeZone('UTC'),
            );
            $errors = DateTimeImmutable::getLastErrors();

            if (
                !$dateTime instanceof DateTimeImmutable
                || (
                    $errors !== false
                    && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)
                )
                || $dateTime->format('Y-m-d H:i:s.u') !== $value
            ) {
                throw new \UnexpectedValueException(
                    'Persisted Content timestamps must use exact UTC microseconds.',
                );
            }

            return $dateTime;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_timestamp_invalid',
                $exception,
            );
        }
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function typeSubject(ContentTypeKey $typeKey): string
    {
        return 'content:type:'.$typeKey->value;
    }

    /**
     * @param array<string, bool|int|string|null> $context
     */
    private function auditDomainDenial(
        ConnectionInterface $connection,
        ActorContext $actor,
        string $operation,
        ContentRejected $rejection,
        string $subject,
        array $context = [],
    ): void {
        try {
            $connection->transaction(function () use (
                $actor,
                $operation,
                $rejection,
                $subject,
                $context,
            ): void {
                $this->audit->domainDenied(
                    $actor,
                    $operation,
                    $rejection->reasonCode(),
                    $subject,
                    $context,
                );
            }, 1);
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'audit',
                'content_audit_write_failed',
                $exception,
            );
        }
    }
}

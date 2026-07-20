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
use Larena\Content\Contracts\ContentIdGenerator;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\Runtime\PublishedContentProjectionBuilder;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentAttachmentPlacement;
use Larena\Content\ValueObjects\ContentAttachmentPage;
use Larena\Content\ValueObjects\ContentAttachmentReference;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemPage;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentRevision;
use Larena\Content\ValueObjects\ContentRevisionPage;
use Larena\Content\ValueObjects\ContentRevisionQuery;
use Larena\Content\ValueObjects\ContentSearchProjection;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Search\Contracts\SearchWriteResult;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Throwable;

final readonly class DatabaseContentItemService implements ContentItemService
{
    public function __construct(
        private DatabaseContentRepository $repository,
        private ContentAuthorizer $authorizer,
        private ContentParticipantGuard $participants,
        private ContentStorageGateway $storage,
        private ContentSchemaMapper $schemas,
        private ContentInputGuard $input,
        private ContentLogicalFileInspector $files,
        private PublishedContentProjectionBuilder $projections,
        private DatabaseSearchIndex $search,
        private ContentAuditEmitter $audit,
        private ContentClock $clock,
        private ContentIdGenerator $ids,
    ) {
    }

    public function create(
        ContentTypeKey $typeKey,
        ContentLocale $locale,
        ContentSlug $slug,
        ContentVisibility $visibility,
        array $values,
        ActorContext $actor,
    ): ContentItem {
        $connection = $this->preflightProtected(
            $actor,
            'content.item.create',
            ['storage.record.create'],
        );
        $itemRef = $this->ids->newItemRef();

        try {
            $this->input->assertSubmittedValues($values);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $typeKey,
                $locale,
                $slug,
                $visibility,
                $values,
                $actor,
                $itemRef,
            ): ContentItem {
                $typeHead = $this->repository->typeRow($typeKey->value, true);
                if ($typeHead === null) {
                    throw new ContentRejected('type_not_found', 'The Content type does not exist.');
                }

                if ($this->repository->itemRow($itemRef->value, true) !== null) {
                    throw new ContentRejected('item_identity_conflict');
                }
                $this->lockAndAssertRoutesAvailable(
                    $typeKey,
                    $locale,
                    [$slug],
                    $itemRef,
                );

                $typeVersion = $this->typeVersion(
                    $typeKey,
                    (int) $typeHead['current_version'],
                    true,
                );
                $normalized = $this->schemas->normalizeValues(
                    $typeVersion->fieldDefinitions,
                    $values,
                );
                $storageWrite = $this->storage->create(
                    $itemRef,
                    new StorageSchemaVersionRef(
                        $typeVersion->storageSchemaRef,
                        $typeVersion->storageSchemaVersion,
                    ),
                    $normalized,
                    $actor,
                );
                $now = $this->clock->now();
                $revision = new ContentRevision(
                    itemRef: $itemRef,
                    revision: 1,
                    typeKey: $typeKey,
                    locale: $locale,
                    typeVersion: $typeVersion->version,
                    storageSchemaRef: $storageWrite->ref()->schemaId,
                    storageSchemaVersion: $storageWrite->version->schema->version,
                    storageRecordRef: $storageWrite->ref()->recordId,
                    storageRecordVersion: $storageWrite->ref()->revision,
                    slug: $slug,
                    status: ContentStatus::Draft,
                    visibility: $visibility,
                    attachmentCount: 0,
                    createdBy: $actor->actorRef,
                    correlationId: $actor->correlationId,
                    createdAt: $now,
                );
                $item = new ContentItem(
                    itemRef: $itemRef,
                    typeKey: $typeKey,
                    locale: $locale,
                    currentRevision: 1,
                    currentSlug: $slug,
                    currentStatus: ContentStatus::Draft,
                    currentVisibility: $visibility,
                    publishedRevision: null,
                    publishedSlug: null,
                    publishedAt: null,
                );
                $timestamp = $this->timestamp($now);

                $this->repository->insertItemHead($this->itemHeadRow($item, $timestamp, $timestamp));
                $this->repository->appendRevision($this->revisionRow($revision), []);
                $this->synchronizeRoutes(null, $item, $timestamp);
                $this->audit->emit(
                    'content.item.created',
                    $actor,
                    $itemRef->value,
                    ContentAuditPayload::from([
                        'operation' => 'content.item.create',
                        'type_key' => $typeKey->value,
                        'item_ref' => $itemRef->value,
                        'new_revision' => 1,
                        'status' => ContentStatus::Draft->value,
                        'visibility' => $visibility->value,
                        'field_count' => count($normalized),
                        'attachment_count' => 0,
                    ]),
                );

                return $item;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.item.create',
                $exception,
                $itemRef->value,
                [
                    'type_key' => $typeKey->value,
                    'item_ref' => $itemRef->value,
                ],
            );

            throw $exception;
        }
    }

    public function read(ContentItemRef $itemRef, ActorContext $actor): ContentItem
    {
        $connection = $this->preflightProtected($actor, 'content.item.read');

        try {
            $this->repository->assertCompleteCompatible();
            $row = $this->repository->itemRow($itemRef->value);
            if ($row === null) {
                throw new ContentRejected('item_not_found', 'The Content item does not exist.');
            }

            return $this->hydrateItem($row);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.item.read',
                $exception,
                $itemRef->value,
                ['item_ref' => $itemRef->value],
            );

            throw $exception;
        }
    }

    public function list(ContentItemQuery $query, ActorContext $actor): ContentItemPage
    {
        $connection = $this->participants->assertSharedConnection();
        $this->input->assertActor($actor);

        try {
            $rawQuery = [
                'type_key' => $query->typeKey?->value,
                'locale' => $query->locale?->value,
                'status' => $query->status?->value,
                'visibility' => $query->visibility?->value,
                'after_item_ref' => $query->afterItemRef?->value,
                'limit' => $query->limit,
            ];
            $scoped = $this->authorizer->scope(
                $rawQuery,
                $actor,
                'content.item.list',
                'content.item',
            );
            if ($scoped !== $rawQuery) {
                throw new ContentRejected(
                    'content_query_scope_invalid',
                    'Content Platform v1 accepts only the exact global-role list scope.',
                );
            }

            $this->input->assertPageLimit($query->limit);
            $filters = array_filter(
                [
                    'type_key' => $query->typeKey?->value,
                    'locale' => $query->locale?->value,
                    'status' => $query->status?->value,
                    'visibility' => $query->visibility?->value,
                ],
                static fn (?string $value): bool => $value !== null,
            );
            $this->repository->assertCompleteCompatible();
            $rows = $this->repository->itemRows(
                $filters,
                $query->afterItemRef?->value,
                $query->limit,
            );
            $items = array_map(
                fn (array $row): ContentItem => $this->hydrateItem($row),
                $rows,
            );
            $last = end($items);
            $next = count($items) === $query->limit && $last instanceof ContentItem
                ? $last->itemRef
                : null;

            return new ContentItemPage($items, $next);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.item.list',
                $exception,
                'content:item:list',
            );

            throw $exception;
        }
    }

    public function update(
        ContentItemRef $itemRef,
        int $expectedRevision,
        ContentSlug $slug,
        ContentVisibility $visibility,
        array $values,
        ActorContext $actor,
    ): ContentItem {
        $connection = $this->preflightProtected(
            $actor,
            'content.item.update',
            ['storage.record.update'],
        );

        try {
            $this->assertExpectedRevision($expectedRevision);
            $this->input->assertSubmittedValues($values);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $itemRef,
                $expectedRevision,
                $slug,
                $visibility,
                $values,
                $actor,
            ): ContentItem {
                [$before, $current] = $this->lockedCurrent($itemRef, $expectedRevision);
                $this->lockAndAssertRoutesAvailable(
                    $before->typeKey,
                    $before->locale,
                    $this->routeSlugs($before, $slug),
                    $itemRef,
                );
                $typeVersion = $this->typeVersion(
                    $before->typeKey,
                    $current->typeVersion,
                    true,
                );
                $this->assertRevisionSchemaMatchesTypeVersion($current, $typeVersion);
                $normalized = $this->schemas->normalizeValues(
                    $typeVersion->fieldDefinitions,
                    $values,
                );
                $storageWrite = $this->storage->update(
                    $itemRef,
                    $this->storageRef($current),
                    new StorageSchemaVersionRef(
                        $typeVersion->storageSchemaRef,
                        $typeVersion->storageSchemaVersion,
                    ),
                    $normalized,
                    $actor,
                );
                $attachments = $this->attachmentPlacementsForRevision($current, true);
                $now = $this->clock->now();
                [$after] = $this->persistNextRevision(
                    before: $before,
                    storageRef: $storageWrite->ref(),
                    storageSchema: $storageWrite->version->schema,
                    typeVersion: $typeVersion->version,
                    slug: $slug,
                    status: ContentStatus::Draft,
                    visibility: $visibility,
                    attachments: $attachments,
                    publishedRevision: $before->publishedRevision,
                    publishedSlug: $before->publishedSlug,
                    publishedAt: $before->publishedAt,
                    actor: $actor,
                    now: $now,
                );
                $this->audit->emit(
                    'content.item.updated',
                    $actor,
                    $itemRef->value,
                    ContentAuditPayload::from([
                        'operation' => 'content.item.update',
                        'type_key' => $before->typeKey->value,
                        'item_ref' => $itemRef->value,
                        'expected_revision' => $expectedRevision,
                        'current_revision' => $before->currentRevision,
                        'new_revision' => $after->currentRevision,
                        'status' => $after->currentStatus->value,
                        'visibility' => $after->currentVisibility->value,
                        'field_count' => count($normalized),
                        'attachment_count' => count($attachments),
                    ]),
                );

                return $after;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.item.update',
                $exception,
                $itemRef->value,
                [
                    'item_ref' => $itemRef->value,
                    'expected_revision' => max(0, $expectedRevision),
                ],
            );

            throw $exception;
        }
    }

    public function restore(
        ContentItemRef $itemRef,
        int $restoreRevision,
        int $expectedRevision,
        ActorContext $actor,
    ): ContentItem {
        $connection = $this->preflightProtected(
            $actor,
            'content.item.restore',
            ['storage.record.read', 'storage.record.update'],
        );

        try {
            $this->assertExpectedRevision($restoreRevision);
            $this->assertExpectedRevision($expectedRevision);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $itemRef,
                $restoreRevision,
                $expectedRevision,
                $actor,
            ): ContentItem {
                [$before, $current] = $this->lockedCurrent($itemRef, $expectedRevision);
                $targetRow = $this->repository->revisionRow(
                    $itemRef->value,
                    $restoreRevision,
                    true,
                );
                if ($targetRow === null) {
                    throw new ContentRejected(
                        'revision_not_found',
                        'The Content restore revision does not exist.',
                    );
                }
                $target = $this->hydrateRevision($targetRow);
                $this->assertRevisionBelongsToItem($target, $before);
                $this->lockAndAssertRoutesAvailable(
                    $before->typeKey,
                    $before->locale,
                    $this->routeSlugs($before, $target->slug),
                    $itemRef,
                );
                $currentTypeVersion = $this->typeVersion(
                    $before->typeKey,
                    $current->typeVersion,
                    true,
                );
                $targetTypeVersion = $this->typeVersion(
                    $before->typeKey,
                    $target->typeVersion,
                    true,
                );
                $this->assertRevisionSchemaMatchesTypeVersion(
                    $current,
                    $currentTypeVersion,
                );
                $this->assertRevisionSchemaMatchesTypeVersion(
                    $target,
                    $targetTypeVersion,
                );
                $storageWrite = $this->storage->restore(
                    $itemRef,
                    $this->storageRef($current),
                    $this->storageSchemaRef($current),
                    $this->storageRef($target),
                    $this->storageSchemaRef($target),
                    $actor,
                );
                $attachments = $this->attachmentPlacementsForRevision($target, true);
                $now = $this->clock->now();
                [$after] = $this->persistNextRevision(
                    before: $before,
                    storageRef: $storageWrite->ref(),
                    storageSchema: $storageWrite->version->schema,
                    typeVersion: $currentTypeVersion->version,
                    slug: $target->slug,
                    status: ContentStatus::Draft,
                    visibility: $target->visibility,
                    attachments: $attachments,
                    publishedRevision: $before->publishedRevision,
                    publishedSlug: $before->publishedSlug,
                    publishedAt: $before->publishedAt,
                    actor: $actor,
                    now: $now,
                );
                $this->audit->emit(
                    'content.item.restored',
                    $actor,
                    $itemRef->value,
                    ContentAuditPayload::from([
                        'operation' => 'content.item.restore',
                        'type_key' => $before->typeKey->value,
                        'item_ref' => $itemRef->value,
                        'expected_revision' => $expectedRevision,
                        'current_revision' => $restoreRevision,
                        'new_revision' => $after->currentRevision,
                        'status' => $after->currentStatus->value,
                        'visibility' => $after->currentVisibility->value,
                        'attachment_count' => count($attachments),
                    ]),
                );

                return $after;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.item.restore',
                $exception,
                $itemRef->value,
                [
                    'item_ref' => $itemRef->value,
                    'expected_revision' => max(0, $expectedRevision),
                    'current_revision' => max(0, $restoreRevision),
                ],
            );

            throw $exception;
        }
    }

    public function revision(
        ContentItemRef $itemRef,
        int $revision,
        ActorContext $actor,
    ): ContentRevision {
        $connection = $this->preflightProtected($actor, 'content.revision.read');

        try {
            $this->assertExpectedRevision($revision);
            $this->repository->assertCompleteCompatible();
            $row = $this->repository->revisionRow($itemRef->value, $revision);
            if ($row === null) {
                throw new ContentRejected('revision_not_found');
            }

            return $this->hydrateRevision($row);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.revision.read',
                $exception,
                $itemRef->value,
                [
                    'item_ref' => $itemRef->value,
                    'current_revision' => max(0, $revision),
                ],
            );

            throw $exception;
        }
    }

    public function revisions(
        ContentRevisionQuery $query,
        ActorContext $actor,
    ): ContentRevisionPage {
        $connection = $this->participants->assertSharedConnection();
        $this->input->assertActor($actor);

        try {
            $rawQuery = [
                'item_ref' => $query->itemRef->value,
                'after_revision' => $query->afterRevision,
                'limit' => $query->limit,
            ];
            $scoped = $this->authorizer->scope(
                $rawQuery,
                $actor,
                'content.revision.list',
                'content.revision',
            );
            if ($scoped !== $rawQuery) {
                throw new ContentRejected('content_query_scope_invalid');
            }

            $this->input->assertPageLimit($query->limit);
            $this->repository->assertCompleteCompatible();
            if ($this->repository->itemRow($query->itemRef->value) === null) {
                throw new ContentRejected('item_not_found');
            }
            $rows = $this->repository->revisionRows(
                $query->itemRef->value,
                $query->afterRevision,
                $query->limit,
            );
            $items = array_map(
                fn (array $row): ContentRevision => $this->hydrateRevision($row),
                $rows,
            );
            $last = end($items);
            $next = count($items) === $query->limit && $last instanceof ContentRevision
                ? $last->revision
                : null;

            return new ContentRevisionPage($query->itemRef, $items, $next);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.revision.list',
                $exception,
                $query->itemRef->value,
                ['item_ref' => $query->itemRef->value],
            );

            throw $exception;
        }
    }

    public function attachments(
        ContentItemRef $itemRef,
        int $revision,
        ActorContext $actor,
    ): array {
        $connection = $this->preflightProtected($actor, 'content.attachment.list');

        try {
            $this->assertExpectedRevision($revision);
            $this->repository->assertCompleteCompatible();
            $revisionRow = $this->repository->revisionRow($itemRef->value, $revision);
            if ($revisionRow === null) {
                throw new ContentRejected('revision_not_found');
            }

            return $this->attachmentReferencesForRevision(
                $this->hydrateRevision($revisionRow),
            );
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.attachment.list',
                $exception,
                $itemRef->value,
                [
                    'item_ref' => $itemRef->value,
                    'current_revision' => max(0, $revision),
                ],
            );

            throw $exception;
        }
    }

    public function currentAttachments(
        ContentItemRef $itemRef,
        ActorContext $actor,
    ): ContentAttachmentPage {
        $connection = $this->preflightProtected($actor, 'content.attachment.list');

        try {
            $this->repository->assertCompleteCompatible();
            $item = $this->repository->itemRow($itemRef->value);
            if ($item === null) {
                throw new ContentRejected('item_not_found');
            }
            $revisionNumber = (int) $item['current_revision'];
            $revision = $this->repository->revisionRow(
                $itemRef->value,
                $revisionNumber,
            );
            if ($revision === null) {
                throw new ContentIntegrationFailed(
                    'content',
                    'current_revision_missing',
                );
            }

            return new ContentAttachmentPage(
                $itemRef,
                $revisionNumber,
                $this->attachmentReferencesForRevision(
                    $this->hydrateRevision($revision),
                ),
            );
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.attachment.list',
                $exception,
                $itemRef->value,
                ['item_ref' => $itemRef->value],
            );

            throw $exception;
        }
    }

    public function attach(
        ContentItemRef $itemRef,
        int $expectedRevision,
        string $logicalFileRef,
        string $role,
        ActorContext $actor,
    ): ContentItem {
        $connection = $this->preflightProtected($actor, 'content.attachment.attach');

        try {
            $this->assertExpectedRevision($expectedRevision);
            $this->input->assertLogicalFileRef($logicalFileRef);
            $this->assertAttachmentRole($role);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $itemRef,
                $expectedRevision,
                $logicalFileRef,
                $role,
                $actor,
            ): ContentItem {
                [$before, $current] = $this->lockedCurrent($itemRef, $expectedRevision);
                $this->lockAndAssertRoutesAvailable(
                    $before->typeKey,
                    $before->locale,
                    $this->routeSlugs($before, $before->currentSlug),
                    $itemRef,
                );
                $inspection = $this->files->inspect($logicalFileRef);
                if (!$inspection->isContentAttachable()) {
                    throw new ContentRejected('logical_file_not_attachable');
                }

                $placements = $this->attachmentPlacementsForRevision($current, true);
                foreach ($placements as $placement) {
                    if (
                        $placement->logicalFileRef === $logicalFileRef
                        && $placement->role === $role
                    ) {
                        throw new ContentRejected('attachment_already_exists');
                    }
                }
                $placements[] = new ContentAttachmentPlacement(
                    $logicalFileRef,
                    $role,
                    count($placements),
                );
                $this->input->assertAttachmentManifest($placements);
                $now = $this->clock->now();
                [$after] = $this->persistNextRevision(
                    before: $before,
                    storageRef: $this->storageRef($current),
                    storageSchema: $this->storageSchemaRef($current),
                    typeVersion: $current->typeVersion,
                    slug: $before->currentSlug,
                    status: ContentStatus::Draft,
                    visibility: $before->currentVisibility,
                    attachments: $placements,
                    publishedRevision: $before->publishedRevision,
                    publishedSlug: $before->publishedSlug,
                    publishedAt: $before->publishedAt,
                    actor: $actor,
                    now: $now,
                );
                $this->audit->emit(
                    'content.attachment.attached',
                    $actor,
                    $itemRef->value,
                    ContentAuditPayload::from([
                        'operation' => 'content.attachment.attach',
                        'item_ref' => $itemRef->value,
                        'logical_file_ref' => $logicalFileRef,
                        'expected_revision' => $expectedRevision,
                        'new_revision' => $after->currentRevision,
                        'attachment_count' => count($placements),
                    ]),
                );

                return $after;
            }, 3);
        } catch (ContentRejected $exception) {
            $auditLogicalFileRef = $this->auditLogicalFileRef($logicalFileRef);
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.attachment.attach',
                $exception,
                $itemRef->value,
                [
                    'item_ref' => $itemRef->value,
                    ...($auditLogicalFileRef === null
                        ? []
                        : ['logical_file_ref' => $auditLogicalFileRef]),
                    'expected_revision' => max(0, $expectedRevision),
                ],
            );

            throw $exception;
        }
    }

    public function detach(
        ContentItemRef $itemRef,
        int $expectedRevision,
        string $logicalFileRef,
        string $role,
        ActorContext $actor,
    ): ContentItem {
        return $this->changeAttachmentManifest(
            itemRef: $itemRef,
            expectedRevision: $expectedRevision,
            actor: $actor,
            operation: 'content.attachment.detach',
            eventType: 'content.attachment.detached',
            logicalFileRef: $logicalFileRef,
            role: $role,
            transform: static function (array $placements) use ($logicalFileRef, $role): array {
                $found = false;
                $remaining = [];

                foreach ($placements as $placement) {
                    if (
                        $placement->logicalFileRef === $logicalFileRef
                        && $placement->role === $role
                    ) {
                        $found = true;
                        continue;
                    }
                    $remaining[] = new ContentAttachmentPlacement(
                        $placement->logicalFileRef,
                        $placement->role,
                        count($remaining),
                    );
                }

                if (!$found) {
                    throw new ContentRejected('attachment_not_found');
                }

                return $remaining;
            },
        );
    }

    public function reorder(
        ContentItemRef $itemRef,
        int $expectedRevision,
        array $placements,
        ActorContext $actor,
    ): ContentItem {
        return $this->changeAttachmentManifest(
            itemRef: $itemRef,
            expectedRevision: $expectedRevision,
            actor: $actor,
            operation: 'content.attachment.reorder',
            eventType: 'content.attachment.reordered',
            logicalFileRef: null,
            role: null,
            transform: static function (array $current) use ($placements): array {
                $currentIdentities = array_map(
                    static fn (ContentAttachmentPlacement $placement): string => $placement->logicalFileRef."\0".$placement->role,
                    $current,
                );
                $nextIdentities = array_map(
                    static fn (ContentAttachmentPlacement $placement): string => $placement->logicalFileRef."\0".$placement->role,
                    $placements,
                );
                sort($currentIdentities, SORT_STRING);
                sort($nextIdentities, SORT_STRING);

                if ($currentIdentities !== $nextIdentities) {
                    throw new ContentRejected('attachment_manifest_identity_mismatch');
                }

                return $placements;
            },
        );
    }

    public function publish(
        ContentItemRef $itemRef,
        int $expectedRevision,
        ActorContext $actor,
    ): ContentItem {
        $connection = $this->preflightProtected($actor, 'content.item.publish');

        try {
            $this->assertExpectedRevision($expectedRevision);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $itemRef,
                $expectedRevision,
                $actor,
            ): ContentItem {
                [$before, $current] = $this->lockedCurrent($itemRef, $expectedRevision);
                if ($before->currentVisibility !== ContentVisibility::Public) {
                    throw new ContentRejected('private_item_cannot_publish');
                }
                $this->lockAndAssertRoutesAvailable(
                    $before->typeKey,
                    $before->locale,
                    $this->routeSlugs($before, $before->currentSlug),
                    $itemRef,
                );
                $placements = $this->attachmentPlacementsForRevision($current, true);
                $now = $this->clock->now();
                $nextRevision = $before->currentRevision + 1;
                [$after, $publishedRevision] = $this->persistNextRevision(
                    before: $before,
                    storageRef: $this->storageRef($current),
                    storageSchema: $this->storageSchemaRef($current),
                    typeVersion: $current->typeVersion,
                    slug: $before->currentSlug,
                    status: ContentStatus::Published,
                    visibility: ContentVisibility::Public,
                    attachments: $placements,
                    publishedRevision: $nextRevision,
                    publishedSlug: $before->currentSlug,
                    publishedAt: $now,
                    actor: $actor,
                    now: $now,
                );
                $typeVersion = $this->typeVersion(
                    $publishedRevision->typeKey,
                    $publishedRevision->typeVersion,
                    true,
                );

                try {
                    $projection = $this->projections->build(
                        $typeVersion,
                        $after,
                        $publishedRevision,
                        $this->attachmentReferences($publishedRevision, $placements),
                        true,
                    );
                    $searchProjection = ContentSearchProjection::fromPublished($projection);
                } catch (\InvalidArgumentException $exception) {
                    throw new ContentRejected(
                        'publication_projection_invalid',
                        'The current revision cannot produce its exact public projection.',
                        $exception,
                    );
                }

                $this->assertSearchWriteResult(
                    $this->writeSearchProjection($searchProjection),
                    $itemRef,
                    $after->currentRevision,
                    'indexed',
                );
                $this->audit->emit(
                    'content.item.published',
                    $actor,
                    $itemRef->value,
                    ContentAuditPayload::from([
                        'operation' => 'content.item.publish',
                        'type_key' => $before->typeKey->value,
                        'item_ref' => $itemRef->value,
                        'expected_revision' => $expectedRevision,
                        'new_revision' => $after->currentRevision,
                        'status' => $after->currentStatus->value,
                        'visibility' => $after->currentVisibility->value,
                        'attachment_count' => count($placements),
                    ]),
                );

                return $after;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.item.publish',
                $exception,
                $itemRef->value,
                [
                    'item_ref' => $itemRef->value,
                    'expected_revision' => max(0, $expectedRevision),
                ],
            );

            throw $exception;
        }
    }

    public function unpublish(
        ContentItemRef $itemRef,
        int $expectedRevision,
        ActorContext $actor,
    ): ContentItem {
        $connection = $this->preflightProtected($actor, 'content.item.unpublish');

        try {
            $this->assertExpectedRevision($expectedRevision);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $itemRef,
                $expectedRevision,
                $actor,
            ): ContentItem {
                [$before, $current] = $this->lockedCurrent($itemRef, $expectedRevision);
                if (!$before->hasPublishedRevision()) {
                    throw new ContentRejected('item_not_published');
                }
                $this->lockAndAssertRoutesAvailable(
                    $before->typeKey,
                    $before->locale,
                    $this->routeSlugs($before, $before->currentSlug),
                    $itemRef,
                );
                $placements = $this->attachmentPlacementsForRevision($current, true);
                $now = $this->clock->now();
                [$after] = $this->persistNextRevision(
                    before: $before,
                    storageRef: $this->storageRef($current),
                    storageSchema: $this->storageSchemaRef($current),
                    typeVersion: $current->typeVersion,
                    slug: $before->currentSlug,
                    status: ContentStatus::Draft,
                    visibility: $before->currentVisibility,
                    attachments: $placements,
                    publishedRevision: null,
                    publishedSlug: null,
                    publishedAt: null,
                    actor: $actor,
                    now: $now,
                );
                $this->assertSearchWriteResult(
                    $this->removeSearchProjection($itemRef, $after->currentRevision),
                    $itemRef,
                    $after->currentRevision,
                    'removed',
                );
                $this->audit->emit(
                    'content.item.unpublished',
                    $actor,
                    $itemRef->value,
                    ContentAuditPayload::from([
                        'operation' => 'content.item.unpublish',
                        'type_key' => $before->typeKey->value,
                        'item_ref' => $itemRef->value,
                        'expected_revision' => $expectedRevision,
                        'new_revision' => $after->currentRevision,
                        'status' => $after->currentStatus->value,
                        'visibility' => $after->currentVisibility->value,
                        'attachment_count' => count($placements),
                    ]),
                );

                return $after;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.item.unpublish',
                $exception,
                $itemRef->value,
                [
                    'item_ref' => $itemRef->value,
                    'expected_revision' => max(0, $expectedRevision),
                ],
            );

            throw $exception;
        }
    }

    /**
     * @param callable(list<ContentAttachmentPlacement>):list<ContentAttachmentPlacement> $transform
     */
    private function changeAttachmentManifest(
        ContentItemRef $itemRef,
        int $expectedRevision,
        ActorContext $actor,
        string $operation,
        string $eventType,
        ?string $logicalFileRef,
        ?string $role,
        callable $transform,
    ): ContentItem {
        $connection = $this->preflightProtected($actor, $operation);

        try {
            $this->assertExpectedRevision($expectedRevision);
            if ($logicalFileRef !== null) {
                $this->input->assertLogicalFileRef($logicalFileRef);
            }
            if ($role !== null) {
                $this->assertAttachmentRole($role);
            }
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $itemRef,
                $expectedRevision,
                $actor,
                $operation,
                $eventType,
                $logicalFileRef,
                $transform,
            ): ContentItem {
                [$before, $current] = $this->lockedCurrent($itemRef, $expectedRevision);
                $this->lockAndAssertRoutesAvailable(
                    $before->typeKey,
                    $before->locale,
                    $this->routeSlugs($before, $before->currentSlug),
                    $itemRef,
                );
                $placements = $transform(
                    $this->attachmentPlacementsForRevision($current, true),
                );
                $this->input->assertAttachmentManifest($placements);
                $now = $this->clock->now();
                [$after] = $this->persistNextRevision(
                    before: $before,
                    storageRef: $this->storageRef($current),
                    storageSchema: $this->storageSchemaRef($current),
                    typeVersion: $current->typeVersion,
                    slug: $before->currentSlug,
                    status: ContentStatus::Draft,
                    visibility: $before->currentVisibility,
                    attachments: $placements,
                    publishedRevision: $before->publishedRevision,
                    publishedSlug: $before->publishedSlug,
                    publishedAt: $before->publishedAt,
                    actor: $actor,
                    now: $now,
                );
                $payload = [
                    'operation' => $operation,
                    'item_ref' => $itemRef->value,
                    'expected_revision' => $expectedRevision,
                    'new_revision' => $after->currentRevision,
                    'attachment_count' => count($placements),
                ];
                if ($logicalFileRef !== null) {
                    $payload['logical_file_ref'] = $logicalFileRef;
                }
                $this->audit->emit(
                    $eventType,
                    $actor,
                    $itemRef->value,
                    ContentAuditPayload::from($payload),
                );

                return $after;
            }, 3);
        } catch (ContentRejected $exception) {
            $context = [
                'item_ref' => $itemRef->value,
                'expected_revision' => max(0, $expectedRevision),
            ];
            $auditLogicalFileRef = $this->auditLogicalFileRef($logicalFileRef);
            if ($auditLogicalFileRef !== null) {
                $context['logical_file_ref'] = $auditLogicalFileRef;
            }
            $this->auditDomainDenial(
                $connection,
                $actor,
                $operation,
                $exception,
                $itemRef->value,
                $context,
            );

            throw $exception;
        }
    }

    /**
     * @return array{ContentItem, ContentRevision}
     */
    private function lockedCurrent(
        ContentItemRef $itemRef,
        int $expectedRevision,
    ): array {
        $row = $this->repository->itemRow($itemRef->value, true);
        if ($row === null) {
            throw new ContentRejected('item_not_found');
        }
        $item = $this->hydrateItem($row);
        if ($item->currentRevision !== $expectedRevision) {
            throw new ContentConflict($expectedRevision, $item->currentRevision);
        }
        $revisionRow = $this->repository->revisionRow(
            $itemRef->value,
            $expectedRevision,
            true,
        );
        if ($revisionRow === null) {
            throw new ContentIntegrationFailed('content', 'current_revision_missing');
        }

        $revision = $this->hydrateRevision($revisionRow);
        $this->assertRevisionBelongsToItem($revision, $item);

        return [$item, $revision];
    }

    /**
     * @param list<ContentAttachmentPlacement> $attachments
     * @return array{ContentItem, ContentRevision}
     */
    private function persistNextRevision(
        ContentItem $before,
        StorageRecordVersionRef $storageRef,
        StorageSchemaVersionRef $storageSchema,
        int $typeVersion,
        ContentSlug $slug,
        ContentStatus $status,
        ContentVisibility $visibility,
        array $attachments,
        ?int $publishedRevision,
        ?ContentSlug $publishedSlug,
        ?DateTimeImmutable $publishedAt,
        ActorContext $actor,
        DateTimeImmutable $now,
    ): array {
        $this->input->assertMutableRevision($before->currentRevision);
        if ($storageRef->schemaId !== $storageSchema->schemaId) {
            throw new ContentIntegrationFailed(
                'storage',
                'record_schema_result_mismatch',
            );
        }
        $next = $before->currentRevision + 1;
        $revision = new ContentRevision(
            itemRef: $before->itemRef,
            revision: $next,
            typeKey: $before->typeKey,
            locale: $before->locale,
            typeVersion: $typeVersion,
            storageSchemaRef: $storageSchema->schemaId,
            storageSchemaVersion: $storageSchema->version,
            storageRecordRef: $storageRef->recordId,
            storageRecordVersion: $storageRef->revision,
            slug: $slug,
            status: $status,
            visibility: $visibility,
            attachmentCount: count($attachments),
            createdBy: $actor->actorRef,
            correlationId: $actor->correlationId,
            createdAt: $now,
        );
        $after = new ContentItem(
            itemRef: $before->itemRef,
            typeKey: $before->typeKey,
            locale: $before->locale,
            currentRevision: $next,
            currentSlug: $slug,
            currentStatus: $status,
            currentVisibility: $visibility,
            publishedRevision: $publishedRevision,
            publishedSlug: $publishedSlug,
            publishedAt: $publishedAt,
        );
        $timestamp = $this->timestamp($now);

        $this->repository->appendRevision(
            $this->revisionRow($revision),
            $this->placementRows($attachments),
        );
        if (!$this->repository->compareAndSwapItemHead(
            $before->itemRef->value,
            $before->currentRevision,
            $this->itemHeadMutationRow($after, $timestamp),
        )) {
            throw new ContentConflict($before->currentRevision, $before->currentRevision + 1);
        }
        $this->synchronizeRoutes($before, $after, $timestamp);

        return [$after, $revision];
    }

    /**
     * @param list<ContentSlug> $slugs
     */
    private function lockAndAssertRoutesAvailable(
        ContentTypeKey $typeKey,
        ContentLocale $locale,
        array $slugs,
        ContentItemRef $itemRef,
    ): void {
        $identities = array_map(
            static fn (ContentSlug $slug): array => [
                'type_key' => $typeKey->value,
                'locale' => $locale->value,
                'slug' => $slug->value,
            ],
            $slugs,
        );
        $this->repository->lockRouteRows($identities);

        foreach ($slugs as $slug) {
            $row = $this->repository->routeRow(
                $typeKey->value,
                $locale->value,
                $slug->value,
            );
            if ($row !== null && (string) $row['item_ref'] !== $itemRef->value) {
                throw new ContentRejected('slug_conflict');
            }
        }
    }

    /**
     * @return list<ContentSlug>
     */
    private function routeSlugs(ContentItem $item, ContentSlug $candidate): array
    {
        $byValue = [
            $item->currentSlug->value => $item->currentSlug,
            $candidate->value => $candidate,
        ];
        if ($item->publishedSlug !== null) {
            $byValue[$item->publishedSlug->value] = $item->publishedSlug;
        }
        ksort($byValue, SORT_STRING);

        return array_values($byValue);
    }

    private function synchronizeRoutes(
        ?ContentItem $before,
        ContentItem $after,
        string $timestamp,
    ): void {
        $slugs = [$after->currentSlug->value => $after->currentSlug];
        foreach ([$before?->currentSlug, $before?->publishedSlug, $after->publishedSlug] as $slug) {
            if ($slug instanceof ContentSlug) {
                $slugs[$slug->value] = $slug;
            }
        }
        ksort($slugs, SORT_STRING);

        foreach ($slugs as $slug) {
            $this->repository->setRoute([
                'type_key' => $after->typeKey->value,
                'locale' => $after->locale->value,
                'slug' => $slug->value,
                'item_ref' => $after->itemRef->value,
                'current_revision' => $after->currentSlug->value === $slug->value
                    ? $after->currentRevision
                    : null,
                'published_revision' => $after->publishedSlug?->value === $slug->value
                    ? $after->publishedRevision
                    : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateItem(array $row): ContentItem
    {
        try {
            return new ContentItem(
                itemRef: new ContentItemRef((string) $row['item_ref']),
                typeKey: new ContentTypeKey((string) $row['type_key']),
                locale: new ContentLocale((string) $row['locale']),
                currentRevision: (int) $row['current_revision'],
                currentSlug: new ContentSlug((string) $row['current_slug']),
                currentStatus: ContentStatus::from((string) $row['current_status']),
                currentVisibility: ContentVisibility::from((string) $row['current_visibility']),
                publishedRevision: $row['published_revision'] === null
                    ? null
                    : (int) $row['published_revision'],
                publishedSlug: $row['published_slug'] === null
                    ? null
                    : new ContentSlug((string) $row['published_slug']),
                publishedAt: $row['published_at'] === null
                    ? null
                    : $this->dateTime((string) $row['published_at']),
            );
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_item_invalid',
                $exception,
            );
        }
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateRevision(array $row): ContentRevision
    {
        try {
            return new ContentRevision(
                itemRef: new ContentItemRef((string) $row['item_ref']),
                revision: (int) $row['revision'],
                typeKey: new ContentTypeKey((string) $row['type_key']),
                locale: new ContentLocale((string) $row['locale']),
                typeVersion: (int) $row['type_version'],
                storageSchemaRef: (string) $row['storage_schema_ref'],
                storageSchemaVersion: (int) $row['storage_schema_version'],
                storageRecordRef: (string) $row['storage_record_ref'],
                storageRecordVersion: (int) $row['storage_record_version'],
                slug: new ContentSlug((string) $row['slug']),
                status: ContentStatus::from((string) $row['status']),
                visibility: ContentVisibility::from((string) $row['visibility']),
                attachmentCount: (int) $row['attachment_count'],
                createdBy: (string) $row['created_by'],
                correlationId: (string) $row['correlation_id'],
                createdAt: $this->dateTime((string) $row['created_at']),
            );
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_revision_invalid',
                $exception,
            );
        }
    }

    private function typeVersion(
        ContentTypeKey $typeKey,
        int $version,
        bool $forUpdate = false,
    ): ContentTypeVersion {
        $row = $this->repository->typeVersionRow(
            $typeKey->value,
            $version,
            $forUpdate,
        );
        if ($row === null) {
            throw new ContentIntegrationFailed('content', 'type_version_missing');
        }
        try {
            $storageSchema = $this->storage->schemaVersion(new StorageSchemaVersionRef(
                (string) $row['storage_schema_ref'],
                (int) $row['storage_schema_version'],
            ), $forUpdate);
            if (!hash_equals((string) $row['schema_hash'], $storageSchema->definitionHash)) {
                throw new ContentIntegrationFailed(
                    'content',
                    'type_version_storage_hash_mismatch',
                );
            }
            $fields = $this->schemas->fieldDefinitions($storageSchema);
            $projection = ContentProjectionContract::fromArray(
                $this->decodeObject((string) $row['projection_contract']),
                $fields,
            );

            return new ContentTypeVersion(
                typeKey: $typeKey,
                version: (int) $row['version'],
                storageSchemaRef: (string) $row['storage_schema_ref'],
                storageSchemaVersion: (int) $row['storage_schema_version'],
                schemaHash: (string) $row['schema_hash'],
                fieldDefinitions: $fields,
                projectionContract: $projection,
                safeMetadata: $this->decodeObject((string) $row['safe_metadata']),
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
     * @param list<array<string, bool|int|string|null>> $rows
     * @return list<ContentAttachmentReference>
     */
    private function hydrateAttachments(
        ContentItemRef $itemRef,
        int $revision,
        array $rows,
    ): array {
        try {
            return array_map(
                static fn (array $row): ContentAttachmentReference => new ContentAttachmentReference(
                    itemRef: $itemRef,
                    revision: $revision,
                    position: (int) $row['position'],
                    logicalFileRef: (string) $row['logical_file_ref'],
                    role: (string) $row['role'],
                ),
                $rows,
            );
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_attachment_invalid',
                $exception,
            );
        }
    }

    /**
     * @return list<ContentAttachmentReference>
     */
    private function attachmentReferencesForRevision(
        ContentRevision $revision,
        bool $forUpdate = false,
    ): array {
        $attachments = $this->hydrateAttachments(
            $revision->itemRef,
            $revision->revision,
            $this->repository->attachmentRows(
                $revision->itemRef->value,
                $revision->revision,
                $forUpdate,
            ),
        );
        PublishedContentProjectionBuilder::assertExactAttachmentManifest(
            $revision,
            $attachments,
        );

        return $attachments;
    }

    /**
     * @return list<ContentAttachmentPlacement>
     */
    private function attachmentPlacementsForRevision(
        ContentRevision $revision,
        bool $forUpdate = false,
    ): array
    {
        return array_map(
            static fn (
                ContentAttachmentReference $attachment,
            ): ContentAttachmentPlacement => $attachment->placement(),
            $this->attachmentReferencesForRevision($revision, $forUpdate),
        );
    }

    /**
     * @param list<ContentAttachmentPlacement> $placements
     * @return list<ContentAttachmentReference>
     */
    private function attachmentReferences(
        ContentRevision $revision,
        array $placements,
    ): array {
        return array_map(
            static fn (ContentAttachmentPlacement $placement): ContentAttachmentReference => new ContentAttachmentReference(
                itemRef: $revision->itemRef,
                revision: $revision->revision,
                position: $placement->position,
                logicalFileRef: $placement->logicalFileRef,
                role: $placement->role,
            ),
            $placements,
        );
    }

    /**
     * @param list<ContentAttachmentPlacement> $placements
     * @return list<array{logical_file_ref: string, role: string, position: int}>
     */
    private function placementRows(array $placements): array
    {
        return array_map(
            static fn (ContentAttachmentPlacement $placement): array => [
                'logical_file_ref' => $placement->logicalFileRef,
                'role' => $placement->role,
                'position' => $placement->position,
            ],
            $placements,
        );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function itemHeadRow(
        ContentItem $item,
        string $createdAt,
        string $updatedAt,
    ): array {
        return [
            'item_ref' => $item->itemRef->value,
            'type_key' => $item->typeKey->value,
            'locale' => $item->locale->value,
            'current_revision' => $item->currentRevision,
            'current_slug' => $item->currentSlug->value,
            'current_status' => $item->currentStatus->value,
            'current_visibility' => $item->currentVisibility->value,
            'published_revision' => $item->publishedRevision,
            'published_slug' => $item->publishedSlug?->value,
            'published_at' => $item->publishedAt === null
                ? null
                : $this->timestamp($item->publishedAt),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function itemHeadMutationRow(ContentItem $item, string $updatedAt): array
    {
        $row = $this->itemHeadRow($item, $updatedAt, $updatedAt);
        unset($row['item_ref'], $row['type_key'], $row['locale'], $row['created_at']);

        return $row;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function revisionRow(ContentRevision $revision): array
    {
        return [
            'item_ref' => $revision->itemRef->value,
            'revision' => $revision->revision,
            'type_key' => $revision->typeKey->value,
            'locale' => $revision->locale->value,
            'type_version' => $revision->typeVersion,
            'storage_schema_ref' => $revision->storageSchemaRef,
            'storage_schema_version' => $revision->storageSchemaVersion,
            'storage_record_ref' => $revision->storageRecordRef,
            'storage_record_version' => $revision->storageRecordVersion,
            'slug' => $revision->slug->value,
            'status' => $revision->status->value,
            'visibility' => $revision->visibility->value,
            'attachment_count' => $revision->attachmentCount,
            'created_by' => $revision->createdBy,
            'correlation_id' => $revision->correlationId,
            'created_at' => $this->timestamp($revision->createdAt),
        ];
    }

    private function storageRef(ContentRevision $revision): StorageRecordVersionRef
    {
        return new StorageRecordVersionRef(
            $revision->storageSchemaRef,
            $revision->storageRecordRef,
            $revision->storageRecordVersion,
        );
    }

    private function storageSchemaRef(
        ContentRevision $revision,
    ): StorageSchemaVersionRef {
        return new StorageSchemaVersionRef(
            $revision->storageSchemaRef,
            $revision->storageSchemaVersion,
        );
    }

    private function assertRevisionSchemaMatchesTypeVersion(
        ContentRevision $revision,
        ContentTypeVersion $typeVersion,
    ): void {
        if (
            $revision->typeKey->value !== $typeVersion->typeKey->value
            || $revision->typeVersion !== $typeVersion->version
            || $revision->storageSchemaRef !== $typeVersion->storageSchemaRef
            || $revision->storageSchemaVersion !== $typeVersion->storageSchemaVersion
        ) {
            throw new ContentIntegrationFailed(
                'content',
                'revision_schema_reference_mismatch',
            );
        }
    }

    private function assertRevisionBelongsToItem(
        ContentRevision $revision,
        ContentItem $item,
    ): void {
        if (
            $revision->itemRef->value !== $item->itemRef->value
            || $revision->typeKey->value !== $item->typeKey->value
            || $revision->locale->value !== $item->locale->value
        ) {
            throw new ContentIntegrationFailed(
                'content',
                'revision_item_identity_mismatch',
            );
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

    private function assertExpectedRevision(int $revision): void
    {
        $this->input->assertMutableRevision($revision);
        if ($revision < 1) {
            throw new ContentRejected('revision_invalid');
        }
    }

    private function auditLogicalFileRef(?string $logicalFileRef): ?string
    {
        if ($logicalFileRef === null) {
            return null;
        }

        try {
            ContentAttachmentPlacement::assertLogicalFileRef($logicalFileRef);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $logicalFileRef;
    }

    private function writeSearchProjection(
        ContentSearchProjection $projection,
    ): SearchWriteResult {
        try {
            return $this->search->upsert($projection->toSearchProjection());
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'search',
                'projection_write_failed',
                $exception,
            );
        }
    }

    private function removeSearchProjection(
        ContentItemRef $itemRef,
        int $revision,
    ): SearchWriteResult {
        try {
            return $this->search->remove(
                ContentSearchProjection::PROVIDER_ID,
                $itemRef->value,
                $revision,
            );
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'search',
                'projection_remove_failed',
                $exception,
            );
        }
    }

    private function assertSearchWriteResult(
        SearchWriteResult $result,
        ContentItemRef $itemRef,
        int $revision,
        string $expectedStatus,
    ): void {
        if (
            !$result->changed
            || $result->status !== $expectedStatus
            || $result->providerId !== ContentSearchProjection::PROVIDER_ID
            || $result->sourceRef !== $itemRef->value
            || $result->sourceRevision !== $revision
        ) {
            throw new ContentIntegrationFailed(
                'search',
                'write_result_mismatch',
            );
        }
    }

    private function assertAttachmentRole(string $role): void
    {
        try {
            ContentAttachmentPlacement::assertRole($role);
        } catch (\InvalidArgumentException $exception) {
            throw new ContentRejected(
                'attachment_role_invalid',
                'The Content attachment role is invalid.',
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
            throw new ContentIntegrationFailed('content', 'persisted_json_invalid', $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ContentIntegrationFailed('content', 'persisted_json_invalid');
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
            throw new ContentIntegrationFailed('content', 'persisted_timestamp_invalid', $exception);
        }
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
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

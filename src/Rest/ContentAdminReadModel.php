<?php

declare(strict_types=1);

namespace Larena\Content\Rest;

use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentAttachmentReference;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentRevision;
use Larena\Storage\Contracts\StorageRecordVersionRef;

final readonly class ContentAdminReadModel
{
    public function __construct(
        private ContentTypeService $types,
        private ContentItemService $items,
        private ContentStorageGateway $storage,
        private ContentAdminValueCodec $codec,
    ) {
    }

    /** @return array<string, mixed> */
    public function item(ContentItem $item, ActorContext $actor): array
    {
        $revision = $this->items->revision(
            $item->itemRef,
            $item->currentRevision,
            $actor,
        );
        if (
            $revision->itemRef->value !== $item->itemRef->value
            || $revision->typeKey->value !== $item->typeKey->value
            || $revision->locale->value !== $item->locale->value
            || $revision->revision !== $item->currentRevision
            || $revision->slug->value !== $item->currentSlug->value
            || $revision->status !== $item->currentStatus
            || $revision->visibility !== $item->currentVisibility
        ) {
            throw new ContentIntegrationFailed(
                'content',
                'content_admin_item_head_revision_mismatch',
            );
        }

        return [
            ...$this->itemSummary($item),
            'revision' => $this->revision($revision, $actor),
        ];
    }

    /** @return array<string, mixed> */
    public function itemSummary(ContentItem $item): array
    {
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
            'published_at' => $item->publishedAt?->format('Y-m-d\TH:i:s.u\Z'),
            'has_unpublished_changes' => $item->publishedRevision !== $item->currentRevision,
        ];
    }

    /** @return array<string, mixed> */
    public function revision(ContentRevision $revision, ActorContext $actor): array
    {
        $type = $this->types->version(
            $revision->typeKey,
            $revision->typeVersion,
            $actor,
        );
        if (
            $type->typeKey->value !== $revision->typeKey->value
            || $type->version !== $revision->typeVersion
            || $type->storageSchemaRef !== $revision->storageSchemaRef
            || $type->storageSchemaVersion !== $revision->storageSchemaVersion
        ) {
            throw new ContentIntegrationFailed(
                'content',
                'content_admin_revision_type_schema_mismatch',
            );
        }

        $stored = $this->storage->readAdminVersion(
            new StorageRecordVersionRef(
                $revision->storageSchemaRef,
                $revision->storageRecordRef,
                $revision->storageRecordVersion,
            ),
            $actor,
        );

        if (
            $stored->schema->schemaId !== $revision->storageSchemaRef
            || $stored->schema->version !== $revision->storageSchemaVersion
            || $stored->ownerRef !== $revision->itemRef->value
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'content_admin_revision_storage_mismatch',
            );
        }

        return [
            ...$this->revisionSummary($revision),
            'values' => $this->codec->encodeValues($stored->values, $type),
        ];
    }

    /** @return array<string, mixed> */
    public function revisionSummary(ContentRevision $revision): array
    {
        return [
            'item_ref' => $revision->itemRef->value,
            'revision' => $revision->revision,
            'type_key' => $revision->typeKey->value,
            'locale' => $revision->locale->value,
            'type_version' => $revision->typeVersion,
            'slug' => $revision->slug->value,
            'status' => $revision->status->value,
            'visibility' => $revision->visibility->value,
            'attachment_count' => $revision->attachmentCount,
            'created_by' => $revision->createdBy,
            'created_at' => $revision->createdAt->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /** @return array<string, mixed> */
    public function attachment(ContentAttachmentReference $attachment): array
    {
        return [
            'logical_file_ref' => $attachment->logicalFileRef,
            'role' => $attachment->role,
            'position' => $attachment->position,
        ];
    }
}

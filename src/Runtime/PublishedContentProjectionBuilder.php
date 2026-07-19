<?php

declare(strict_types=1);

namespace Larena\Content\Runtime;

use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ContentAttachmentReference;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentRevision;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Content\ValueObjects\PublicContentAttachment;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Storage\Contracts\StorageRecordVersionRef;

final readonly class PublishedContentProjectionBuilder
{
    public function __construct(
        private ContentStorageGateway $storage,
        private ContentLogicalFileInspector $files,
    ) {
    }

    /**
     * @param array<array-key, mixed> $attachments
     */
    public function build(
        ContentTypeVersion $typeVersion,
        ContentItem $item,
        ContentRevision $revision,
        array $attachments,
    ): PublishedContentProjection {
        self::assertExactAttachmentManifest($revision, $attachments);
        $publicAttachments = [];

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof ContentAttachmentReference) {
                throw new \InvalidArgumentException(
                    'Published Content attachment inputs must be exact revision references.',
                );
            }

            $inspection = $this->files->inspect($attachment->logicalFileRef);

            if (!$inspection->isPubliclyProjectable()) {
                continue;
            }

            $publicAttachments[] = PublicContentAttachment::fromInspection(
                reference: $attachment,
                inspection: $inspection,
                publicPosition: count($publicAttachments),
            );
        }

        return PublishedContentProjection::fromPublishedRevision(
            typeVersion: $typeVersion,
            item: $item,
            revision: $revision,
            storageProjection: $this->storage->publicProjection(new StorageRecordVersionRef(
                schemaId: $revision->storageSchemaRef,
                recordId: $revision->storageRecordRef,
                revision: $revision->storageRecordVersion,
            )),
            publicAttachments: $publicAttachments,
        );
    }

    /**
     * Validates the immutable source manifest before any unavailable or
     * private attachment can be filtered from the public projection.
     *
     * @param array<array-key, mixed> $attachments
     */
    public static function assertExactAttachmentManifest(
        ContentRevision $revision,
        array $attachments,
    ): void {
        if (
            !array_is_list($attachments)
            || count($attachments) !== $revision->attachmentCount
        ) {
            throw new ContentIntegrationFailed(
                'content',
                'attachment_manifest_mismatch',
            );
        }

        $identities = [];

        foreach ($attachments as $position => $attachment) {
            if (
                !$attachment instanceof ContentAttachmentReference
                || $attachment->itemRef->value !== $revision->itemRef->value
                || $attachment->revision !== $revision->revision
                || $attachment->position !== $position
            ) {
                throw new ContentIntegrationFailed(
                    'content',
                    'attachment_manifest_mismatch',
                );
            }

            $identity = $attachment->logicalFileRef . "\0" . $attachment->role;

            if (isset($identities[$identity])) {
                throw new ContentIntegrationFailed(
                    'content',
                    'attachment_manifest_mismatch',
                );
            }

            $identities[$identity] = true;
        }
    }
}

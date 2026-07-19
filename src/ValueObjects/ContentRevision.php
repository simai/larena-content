<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use DateTimeImmutable;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;

final readonly class ContentRevision
{
    public const int MAX_ATTACHMENTS = 100;

    public function __construct(
        public ContentItemRef $itemRef,
        public int $revision,
        public ContentTypeKey $typeKey,
        public ContentLocale $locale,
        public int $typeVersion,
        public string $storageSchemaRef,
        public int $storageSchemaVersion,
        public string $storageRecordRef,
        public int $storageRecordVersion,
        public ContentSlug $slug,
        public ContentStatus $status,
        public ContentVisibility $visibility,
        public int $attachmentCount,
        public string $createdBy,
        public string $correlationId,
        public DateTimeImmutable $createdAt,
    ) {
        if (
            $revision < 1
            || $typeVersion < 1
            || $storageSchemaVersion < 1
            || $storageRecordVersion < 1
        ) {
            throw new \InvalidArgumentException('Content and Storage revision references must be positive.');
        }

        if ($storageSchemaRef !== $typeKey->storageSchemaRef()) {
            throw new \InvalidArgumentException('A Content revision must reference its type-derived Storage schema.');
        }

        if (preg_match('/\Arecord-[a-f0-9]{32}\z/D', $storageRecordRef) !== 1) {
            throw new \InvalidArgumentException(
                'A Content revision must reference an exact canonical Storage record id.',
            );
        }
        self::assertSafeReference($createdBy, 'creator reference');
        self::assertSafeReference($correlationId, 'correlation id');

        if ($attachmentCount < 0 || $attachmentCount > self::MAX_ATTACHMENTS) {
            throw new \InvalidArgumentException(sprintf(
                'Content attachment counts must be between 0 and %d.',
                self::MAX_ATTACHMENTS,
            ));
        }
    }

    private static function assertSafeReference(string $value, string $label): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('Invalid %s.', $label));
        }
    }
}

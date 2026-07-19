<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use DateTimeImmutable;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;

final readonly class ContentItem
{
    public function __construct(
        public ContentItemRef $itemRef,
        public ContentTypeKey $typeKey,
        public ContentLocale $locale,
        public int $currentRevision,
        public ContentSlug $currentSlug,
        public ContentStatus $currentStatus,
        public ContentVisibility $currentVisibility,
        public ?int $publishedRevision,
        public ?ContentSlug $publishedSlug,
        public ?DateTimeImmutable $publishedAt,
    ) {
        if ($currentRevision < 1) {
            throw new \InvalidArgumentException('A Content item current revision must be positive.');
        }

        $publishedValues = [
            $publishedRevision !== null,
            $publishedSlug !== null,
            $publishedAt !== null,
        ];

        if (count(array_unique($publishedValues, SORT_REGULAR)) !== 1) {
            throw new \InvalidArgumentException(
                'Published revision, slug and timestamp must either all be present or all be absent.',
            );
        }

        if ($publishedRevision !== null && ($publishedRevision < 1 || $publishedRevision > $currentRevision)) {
            throw new \InvalidArgumentException('The published revision must reference an existing item revision.');
        }

        if (
            $currentStatus === ContentStatus::Published
            && (
                $publishedRevision !== $currentRevision
                || $publishedSlug?->value !== $currentSlug->value
            )
        ) {
            throw new \InvalidArgumentException(
                'A published Content head must point to its exact current revision and slug.',
            );
        }

        if (
            $currentStatus === ContentStatus::Draft
            && $publishedRevision === $currentRevision
        ) {
            throw new \InvalidArgumentException(
                'A draft Content head cannot also be the exact published revision.',
            );
        }
    }

    public function hasPublishedRevision(): bool
    {
        return $this->publishedRevision !== null;
    }
}

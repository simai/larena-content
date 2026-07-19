<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentRevisionQuery
{
    public function __construct(
        public ContentItemRef $itemRef,
        public ?int $afterRevision = null,
        public int $limit = 50,
    ) {
        if ($afterRevision !== null && $afterRevision < 1) {
            throw new \InvalidArgumentException('The Content revision cursor must be positive.');
        }

        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Content revision query limits must be between 1 and 100.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentTypeVersionQuery
{
    public function __construct(
        public ContentTypeKey $typeKey,
        public ?int $afterVersion = null,
        public int $limit = 50,
    ) {
        if ($afterVersion !== null && $afterVersion < 1) {
            throw new \InvalidArgumentException('The Content type-version cursor must be positive.');
        }

        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Content type-version query limits must be between 1 and 100.');
        }
    }
}

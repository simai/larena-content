<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentTypeQuery
{
    public function __construct(
        public ?ContentTypeKey $afterTypeKey = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Content type query limits must be between 1 and 100.');
        }
    }
}

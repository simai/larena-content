<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;

final readonly class ContentItemQuery
{
    public function __construct(
        public ?ContentTypeKey $typeKey = null,
        public ?ContentLocale $locale = null,
        public ?ContentStatus $status = null,
        public ?ContentVisibility $visibility = null,
        public ?ContentItemRef $afterItemRef = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Content item query limits must be between 1 and 100.');
        }
    }
}

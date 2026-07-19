<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentType
{
    public function __construct(
        public ContentTypeKey $typeKey,
        public int $currentVersion,
    ) {
        if ($currentVersion < 1) {
            throw new \InvalidArgumentException('A Content type current version must be positive.');
        }
    }
}

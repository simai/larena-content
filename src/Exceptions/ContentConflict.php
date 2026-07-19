<?php

declare(strict_types=1);

namespace Larena\Content\Exceptions;

final class ContentConflict extends ContentRejected
{
    public function __construct(
        public readonly int $expectedRevision,
        public readonly int $currentRevision,
        string $reasonCode = 'stale_revision',
    ) {
        if ($expectedRevision < 0 || $currentRevision < 0) {
            throw new \InvalidArgumentException('Content revision numbers cannot be negative.');
        }

        parent::__construct(
            $reasonCode,
            sprintf(
                'Content revision conflict: expected revision %d, current revision %d.',
                $expectedRevision,
                $currentRevision,
            ),
        );
    }
}

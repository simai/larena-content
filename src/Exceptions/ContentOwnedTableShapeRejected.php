<?php

declare(strict_types=1);

namespace Larena\Content\Exceptions;

use RuntimeException;

final class ContentOwnedTableShapeRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $tableKey = 'topology',
    ) {
        parent::__construct($reasonCode);
    }
}

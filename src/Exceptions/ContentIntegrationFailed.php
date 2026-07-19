<?php

declare(strict_types=1);

namespace Larena\Content\Exceptions;

use RuntimeException;
use Throwable;

final class ContentIntegrationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $integration,
        public readonly string $reasonCode,
        ?Throwable $previous = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $integration) !== 1) {
            throw new \InvalidArgumentException('Integration identifiers must be stable lowercase identifiers.');
        }

        if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $reasonCode) !== 1) {
            throw new \InvalidArgumentException('Integration reason codes must be stable lowercase identifiers.');
        }

        parent::__construct(
            sprintf('Content integration "%s" failed with reason "%s".', $integration, $reasonCode),
            0,
            $previous,
        );
    }
}

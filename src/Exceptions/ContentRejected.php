<?php

declare(strict_types=1);

namespace Larena\Content\Exceptions;

use DomainException;
use Throwable;

class ContentRejected extends DomainException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message = 'The Content operation was rejected.',
        ?Throwable $previous = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $reasonCode) !== 1) {
            throw new \InvalidArgumentException('Content rejection reason codes must be stable lowercase identifiers.');
        }

        parent::__construct($message, 0, $previous);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}

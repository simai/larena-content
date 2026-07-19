<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Stringable;

final readonly class ContentLocale implements Stringable
{
    public function __construct(public string $value = 'en')
    {
        if (
            strlen($value) > 16
            || preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Content locales must be lowercase BCP 47-compatible identifiers of at most 16 characters.',
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

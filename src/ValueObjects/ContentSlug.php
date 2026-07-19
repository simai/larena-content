<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Stringable;

final readonly class ContentSlug implements Stringable
{
    public function __construct(public string $value)
    {
        if (
            strlen($value) > 160
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Content slugs must be lowercase ASCII identifiers of at most 160 characters.',
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

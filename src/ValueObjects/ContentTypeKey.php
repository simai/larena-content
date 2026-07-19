<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Stringable;

final readonly class ContentTypeKey implements Stringable
{
    public function __construct(public string $value)
    {
        if (
            strlen($value) > 64
            || preg_match('/\A[a-z][a-z0-9]*(?:[._][a-z0-9]+)*\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Content type keys must be lowercase dotted or underscored developer identifiers of at most 64 characters.',
            );
        }
    }

    public function storageSchemaRef(): string
    {
        return 'content.type.'.$this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Stringable;

final readonly class ContentItemRef implements Stringable
{
    public function __construct(public string $value)
    {
        if (
            strlen($value) > 64
            || preg_match(
                '/\Acontent:item:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',
                $value,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Content item references must use the canonical content:item:<uuid> form.',
            );
        }
    }

    public static function fromUuid(string $uuid): self
    {
        return new self('content:item:'.$uuid);
    }

    public function uuid(): string
    {
        return substr($this->value, strlen('content:item:'));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

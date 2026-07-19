<?php

declare(strict_types=1);

namespace Larena\Content\Runtime;

use JsonException;
use Larena\Content\Exceptions\ContentRejected;

final class ContentCanonicalJson
{
    public function encode(mixed $value): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new ContentRejected(
                'canonical_json_invalid',
                'Content data cannot be represented as canonical JSON.',
                $exception,
            );
        }
    }

    public function byteLength(mixed $value): int
    {
        return strlen($this->encode($value));
    }

    public function assertMaximumBytes(mixed $value, int $maximumBytes, string $reasonCode): void
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('A canonical JSON byte limit must be positive.');
        }

        if ($this->byteLength($value) > $maximumBytes) {
            throw new ContentRejected(
                $reasonCode,
                sprintf('Canonical Content JSON exceeds %d bytes.', $maximumBytes),
            );
        }
    }

    public function canonicalize(mixed $value): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            throw new ContentRejected(
                'canonical_json_invalid',
                'Canonical Content JSON cannot contain a non-finite number.',
            );
        }

        if (!is_array($value)) {
            if (
                $value === null
                || is_bool($value)
                || is_int($value)
                || is_float($value)
                || is_string($value)
            ) {
                return $value;
            }

            throw new ContentRejected(
                'canonical_json_invalid',
                'Canonical Content JSON accepts only JSON-compatible values.',
            );
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $entry): mixed => $this->canonicalize($entry),
                $value,
            );
        }

        $canonical = [];

        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new ContentRejected(
                    'canonical_json_invalid',
                    'Canonical Content JSON object keys must be strings.',
                );
            }

            $canonical[$key] = $this->canonicalize($entry);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}

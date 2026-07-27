<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Larena\Content\Enums\ContentFieldVisibility;

final readonly class ContentFieldDefinition
{
    public const int MAX_STRING_CODE_POINTS = 65_536;

    /** @var array<string, int|string> */
    public array $constraints;

    /**
     * @param array<array-key, mixed> $constraints
     */
    public function __construct(
        public string $key,
        public string $propertyType,
        public ContentFieldVisibility $visibility,
        public bool $required = false,
        array $constraints = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1) {
            throw new \InvalidArgumentException('Content field keys must be stable lowercase identifiers.');
        }

        if (!in_array($propertyType, ['string', 'text', 'number', 'integer', 'boolean', 'date', 'file', 'relation'], true)) {
            throw new \InvalidArgumentException('Content CMS v1 field type is not supported.');
        }

        $this->constraints = self::normalizeConstraints($propertyType, $constraints);
    }

    public function isPublic(): bool
    {
        return $this->visibility === ContentFieldVisibility::Public;
    }

    public function isPublicSearchScalar(): bool
    {
        return $this->isPublic()
            && in_array($this->propertyType, ['string', 'text', 'number', 'integer', 'boolean', 'date'], true);
    }

    public static function assertStringValueWithinFrozenBound(string $value): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Content string values must be valid UTF-8.');
        }

        $codePoints = preg_match_all('/./us', $value);

        if ($codePoints === false || $codePoints > self::MAX_STRING_CODE_POINTS) {
            throw new \InvalidArgumentException(sprintf(
                'Content string values may contain at most %d Unicode code points.',
                self::MAX_STRING_CODE_POINTS,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $constraints
     *
     * @return array<string, int|string>
     */
    private static function normalizeConstraints(string $propertyType, array $constraints): array
    {
        $allowedKeys = match ($propertyType) {
            'string', 'text' => ['min_length', 'max_length'],
            'integer' => ['min', 'max'],
            'number', 'date' => ['min', 'max'],
            'boolean', 'file', 'relation' => [],
            default => throw new \LogicException('Unsupported frozen Content field type.'),
        };

        if (array_is_list($constraints) && $constraints !== []) {
            throw new \InvalidArgumentException('Content field constraints must be a keyed map.');
        }

        $normalized = [];

        foreach ($constraints as $key => $value) {
            $validValue = match ($propertyType) {
                'string', 'text', 'integer' => is_int($value),
                'number' => is_int($value) || is_string($value),
                'date' => is_string($value),
                default => false,
            };
            if (!is_string($key) || !in_array($key, $allowedKeys, true) || !$validValue) {
                throw new \InvalidArgumentException(
                    'Content field constraints must match the frozen Property v1 contract.',
                );
            }

            $normalized[$key] = $value;
        }

        $lengthType = in_array($propertyType, ['string', 'text'], true);
        $minimumKey = $lengthType ? 'min_length' : 'min';
        $maximumKey = $lengthType ? 'max_length' : 'max';
        $minimum = $normalized[$minimumKey] ?? null;
        $maximum = $normalized[$maximumKey] ?? null;

        if (
            ($lengthType && (($minimum !== null && $minimum < 0) || ($maximum !== null && $maximum < 0)))
            || ($lengthType && $maximum !== null && $maximum > self::MAX_STRING_CODE_POINTS)
            || ($propertyType === 'integer' && $minimum !== null && $maximum !== null && $minimum > $maximum)
        ) {
            throw new \InvalidArgumentException('Content field constraint bounds are invalid.');
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}

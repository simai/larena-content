<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Larena\Content\Enums\ContentFieldVisibility;

final readonly class ContentFieldDefinition
{
    /** @var array<string, int> */
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

        if (!in_array($propertyType, ['string', 'integer', 'boolean'], true)) {
            throw new \InvalidArgumentException('Content Platform v1 supports only string, integer and boolean fields.');
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
            && in_array($this->propertyType, ['string', 'integer', 'boolean'], true);
    }

    /**
     * @param array<array-key, mixed> $constraints
     *
     * @return array<string, int>
     */
    private static function normalizeConstraints(string $propertyType, array $constraints): array
    {
        $allowedKeys = match ($propertyType) {
            'string' => ['min_length', 'max_length'],
            'integer' => ['min', 'max'],
            'boolean' => [],
            default => throw new \LogicException('Unsupported frozen Content field type.'),
        };

        if (array_is_list($constraints) && $constraints !== []) {
            throw new \InvalidArgumentException('Content field constraints must be a keyed map.');
        }

        $normalized = [];

        foreach ($constraints as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true) || !is_int($value)) {
                throw new \InvalidArgumentException(
                    'Content field constraints must match the frozen Property v1 contract.',
                );
            }

            $normalized[$key] = $value;
        }

        $minimumKey = $propertyType === 'string' ? 'min_length' : 'min';
        $maximumKey = $propertyType === 'string' ? 'max_length' : 'max';
        $minimum = $normalized[$minimumKey] ?? null;
        $maximum = $normalized[$maximumKey] ?? null;

        if (
            ($propertyType === 'string' && (($minimum !== null && $minimum < 0) || ($maximum !== null && $maximum < 0)))
            || ($minimum !== null && $maximum !== null && $minimum > $maximum)
        ) {
            throw new \InvalidArgumentException('Content field constraint bounds are invalid.');
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}

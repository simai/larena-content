<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentProjectionContract
{
    /** @var list<string> */
    public array $searchableFields;

    /** @var array<string, string> */
    private array $publicFieldTypes;

    /**
     * @var array<string, array{
     *     property_type: string,
     *     visibility: string,
     *     required: bool,
     *     constraints: array<string, int>
     * }>
     */
    private array $fieldSignatures;

    /**
     * @param array<array-key, mixed> $searchableFields
     * @param array<array-key, mixed> $fieldDefinitions
     */
    public function __construct(
        public int $version,
        public string $titleField,
        public ?string $snippetField,
        array $searchableFields,
        array $fieldDefinitions,
    ) {
        if ($version !== 1) {
            throw new \InvalidArgumentException('Content Platform v1 requires projection contract version 1.');
        }

        $fields = [];

        foreach ($fieldDefinitions as $definition) {
            if (!$definition instanceof ContentFieldDefinition) {
                throw new \InvalidArgumentException('Projection field definitions must be ContentFieldDefinition objects.');
            }

            if (isset($fields[$definition->key])) {
                throw new \InvalidArgumentException('Content field definitions must have unique keys.');
            }

            $fields[$definition->key] = $definition;
        }

        if ($fields === []) {
            throw new \InvalidArgumentException('A Content projection requires at least one field definition.');
        }

        $title = $fields[$titleField] ?? null;

        if (!$title instanceof ContentFieldDefinition || !$title->isPublic() || $title->propertyType !== 'string') {
            throw new \InvalidArgumentException('The title field must reference a public string field.');
        }

        if ($snippetField !== null) {
            $snippet = $fields[$snippetField] ?? null;

            if (
                !$snippet instanceof ContentFieldDefinition
                || !$snippet->isPublic()
                || $snippet->propertyType !== 'string'
            ) {
                throw new \InvalidArgumentException('The snippet field must reference a public string field or be null.');
            }
        }

        if ($searchableFields === [] || !array_is_list($searchableFields)) {
            throw new \InvalidArgumentException('Searchable fields must be a non-empty ordered list.');
        }

        $seen = [];
        $normalizedSearchableFields = [];

        foreach ($searchableFields as $fieldKey) {
            if (!is_string($fieldKey) || isset($seen[$fieldKey])) {
                throw new \InvalidArgumentException('Searchable fields must contain unique field keys.');
            }

            $definition = $fields[$fieldKey] ?? null;

            if (!$definition instanceof ContentFieldDefinition || !$definition->isPublicSearchScalar()) {
                throw new \InvalidArgumentException('Every searchable field must reference a public v1 scalar field.');
            }

            $seen[$fieldKey] = true;
            $normalizedSearchableFields[] = $fieldKey;
        }

        if (!isset($seen[$titleField])) {
            throw new \InvalidArgumentException('Searchable fields must include the title field.');
        }

        $this->searchableFields = $normalizedSearchableFields;
        $publicFieldTypes = [];
        $fieldSignatures = [];

        foreach ($fields as $fieldKey => $definition) {
            if ($definition->isPublic()) {
                $publicFieldTypes[$fieldKey] = $definition->propertyType;
            }

            $constraints = $definition->constraints;
            ksort($constraints, SORT_STRING);

            $fieldSignatures[$fieldKey] = [
                'property_type' => $definition->propertyType,
                'visibility' => $definition->visibility->value,
                'required' => $definition->required,
                'constraints' => $constraints,
            ];
        }

        $this->publicFieldTypes = $publicFieldTypes;
        $this->fieldSignatures = $fieldSignatures;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<array-key, mixed> $fieldDefinitions
     */
    public static function fromArray(array $payload, array $fieldDefinitions): self
    {
        $expectedKeys = ['version', 'title_field', 'snippet_field', 'searchable_fields'];
        $actualKeys = array_keys($payload);

        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException('Projection contract v1 accepts exactly four frozen keys.');
        }

        if (
            !is_int($payload['version'])
            || !is_string($payload['title_field'])
            || ($payload['snippet_field'] !== null && !is_string($payload['snippet_field']))
            || !is_array($payload['searchable_fields'])
        ) {
            throw new \InvalidArgumentException('Projection contract v1 contains invalid value types.');
        }

        return new self(
            version: $payload['version'],
            titleField: $payload['title_field'],
            snippetField: $payload['snippet_field'],
            searchableFields: $payload['searchable_fields'],
            fieldDefinitions: $fieldDefinitions,
        );
    }

    /**
     * @return array{
     *     version: int,
     *     title_field: string,
     *     snippet_field: ?string,
     *     searchable_fields: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'title_field' => $this->titleField,
            'snippet_field' => $this->snippetField,
            'searchable_fields' => $this->searchableFields,
        ];
    }

    public function fieldType(string $fieldKey): string
    {
        return $this->publicFieldTypes[$fieldKey]
            ?? throw new \InvalidArgumentException('The field is not part of the searchable public projection.');
    }

    /**
     * @param array<array-key, mixed> $fieldDefinitions
     */
    public function assertExactFieldDefinitions(array $fieldDefinitions): void
    {
        $signatures = [];

        if (!array_is_list($fieldDefinitions)) {
            throw new \InvalidArgumentException('Content field definitions must be an ordered list.');
        }

        foreach ($fieldDefinitions as $definition) {
            if (!$definition instanceof ContentFieldDefinition || isset($signatures[$definition->key])) {
                throw new \InvalidArgumentException('Content field definitions must be unique value objects.');
            }

            $constraints = $definition->constraints;
            ksort($constraints, SORT_STRING);

            $signatures[$definition->key] = [
                'property_type' => $definition->propertyType,
                'visibility' => $definition->visibility->value,
                'required' => $definition->required,
                'constraints' => $constraints,
            ];
        }

        if ($signatures !== $this->fieldSignatures) {
            throw new \InvalidArgumentException(
                'The Content projection contract must belong to the exact Content type field definitions.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, string|int|bool|null>
     */
    public function normalizeExactPublicFields(array $values): array
    {
        $actualKeys = array_keys($values);
        $expectedKeys = array_keys($this->publicFieldTypes);

        sort($actualKeys, SORT_STRING);
        $sortedExpectedKeys = $expectedKeys;
        sort($sortedExpectedKeys, SORT_STRING);

        if ($actualKeys !== $sortedExpectedKeys) {
            throw new \InvalidArgumentException(
                'Published Content fields must match the exact public field allowlist.',
            );
        }

        $normalized = [];

        foreach ($expectedKeys as $fieldKey) {
            $value = $values[$fieldKey];
            $signature = $this->fieldSignatures[$fieldKey];

            if ($value === null) {
                if ($signature['required']) {
                    throw new \InvalidArgumentException(
                        'A required published Content field cannot be null.',
                    );
                }

                $normalized[$fieldKey] = null;
                continue;
            }

            $valid = match ($signature['property_type']) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                default => false,
            };

            if (!$valid) {
                throw new \InvalidArgumentException(
                    'A published Content field does not match its exact Property type.',
                );
            }

            $normalized[$fieldKey] = $value;
        }

        return $normalized;
    }

    /**
     * Normalizes the owner DTO returned for one immutable Storage record
     * version. Optional public fields absent from that record become explicit
     * nulls; unknown, private and admin fields fail closed.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<string, string|int|bool|null>
     */
    public function normalizeStoragePublicFields(array $values): array
    {
        foreach (array_keys($values) as $fieldKey) {
            if (!is_string($fieldKey) || !array_key_exists($fieldKey, $this->publicFieldTypes)) {
                throw new \InvalidArgumentException(
                    'Storage public values must contain only the frozen Content public field allowlist.',
                );
            }
        }

        $complete = [];

        foreach ($this->publicFieldTypes as $fieldKey => $_propertyType) {
            if (!array_key_exists($fieldKey, $values)) {
                if ($this->fieldSignatures[$fieldKey]['required']) {
                    throw new \InvalidArgumentException(
                        'A required published Content field is absent from the exact Storage projection.',
                    );
                }

                $complete[$fieldKey] = null;
                continue;
            }

            $complete[$fieldKey] = $values[$fieldKey];
        }

        return $this->normalizeExactPublicFields($complete);
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Runtime;

use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaVersion;

final class ContentSchemaMapper
{
    /** @var array<string, int> */
    private const PROPERTY_VERSIONS = [
        'string' => 2,
        'integer' => 1,
        'boolean' => 1,
    ];

    private ContentInputGuard $input;
    private ContentCanonicalJson $canonicalJson;

    public function __construct(
        private readonly PropertyTypeRegistry $propertyTypes,
        ?ContentInputGuard $input = null,
        ?ContentCanonicalJson $canonicalJson = null,
    ) {
        $this->canonicalJson = $canonicalJson ?? new ContentCanonicalJson();
        $this->input = $input ?? new ContentInputGuard($this->canonicalJson);
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     *
     * @return array{
     *     schema_id: string,
     *     owner_package: string,
     *     fields: list<array{
     *         key: string,
     *         type: string,
     *         type_version: int,
     *         required: bool,
     *         visibility: string,
     *         constraints: array<string, int>
     *     }>
     * }
     */
    public function definition(ContentTypeKey $typeKey, array $fields): array
    {
        $this->input->assertFields($fields);
        $mapped = [];

        foreach ($fields as $field) {
            $version = $this->propertyVersion($field->propertyType);
            $descriptor = $this->propertyTypes->resolve($field->propertyType, $version);

            if (
                $descriptor === null
                || !$descriptor->isValid()
                || $descriptor->typeKey !== $field->propertyType
                || $descriptor->version !== $version
            ) {
                throw new ContentIntegrationFailed(
                    'property',
                    'exact_property_version_unavailable',
                );
            }

            $constraints = $field->constraints;
            ksort($constraints, SORT_STRING);

            $mapped[] = [
                'key' => $field->key,
                'type' => $field->propertyType,
                'type_version' => $version,
                'required' => $field->required,
                'visibility' => $this->storageVisibility($field->visibility),
                'constraints' => $constraints,
            ];
        }

        return [
            'schema_id' => $typeKey->storageSchemaRef(),
            'owner_package' => 'larena/content',
            'fields' => $mapped,
        ];
    }

    /**
     * Performs an owner-compatible preflight. Storage repeats normalization
     * and validation independently when it writes.
     *
     * @param list<ContentFieldDefinition> $fields
     * @param array<string, scalar|null> $values
     *
     * @return array<string, scalar|null>
     */
    public function normalizeValues(array $fields, array $values): array
    {
        $this->input->assertFields($fields);
        $this->input->assertSubmittedValues($values);
        $definitions = [];

        foreach ($fields as $field) {
            $definitions[$field->key] = $field;
        }

        foreach ($values as $key => $_value) {
            if (!isset($definitions[$key])) {
                throw new ContentRejected(
                    'content_value_unknown_field',
                    'Submitted Content values contain an unknown field.',
                );
            }
        }

        $normalized = [];

        foreach ($definitions as $key => $field) {
            if (!array_key_exists($key, $values)) {
                if ($field->required) {
                    throw new ContentRejected(
                        'content_required_field_missing',
                        'A required Content value is missing.',
                    );
                }

                continue;
            }

            if ($values[$key] === null) {
                if ($field->required) {
                    throw new ContentRejected(
                        'content_required_field_missing',
                        'A required Content value cannot be null.',
                    );
                }

                // Property and Storage own concrete typed values. Content's
                // nullable mutation input means "remove this optional value",
                // so null never crosses the owner boundary as a fake value.
                continue;
            }

            $version = $this->propertyVersion($field->propertyType);
            if ($this->propertyTypes->resolve($field->propertyType, $version) === null) {
                throw new ContentIntegrationFailed(
                    'property',
                    'exact_property_version_unavailable',
                );
            }

            $result = $this->propertyTypes->normalizeAndValidate(
                $field->propertyType,
                $version,
                $values[$key],
                $field->constraints,
            );

            if (!$result->canBePersistedByOwner()) {
                throw new ContentRejected(
                    'content_value_invalid',
                    'A submitted Content value failed its exact Property contract.',
                );
            }

            $value = $result->normalizedValue;
            if (!($value === null || is_string($value) || is_int($value) || is_bool($value))) {
                throw new ContentIntegrationFailed(
                    'property',
                    'normalized_value_contract_mismatch',
                );
            }

            $normalized[$key] = $value;
        }

        $this->input->assertNormalizedValues($normalized);

        return $normalized;
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     */
    public function assertSchemaMatches(
        ContentTypeKey $typeKey,
        array $fields,
        StorageSchemaVersion $schema,
    ): void {
        $definition = $this->definition($typeKey, $fields);
        $definitionHash = $this->schemaHash($definition);

        if (
            $schema->ref->schemaId !== $typeKey->storageSchemaRef()
            || $schema->ownerPackage !== 'larena/content'
            || $schema->fields !== $definition['fields']
            || !hash_equals($definitionHash, $schema->definitionHash)
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_contract_mismatch',
            );
        }
    }

    /**
     * Hydrates only the exact Content-owned Storage schema shape. This is the
     * inverse of definition(); unknown Property versions or visibility values
     * fail closed rather than being upgraded through latest().
     *
     * @return list<ContentFieldDefinition>
     */
    public function fieldDefinitions(StorageSchemaVersion $schema): array
    {
        if (
            $schema->ownerPackage !== 'larena/content'
            || !str_starts_with($schema->ref->schemaId, 'content.type.')
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_contract_mismatch',
            );
        }

        $schemaFields = $this->exactSchemaFields($schema->fields);
        $typeKeyValue = substr($schema->ref->schemaId, strlen('content.type.'));

        try {
            $typeKey = new ContentTypeKey($typeKeyValue);
        } catch (\InvalidArgumentException $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_contract_mismatch',
                $exception,
            );
        }

        $fields = [];

        foreach ($schemaFields as $field) {
            if (!is_array($field)) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_contract_mismatch',
                );
            }

            $keys = array_keys($field);
            sort($keys, SORT_STRING);

            if (
                $keys !== ['constraints', 'key', 'required', 'type', 'type_version', 'visibility']
                || !is_string($field['key'])
                || !is_string($field['type'])
                || !is_int($field['type_version'])
                || !is_bool($field['required'])
                || !is_string($field['visibility'])
                || !is_array($field['constraints'])
                || ($field['constraints'] !== [] && array_is_list($field['constraints']))
            ) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_contract_mismatch',
                );
            }

            $expectedVersion = self::PROPERTY_VERSIONS[$field['type']] ?? null;
            $descriptor = $expectedVersion === null
                ? null
                : $this->propertyTypes->resolve($field['type'], $expectedVersion);

            if (
                $expectedVersion === null
                || $field['type_version'] !== $expectedVersion
                || $descriptor === null
                || !$descriptor->isValid()
                || $descriptor->typeKey !== $field['type']
                || $descriptor->version !== $expectedVersion
            ) {
                throw new ContentIntegrationFailed(
                    'property',
                    'exact_property_version_unavailable',
                );
            }

            $constraints = [];
            foreach ($field['constraints'] as $key => $value) {
                if (!is_string($key) || !is_int($value)) {
                    throw new ContentIntegrationFailed(
                        'storage',
                        'schema_contract_mismatch',
                    );
                }

                $constraints[$key] = $value;
            }
            ksort($constraints, SORT_STRING);

            $visibility = match ($field['visibility']) {
                'public' => ContentFieldVisibility::Public,
                'protected' => ContentFieldVisibility::Private,
                'admin' => ContentFieldVisibility::AdminOnly,
                default => throw new ContentIntegrationFailed(
                    'storage',
                    'schema_contract_mismatch',
                ),
            };

            try {
                $fields[] = new ContentFieldDefinition(
                    key: $field['key'],
                    propertyType: $field['type'],
                    visibility: $visibility,
                    required: $field['required'],
                    constraints: $constraints,
                );
            } catch (\InvalidArgumentException $exception) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'schema_contract_mismatch',
                    $exception,
                );
            }
        }

        $this->assertSchemaMatches($typeKey, $fields, $schema);

        return $fields;
    }

    /**
     * Treats owner DTO data as an external boundary despite its descriptive
     * PHPDoc and validates the list shape before hydration.
     *
     * @return list<mixed>
     */
    private function exactSchemaFields(mixed $fields): array
    {
        if (
            !is_array($fields)
            || !array_is_list($fields)
            || $fields === []
            || count($fields) > ContentInputGuard::MAX_FIELDS
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_contract_mismatch',
            );
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function schemaHash(array $definition): string
    {
        return hash('sha256', $this->canonicalJson->encode($definition));
    }

    public function propertyVersion(string $propertyType): int
    {
        return self::PROPERTY_VERSIONS[$propertyType]
            ?? throw new ContentRejected(
                'property_type_unsupported',
                'Content Platform v1 supports only its three frozen Property types.',
            );
    }

    public function storageVisibility(ContentFieldVisibility $visibility): string
    {
        return match ($visibility) {
            ContentFieldVisibility::Public => 'public',
            ContentFieldVisibility::Private => 'protected',
            ContentFieldVisibility::AdminOnly => 'admin',
        };
    }
}

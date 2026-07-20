<?php

declare(strict_types=1);

namespace Larena\Content\Rest;

use InvalidArgumentException;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\ValueObjects\ContentAttachmentPlacement;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentTypeVersion;

final class ContentAdminValueCodec
{
    /**
     * @param array<array-key, mixed> $input
     * @return list<ContentFieldDefinition>
     */
    public function fields(array $input): array
    {
        if (!array_is_list($input) || $input === [] || count($input) > 100) {
            throw new InvalidArgumentException('content_admin_fields_invalid');
        }

        $fields = [];
        $seen = [];
        foreach ($input as $definition) {
            if (!is_array($definition)) {
                throw new InvalidArgumentException('content_admin_fields_invalid');
            }
            $this->assertExactKeys(
                $definition,
                ['key', 'property_type', 'visibility', 'required', 'constraints'],
            );
            $key = $this->requiredString($definition, 'key');
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('content_admin_field_duplicate');
            }
            $seen[$key] = true;
            $propertyType = $this->requiredString($definition, 'property_type');
            $visibility = ContentFieldVisibility::tryFrom(
                $this->requiredString($definition, 'visibility'),
            );
            $required = $definition['required'] ?? null;
            $constraints = $definition['constraints'] ?? null;
            if (!$visibility instanceof ContentFieldVisibility || !is_bool($required) || !is_array($constraints)) {
                throw new InvalidArgumentException('content_admin_fields_invalid');
            }

            $fields[] = new ContentFieldDefinition(
                key: $key,
                propertyType: $propertyType,
                visibility: $visibility,
                required: $required,
                constraints: $constraints,
            );
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $input
     * @param list<ContentFieldDefinition> $fields
     */
    public function projection(array $input, array $fields): ContentProjectionContract
    {
        return ContentProjectionContract::fromArray($input, $fields);
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, bool|int|string|null>
     */
    public function safeMetadata(array $input): array
    {
        $allowed = ['label', 'plural_label', 'description', 'icon', 'group', 'sort', 'hidden'];
        $metadata = [];
        foreach ($input as $key => $value) {
            if (
                !is_string($key)
                || !in_array($key, $allowed, true)
                || !($value === null || is_string($value) || is_int($value) || is_bool($value))
            ) {
                throw new InvalidArgumentException('content_admin_safe_metadata_invalid');
            }
            $metadata[$key] = $value;
        }

        return $metadata;
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, string|int|bool|null>
     */
    public function values(array $input): array
    {
        if (!array_is_list($input) || count($input) > 100) {
            throw new InvalidArgumentException('content_admin_values_invalid');
        }

        $values = [];
        foreach ($input as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('content_admin_values_invalid');
            }
            $this->assertExactKeys($entry, ['key', 'value']);
            $key = $this->requiredString($entry, 'key');
            $value = $entry['value'] ?? null;
            if (
                array_key_exists($key, $values)
                || !($value === null || is_string($value) || is_int($value) || is_bool($value))
            ) {
                throw new InvalidArgumentException(
                    array_key_exists($key, $values)
                        ? 'content_admin_value_key_duplicate'
                        : 'content_admin_values_invalid',
                );
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $input
     * @return list<ContentAttachmentPlacement>
     */
    public function placements(array $input): array
    {
        if (!array_is_list($input) || count($input) > 100) {
            throw new InvalidArgumentException('content_admin_attachments_invalid');
        }

        $placements = [];
        foreach ($input as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('content_admin_attachments_invalid');
            }
            $this->assertExactKeys($entry, ['logical_file_ref', 'role', 'position']);
            $position = $entry['position'] ?? null;
            if (!is_int($position)) {
                throw new InvalidArgumentException('content_admin_attachments_invalid');
            }
            $placements[] = new ContentAttachmentPlacement(
                logicalFileRef: $this->requiredString($entry, 'logical_file_ref'),
                role: $this->requiredString($entry, 'role'),
                position: $position,
            );
        }

        return $placements;
    }

    /**
     * @param array<string, scalar|null> $values
     * @return list<array{key:string,value:string|int|bool|null}>
     */
    public function encodeValues(array $values, ContentTypeVersion $type): array
    {
        $encoded = [];
        foreach ($type->fieldDefinitions as $field) {
            if (!array_key_exists($field->key, $values)) {
                continue;
            }
            $value = $values[$field->key];
            if (!($value === null || is_string($value) || is_int($value) || is_bool($value))) {
                throw new InvalidArgumentException('content_admin_values_invalid');
            }
            $encoded[] = ['key' => $field->key, 'value' => $value];
        }

        if (count($encoded) !== count($values)) {
            throw new InvalidArgumentException('content_admin_values_invalid');
        }

        return $encoded;
    }

    /** @return array<string, mixed> */
    public function encodeTypeVersion(ContentTypeVersion $type): array
    {
        return [
            'type_key' => $type->typeKey->value,
            'version' => $type->version,
            'schema_hash' => $type->schemaHash,
            'fields' => array_map(
                fn (ContentFieldDefinition $field): array => $this->encodeField($field),
                $type->fieldDefinitions,
            ),
            'projection' => $type->projectionContract->toArray(),
            'safe_metadata' => $type->safeMetadata,
            'created_by' => $type->createdBy,
            'created_at' => $type->createdAt->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /** @return array<string, mixed> */
    private function encodeField(ContentFieldDefinition $field): array
    {
        return [
            'key' => $field->key,
            'property_type' => $field->propertyType,
            'visibility' => $field->visibility->value,
            'required' => $field->required,
            'constraints' => $field->constraints,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     * @param list<string> $expected
     */
    private function assertExactKeys(array $input, array $expected): void
    {
        if ($input !== [] && array_is_list($input)) {
            throw new InvalidArgumentException('content_admin_object_invalid');
        }
        $actual = array_keys($input);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('content_admin_object_invalid');
        }
    }

    /** @param array<array-key, mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('content_admin_string_invalid');
        }

        return $value;
    }
}

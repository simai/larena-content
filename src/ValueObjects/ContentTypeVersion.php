<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use DateTimeImmutable;

final readonly class ContentTypeVersion
{
    /**
     * Public-safe type metadata keys frozen for Content Platform v1.
     *
     * @var list<string>
     */
    private const SAFE_METADATA_KEYS = [
        'label',
        'plural_label',
        'description',
        'icon',
        'group',
        'sort',
        'hidden',
    ];

    /** @var list<ContentFieldDefinition> */
    public array $fieldDefinitions;

    /**
     * @param array<array-key, mixed> $fieldDefinitions
     * @param array<string, bool|float|int|string|null> $safeMetadata
     */
    public function __construct(
        public ContentTypeKey $typeKey,
        public int $version,
        public string $storageSchemaRef,
        public int $storageSchemaVersion,
        public string $schemaHash,
        array $fieldDefinitions,
        public ContentProjectionContract $projectionContract,
        public array $safeMetadata,
        public string $createdBy,
        public string $correlationId,
        public DateTimeImmutable $createdAt,
    ) {
        if ($version < 1 || $storageSchemaVersion < 1) {
            throw new \InvalidArgumentException('Content and Storage schema versions must be positive.');
        }

        if ($storageSchemaRef !== $typeKey->storageSchemaRef()) {
            throw new \InvalidArgumentException('The Storage schema reference must be derived from the Content type key.');
        }

        if (preg_match('/\A[0-9a-f]{64}\z/D', $schemaHash) !== 1) {
            throw new \InvalidArgumentException('Content schema hashes must be lowercase SHA-256 values.');
        }

        if ($fieldDefinitions === [] || !array_is_list($fieldDefinitions)) {
            throw new \InvalidArgumentException('A Content type version requires an ordered field definition list.');
        }

        $fieldKeys = [];
        $normalizedFieldDefinitions = [];

        foreach ($fieldDefinitions as $definition) {
            if (!$definition instanceof ContentFieldDefinition || isset($fieldKeys[$definition->key])) {
                throw new \InvalidArgumentException('Content type field definitions must be unique value objects.');
            }

            $fieldKeys[$definition->key] = true;
            $normalizedFieldDefinitions[] = $definition;
        }

        $this->fieldDefinitions = $normalizedFieldDefinitions;
        $projectionContract->assertExactFieldDefinitions($normalizedFieldDefinitions);
        self::assertSafeMetadata($safeMetadata);
        self::assertSafeReference($createdBy, 'creator reference');
        self::assertSafeReference($correlationId, 'correlation id');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function assertSafeMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (
                !in_array($key, self::SAFE_METADATA_KEYS, true)
                || ($value !== null && !is_scalar($value))
            ) {
                throw new \InvalidArgumentException(
                    'Content type metadata must use the frozen public-safe v1 allowlist.',
                );
            }
        }
    }

    private static function assertSafeReference(string $value, string $label): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('Invalid Content %s.', $label));
        }
    }
}

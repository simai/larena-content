<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use DateTimeImmutable;
use JsonException;

final readonly class ContentTypeVersion
{
    public const int MAX_FIELDS = 100;

    public const int MAX_CANONICAL_JSON_BYTES = 16_384;

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

        if (
            $fieldDefinitions === []
            || !array_is_list($fieldDefinitions)
            || count($fieldDefinitions) > self::MAX_FIELDS
        ) {
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
        self::assertCanonicalJsonBound(
            $projectionContract->toArray(),
            'Content projection contracts',
        );
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
            if (!in_array($key, self::SAFE_METADATA_KEYS, true)) {
                throw new \InvalidArgumentException(
                    'Content type metadata must use the frozen public-safe v1 allowlist.',
                );
            }

            if ($key === 'sort') {
                $validType = $value === null || is_int($value);
            } elseif ($key === 'hidden') {
                $validType = $value === null || is_bool($value);
            } else {
                $validType = $value === null || is_string($value);
            }

            if (!$validType) {
                throw new \InvalidArgumentException(
                    'Content type metadata values must match the exact frozen key types.',
                );
            }

            if (is_string($value)) {
                self::assertSafeMetadataText($value);
            }
        }

        self::assertCanonicalJsonBound($metadata, 'Content safe type metadata');
    }

    private static function assertSafeMetadataText(string $value): void
    {
        if (
            preg_match('//u', $value) !== 1
            || preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $value) === 1
            || preg_match(
                '/(?:<\?(?:php|=)?|<\s*script\b|javascript\s*:|data\s*:\s*text\/html|(?:^|[\s<])on[a-z]+\s*=)/iu',
                $value,
            ) === 1
        ) {
            throw new \InvalidArgumentException(
                'Content safe type metadata must be inert valid UTF-8 text.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function assertCanonicalJsonBound(array $value, string $label): void
    {
        $canonical = $value;

        if (!array_is_list($canonical)) {
            ksort($canonical, SORT_STRING);
        }

        try {
            $json = json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException(sprintf('%s must be canonical JSON.', $label), 0, $exception);
        }

        if (strlen($json) > self::MAX_CANONICAL_JSON_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                '%s may contain at most %d canonical JSON bytes.',
                $label,
                self::MAX_CANONICAL_JSON_BYTES,
            ));
        }
    }

    private static function assertSafeReference(string $value, string $label): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('Invalid Content %s.', $label));
        }
    }
}

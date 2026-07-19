<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;

final readonly class ContentLogicalFileInspection
{
    /**
     * Exact public-safe Filesystem metadata admitted by Content Platform v1.
     *
     * @var array<string, 'integer'|'nullable_string'|'string'>
     */
    private const SAFE_METADATA_TYPES = [
        'public_id' => 'string',
        'display_name' => 'string',
        'mime_type' => 'string',
        'extension' => 'string',
        'size_bytes' => 'integer',
        'alt_text' => 'nullable_string',
    ];

    public bool $physicalAvailable;

    /** @var array{public_id: string, display_name: string, mime_type: string, extension: string, size_bytes: int, alt_text: string|null}|array{} */
    public array $safeMetadata;

    /**
     * The legacy `available` and `public` argument names are retained for the
     * interface-first DTO, while their exact meanings are physical
     * availability and Filesystem public visibility.
     *
     * @param array<string, mixed> $safeMetadata
     */
    public function __construct(
        public string $logicalFileRef,
        public bool $exists,
        public bool $available,
        public bool $public,
        array $safeMetadata = [],
        public bool $persistent = false,
    ) {
        ContentAttachmentPlacement::assertLogicalFileRef($logicalFileRef);
        $this->physicalAvailable = $available;

        if (!$exists) {
            if ($available || $public || $persistent || $safeMetadata !== []) {
                throw new \InvalidArgumentException('A missing logical file cannot contain file state.');
            }

            $this->safeMetadata = [];

            return;
        }

        $this->safeMetadata = self::normalizeSafeMetadata($safeMetadata);
    }

    public static function fromFilesystemInspection(PersistentLogicalFileInspection $inspection): self
    {
        return new self(
            logicalFileRef: $inspection->logicalRef,
            exists: $inspection->exists,
            available: $inspection->physicalAvailable,
            public: $inspection->visibility === FileVisibility::Public,
            safeMetadata: $inspection->safeMetadata,
            persistent: $inspection->lifecycleClass === FileLifecycleClass::Persistent,
        );
    }

    public function isContentAttachable(): bool
    {
        return $this->exists && $this->physicalAvailable && $this->persistent;
    }

    public function isPubliclyProjectable(): bool
    {
        return $this->isContentAttachable() && $this->public;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function assertSafeMetadata(array $metadata): void
    {
        self::normalizeSafeMetadata($metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{public_id: string, display_name: string, mime_type: string, extension: string, size_bytes: int, alt_text: string|null}
     */
    private static function normalizeSafeMetadata(array $metadata): array
    {
        $actualKeys = array_keys($metadata);
        $expectedKeys = array_keys(self::SAFE_METADATA_TYPES);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException(
                'Existing logical file metadata must contain the exact frozen six-key allowlist.',
            );
        }

        $sizeBytes = $metadata['size_bytes'];

        if (!is_int($sizeBytes) || $sizeBytes < 0) {
            throw new \InvalidArgumentException(
                'Logical file dimension and size metadata must be non-negative integers.',
            );
        }

        return [
            'public_id' => self::normalizeRequiredSafeText($metadata['public_id']),
            'display_name' => self::normalizeRequiredSafeText($metadata['display_name']),
            'mime_type' => self::normalizeRequiredSafeText($metadata['mime_type']),
            'extension' => self::normalizeRequiredSafeText($metadata['extension']),
            'size_bytes' => $sizeBytes,
            'alt_text' => self::normalizeOptionalSafeText($metadata['alt_text']),
        ];
    }

    private static function normalizeRequiredSafeText(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(
                'Logical file public text metadata is invalid.',
            );
        }

        self::assertSafeText($value);

        return $value;
    }

    private static function normalizeOptionalSafeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Logical file public text metadata is invalid.',
            );
        }

        self::assertSafeText($value);

        return $value;
    }

    private static function assertSafeText(string $value): void
    {
        $codePoints = preg_match_all('/./us', $value);

        if (
            preg_match('//u', $value) !== 1
            || preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $value) === 1
            || $codePoints === false
            || $codePoints > ContentFieldDefinition::MAX_STRING_CODE_POINTS
        ) {
            throw new \InvalidArgumentException(
                'Logical file public text metadata is invalid.',
            );
        }
    }
}

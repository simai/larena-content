<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentLogicalFileInspection
{
    /**
     * Exact public-safe Filesystem metadata admitted by Content Platform v1.
     *
     * @var array<string, 'integer'|'string'>
     */
    private const SAFE_METADATA_TYPES = [
        'public_id' => 'string',
        'display_name' => 'string',
        'alt_text' => 'string',
        'mime_type' => 'string',
        'extension' => 'string',
        'size_bytes' => 'integer',
    ];

    /**
     * @param array<string, bool|float|int|string|null> $safeMetadata
     */
    public function __construct(
        public string $logicalFileRef,
        public bool $exists,
        public bool $available,
        public bool $public,
        public array $safeMetadata = [],
    ) {
        ContentAttachmentPlacement::assertLogicalFileRef($logicalFileRef);

        if (!$exists && ($available || $public)) {
            throw new \InvalidArgumentException('A missing logical file cannot be available or public.');
        }

        if (!$available && $public) {
            throw new \InvalidArgumentException('An unavailable logical file cannot be public.');
        }

        self::assertSafeMetadata($safeMetadata);
    }

    public function isPubliclyProjectable(): bool
    {
        return $this->exists && $this->available && $this->public;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function assertSafeMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            $expectedType = self::SAFE_METADATA_TYPES[$key] ?? null;

            if ($expectedType === null) {
                throw new \InvalidArgumentException(
                    'Logical file metadata is outside the frozen public-safe v1 allowlist.',
                );
            }

            if ($value === null) {
                continue;
            }

            if ($expectedType === 'integer') {
                if (!is_int($value) || $value < 0) {
                    throw new \InvalidArgumentException(
                        'Logical file dimension and size metadata must be non-negative integers.',
                    );
                }

                continue;
            }

            if (
                !is_string($value)
                || $value === ''
                || strlen($value) > 500
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new \InvalidArgumentException(
                    'Logical file public text metadata is invalid.',
                );
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentTypeSchemaCompatibilityReport
{
    /** @var list<string> */
    public array $reasonCodes;

    /** @param array<array-key, mixed> $reasonCodes */
    public function __construct(
        public ContentTypeKey $typeKey,
        public int $sourceVersion,
        public string $sourceSchemaHash,
        public int $targetVersion,
        public string $targetSchemaHash,
        public bool $compatible,
        public string $compatibilityClass,
        public int $addedOptionalFieldCount,
        public int $itemCount,
        public string $itemHeadsHash,
        public string $storageRecordHeadsHash,
        array $reasonCodes,
    ) {
        if (
            $sourceVersion < 1
            || $targetVersion !== $sourceVersion + 1
            || $addedOptionalFieldCount < 0
            || $itemCount < 0
            || preg_match('/\A[0-9a-f]{64}\z/D', $sourceSchemaHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $targetSchemaHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $itemHeadsHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $storageRecordHeadsHash) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Content schema compatibility reports must contain exact bounded identifiers.',
            );
        }

        $normalizedReasonCodes = [];
        foreach ($reasonCodes as $reasonCode) {
            if (!is_string($reasonCode) || preg_match('/\A[a-z][a-z0-9._-]{0,95}\z/D', $reasonCode) !== 1) {
                throw new \InvalidArgumentException(
                    'Content schema compatibility reason codes must be stable safe references.',
                );
            }
            $normalizedReasonCodes[] = $reasonCode;
        }
        $this->reasonCodes = $normalizedReasonCodes;
    }
}

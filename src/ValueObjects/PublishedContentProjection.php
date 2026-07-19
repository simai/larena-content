<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Storage\Contracts\StoragePublicProjection;

final readonly class PublishedContentProjection
{
    public ContentTypeKey $typeKey;

    public int $projectionVersion;

    /** @var array<string, string|int|bool|null> */
    public array $publicFields;

    /** @var list<PublicContentAttachment> */
    public array $publicAttachments;

    /** @var array<string, bool|float|int|string|null> */
    public array $safeTypeMetadata;

    public string $projectionHash;

    private ContentProjectionContract $projectionContract;

    /**
     * @param array<array-key, mixed> $publicFields
     * @param array<array-key, mixed> $publicAttachments
     */
    private function __construct(
        ContentTypeVersion $typeVersion,
        public ContentItemRef $itemRef,
        public ContentLocale $locale,
        public ContentSlug $slug,
        public int $publishedRevision,
        public DateTimeImmutable $publishedAt,
        array $publicFields,
        array $publicAttachments,
    ) {
        if ($publishedRevision < 1) {
            throw new \InvalidArgumentException('Published Content revisions must be positive.');
        }

        $normalizedPublicFields = $typeVersion
            ->projectionContract
            ->normalizeExactPublicFields($publicFields);

        if (!array_is_list($publicAttachments)) {
            throw new \InvalidArgumentException('Published Content attachments must be an ordered list.');
        }

        if (count($publicAttachments) > ContentRevision::MAX_ATTACHMENTS) {
            throw new \InvalidArgumentException(sprintf(
                'Published Content projections may contain at most %d attachments.',
                ContentRevision::MAX_ATTACHMENTS,
            ));
        }

        $attachmentIdentities = [];
        $normalizedPublicAttachments = [];
        $previousPosition = -1;

        foreach ($publicAttachments as $attachment) {
            if (!$attachment instanceof PublicContentAttachment) {
                throw new \InvalidArgumentException(
                    'Published Content projections may contain only public-safe attachment value objects.',
                );
            }

            if (!$attachment->belongsToRevision($this->itemRef, $this->publishedRevision)) {
                throw new \InvalidArgumentException(
                    'Published Content attachments must belong to the exact published item revision.',
                );
            }

            $identity = $attachment->logicalFileRef."\0".$attachment->role;

            if (isset($attachmentIdentities[$identity])) {
                throw new \InvalidArgumentException('Published Content attachments must be unique by file and role.');
            }

            if ($attachment->position <= $previousPosition) {
                throw new \InvalidArgumentException(
                    'Published Content attachments must have unique ascending positions.',
                );
            }

            $attachmentIdentities[$identity] = true;
            $normalizedPublicAttachments[] = $attachment;
            $previousPosition = $attachment->position;
        }

        $this->typeKey = $typeVersion->typeKey;
        $this->projectionVersion = $typeVersion->projectionContract->version;
        $this->projectionContract = $typeVersion->projectionContract;
        $this->publicFields = $normalizedPublicFields;
        $this->publicAttachments = $normalizedPublicAttachments;
        $this->safeTypeMetadata = $typeVersion->safeMetadata;
        $this->projectionHash = $this->calculateProjectionHash();
    }

    /**
     * The public DTO can only be created from an exact persisted publication
     * proof. Callers cannot supply lifecycle pointers or publication time
     * independently from the Content item and its immutable revision.
     *
     * @param array<array-key, mixed> $publicAttachments
     */
    public static function fromPublishedRevision(
        ContentTypeVersion $typeVersion,
        ContentItem $item,
        ContentRevision $revision,
        StoragePublicProjection $storageProjection,
        array $publicAttachments,
    ): self {
        self::assertPublicationProof($typeVersion, $item, $revision);
        self::assertStorageProjectionProof($item, $revision, $storageProjection);

        if (count($publicAttachments) > $revision->attachmentCount) {
            throw new \InvalidArgumentException(
                'A public Content projection cannot contain more attachments than its exact revision.',
            );
        }

        $publishedRevision = $item->publishedRevision;
        $publishedSlug = $item->publishedSlug;
        $publishedAt = $item->publishedAt;

        if ($publishedRevision === null || $publishedSlug === null || $publishedAt === null) {
            throw new \LogicException('Validated publication proof lost its required lifecycle pointers.');
        }

        $publicFields = $typeVersion
            ->projectionContract
            ->normalizeStoragePublicFields($storageProjection->values);

        return new self(
            typeVersion: $typeVersion,
            itemRef: $item->itemRef,
            locale: $item->locale,
            slug: $publishedSlug,
            publishedRevision: $publishedRevision,
            publishedAt: $publishedAt,
            publicFields: $publicFields,
            publicAttachments: $publicAttachments,
        );
    }

    public function projectionContract(): ContentProjectionContract
    {
        return $this->projectionContract;
    }

    /**
     * @return array{
     *     type_key: string,
     *     safe_type_metadata: array<string, bool|float|int|string|null>,
     *     item_ref: string,
     *     locale: string,
     *     slug: string,
     *     published_revision: int,
     *     published_at: string,
     *     public_fields: array<string, string|int|bool|null>,
     *     public_attachments: list<array{
     *         logical_file_ref: string,
     *         role: string,
     *         position: int,
     *         metadata: array<string, bool|float|int|string|null>
     *     }>,
     *     projection_version: int,
     *     projection_hash: string
     * }
     */
    public function toArray(): array
    {
        return [
            'type_key' => $this->typeKey->value,
            'safe_type_metadata' => $this->safeTypeMetadata,
            'item_ref' => $this->itemRef->value,
            'locale' => $this->locale->value,
            'slug' => $this->slug->value,
            'published_revision' => $this->publishedRevision,
            'published_at' => $this->publishedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'public_fields' => $this->publicFields,
            'public_attachments' => array_map(
                static fn (PublicContentAttachment $attachment): array => $attachment->toArray(),
                $this->publicAttachments,
            ),
            'projection_version' => $this->projectionVersion,
            'projection_hash' => $this->projectionHash,
        ];
    }

    private function calculateProjectionHash(): string
    {
        $hashInput = [
            'type_key' => $this->typeKey->value,
            'safe_type_metadata' => self::canonicalize($this->safeTypeMetadata),
            'item_ref' => $this->itemRef->value,
            'locale' => $this->locale->value,
            'slug' => $this->slug->value,
            'published_revision' => $this->publishedRevision,
            'published_at' => $this->publishedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'public_fields' => self::canonicalize($this->publicFields),
            'public_attachments' => array_map(
                static fn (PublicContentAttachment $attachment): array => self::canonicalize($attachment->toArray()),
                $this->publicAttachments,
            ),
            'projection_version' => $this->projectionVersion,
        ];

        try {
            return hash(
                'sha256',
                json_encode(
                    $hashInput,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                ),
            );
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Published Content projection is not canonical JSON.', 0, $exception);
        }
    }

    private static function assertPublicationProof(
        ContentTypeVersion $typeVersion,
        ContentItem $item,
        ContentRevision $revision,
    ): void {
        if (
            !$item->hasPublishedRevision()
            || $item->itemRef->value !== $revision->itemRef->value
            || $item->typeKey->value !== $typeVersion->typeKey->value
            || $revision->typeKey->value !== $typeVersion->typeKey->value
            || $item->locale->value !== $revision->locale->value
            || $item->publishedRevision !== $revision->revision
            || $item->publishedSlug?->value !== $revision->slug->value
            || $revision->status !== ContentStatus::Published
            || $revision->visibility !== ContentVisibility::Public
            || $revision->typeVersion !== $typeVersion->version
            || $revision->storageSchemaRef !== $typeVersion->storageSchemaRef
            || $revision->storageSchemaVersion !== $typeVersion->storageSchemaVersion
            || (
                $item->currentRevision === $revision->revision
                && (
                    $item->currentStatus !== ContentStatus::Published
                    || $item->currentVisibility !== $revision->visibility
                    || $item->currentSlug->value !== $revision->slug->value
                )
            )
        ) {
            throw new \InvalidArgumentException(
                'A public Content projection requires the exact published public item, revision and type version.',
            );
        }
    }

    private static function assertStorageProjectionProof(
        ContentItem $item,
        ContentRevision $revision,
        StoragePublicProjection $storageProjection,
    ): void {
        if (
            $storageProjection->ownerRef !== $item->itemRef->value
            || $storageProjection->ref->schemaId !== $revision->storageSchemaRef
            || $storageProjection->ref->recordId !== $revision->storageRecordRef
            || $storageProjection->ref->revision !== $revision->storageRecordVersion
            || $storageProjection->schema->schemaId !== $revision->storageSchemaRef
            || $storageProjection->schema->version !== $revision->storageSchemaVersion
        ) {
            throw new \InvalidArgumentException(
                'A public Content projection requires the exact published Storage record and schema version.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::canonicalize($entry);
            }
        }

        return $value;
    }
}

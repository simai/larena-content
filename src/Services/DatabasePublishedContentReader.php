<?php

declare(strict_types=1);

namespace Larena\Content\Services;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\Runtime\PublishedContentProjectionBuilder;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ContentAttachmentReference;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentRevision;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

final readonly class DatabasePublishedContentReader implements PublishedContentReader
{
    public function __construct(
        private DatabaseContentRepository $repository,
        private ContentParticipantGuard $participants,
        private ContentStorageGateway $storage,
        private ContentSchemaMapper $schemas,
        private PublishedContentProjectionBuilder $projections,
    ) {
    }

    public function read(
        ContentTypeKey $typeKey,
        ContentSlug $slug,
        ContentLocale $locale,
    ): PublishedContentProjection {
        $this->participants->assertSharedConnection();
        $this->repository->assertCompleteCompatible();
        $route = $this->repository->publishedRouteRow(
            $typeKey->value,
            $locale->value,
            $slug->value,
        );

        if ($route === null) {
            throw new ContentNotPublic();
        }

        try {
            $itemRef = new ContentItemRef((string) $route['item_ref']);
            $publishedRevision = (int) $route['published_revision'];
            $itemRow = $this->repository->itemRow($itemRef->value);
            $revisionRow = $this->repository->revisionRow(
                $itemRef->value,
                $publishedRevision,
            );

            if (
                $itemRow === null
                || $revisionRow === null
                || (string) $itemRow['type_key'] !== $typeKey->value
                || (string) $itemRow['locale'] !== $locale->value
                || (int) $itemRow['published_revision'] !== $publishedRevision
                || (string) $itemRow['published_slug'] !== $slug->value
                || !is_string($itemRow['published_at'])
                || $itemRow['published_at'] === ''
                || (string) $revisionRow['slug'] !== $slug->value
                || (string) $revisionRow['status'] !== ContentStatus::Published->value
                || (string) $revisionRow['visibility'] !== ContentVisibility::Public->value
            ) {
                throw new ContentNotPublic();
            }

            $item = $this->hydrateItem($itemRow);
            $revision = $this->hydrateRevision($revisionRow);
            $typeVersion = $this->hydrateTypeVersion(
                $typeKey,
                $revision->typeVersion,
            );
            $attachments = $this->hydrateAttachments(
                $itemRef,
                $publishedRevision,
                $this->repository->attachmentRows($itemRef->value, $publishedRevision),
            );

            return $this->projections->build(
                $typeVersion,
                $item,
                $revision,
                $attachments,
            );
        } catch (ContentNotPublic $exception) {
            throw $exception;
        } catch (
            ContentIntegrationFailed
            |ContentRejected
            |StorageRejected
            |\InvalidArgumentException
            |\ValueError
        ) {
            throw new ContentNotPublic();
        }
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateItem(array $row): ContentItem
    {
        return new ContentItem(
            itemRef: new ContentItemRef((string) $row['item_ref']),
            typeKey: new ContentTypeKey((string) $row['type_key']),
            locale: new ContentLocale((string) $row['locale']),
            currentRevision: (int) $row['current_revision'],
            currentSlug: new ContentSlug((string) $row['current_slug']),
            currentStatus: ContentStatus::from((string) $row['current_status']),
            currentVisibility: ContentVisibility::from((string) $row['current_visibility']),
            publishedRevision: (int) $row['published_revision'],
            publishedSlug: new ContentSlug((string) $row['published_slug']),
            publishedAt: $this->dateTime((string) $row['published_at']),
        );
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateRevision(array $row): ContentRevision
    {
        return new ContentRevision(
            itemRef: new ContentItemRef((string) $row['item_ref']),
            revision: (int) $row['revision'],
            typeKey: new ContentTypeKey((string) $row['type_key']),
            locale: new ContentLocale((string) $row['locale']),
            typeVersion: (int) $row['type_version'],
            storageSchemaRef: (string) $row['storage_schema_ref'],
            storageSchemaVersion: (int) $row['storage_schema_version'],
            storageRecordRef: (string) $row['storage_record_ref'],
            storageRecordVersion: (int) $row['storage_record_version'],
            slug: new ContentSlug((string) $row['slug']),
            status: ContentStatus::from((string) $row['status']),
            visibility: ContentVisibility::from((string) $row['visibility']),
            attachmentCount: (int) $row['attachment_count'],
            createdBy: (string) $row['created_by'],
            correlationId: (string) $row['correlation_id'],
            createdAt: $this->dateTime((string) $row['created_at']),
        );
    }

    private function hydrateTypeVersion(
        ContentTypeKey $typeKey,
        int $version,
    ): ContentTypeVersion {
        $row = $this->repository->typeVersionRow($typeKey->value, $version);
        if ($row === null) {
            throw new ContentNotPublic();
        }
        $schema = $this->storage->schemaVersion(new StorageSchemaVersionRef(
            (string) $row['storage_schema_ref'],
            (int) $row['storage_schema_version'],
        ));
        if (!hash_equals((string) $row['schema_hash'], $schema->definitionHash)) {
            throw new ContentIntegrationFailed(
                'content',
                'type_version_storage_hash_mismatch',
            );
        }
        $fields = $this->schemas->fieldDefinitions($schema);

        return new ContentTypeVersion(
            typeKey: $typeKey,
            version: (int) $row['version'],
            storageSchemaRef: (string) $row['storage_schema_ref'],
            storageSchemaVersion: (int) $row['storage_schema_version'],
            schemaHash: (string) $row['schema_hash'],
            fieldDefinitions: $fields,
            projectionContract: ContentProjectionContract::fromArray(
                $this->decodeObject((string) $row['projection_contract']),
                $fields,
            ),
            safeMetadata: $this->decodeObject((string) $row['safe_metadata']),
            createdBy: (string) $row['created_by'],
            correlationId: (string) $row['correlation_id'],
            createdAt: $this->dateTime((string) $row['created_at']),
        );
    }

    /**
     * @param list<array<string, bool|int|string|null>> $rows
     * @return list<ContentAttachmentReference>
     */
    private function hydrateAttachments(
        ContentItemRef $itemRef,
        int $revision,
        array $rows,
    ): array {
        return array_map(
            static fn (array $row): ContentAttachmentReference => new ContentAttachmentReference(
                itemRef: $itemRef,
                revision: $revision,
                position: (int) $row['position'],
                logicalFileRef: (string) $row['logical_file_ref'],
                role: (string) $row['role'],
            ),
            $rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_json_invalid',
                $exception,
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ContentIntegrationFailed('content', 'persisted_json_invalid');
        }

        return $decoded;
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                $value,
                new DateTimeZone('UTC'),
            );
            $errors = DateTimeImmutable::getLastErrors();

            if (
                !$dateTime instanceof DateTimeImmutable
                || (
                    $errors !== false
                    && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)
                )
                || $dateTime->format('Y-m-d H:i:s.u') !== $value
            ) {
                throw new \UnexpectedValueException(
                    'Persisted Content timestamps must use exact UTC microseconds.',
                );
            }

            return $dateTime;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_timestamp_invalid',
                $exception,
            );
        }
    }
}

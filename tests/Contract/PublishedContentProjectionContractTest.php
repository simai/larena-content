<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use DateTimeImmutable;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\ValueObjects\ContentAttachmentReference;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentLogicalFileInspection;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentRevision;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Content\ValueObjects\PublicContentAttachment;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Storage\Contracts\StoragePublicProjection;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use PHPUnit\Framework\TestCase;

final class PublishedContentProjectionContractTest extends TestCase
{
    private const string RECORD_ID = 'record-018f6d524ef87bc29c713f2f4c164001';

    public function testProjectionSerializesOnlyFrozenPublicSurface(): void
    {
        $projection = $this->projection([
            'title' => 'Public title',
            'body' => 'Public body',
            'priority' => 7,
            'featured' => true,
        ]);
        $serialized = $projection->toArray();

        self::assertSame(
            [
                'type_key',
                'safe_type_metadata',
                'item_ref',
                'locale',
                'slug',
                'published_revision',
                'published_at',
                'public_fields',
                'public_attachments',
                'projection_version',
                'projection_hash',
            ],
            array_keys($serialized),
        );
        self::assertArrayNotHasKey('current_revision', $serialized);
        self::assertArrayNotHasKey('draft_slug', $serialized);
        self::assertArrayNotHasKey('storage_record', $serialized);
        self::assertArrayNotHasKey('access', $serialized);
        self::assertArrayNotHasKey('audit', $serialized);
        self::assertSame(64, strlen($projection->projectionHash));
    }

    public function testProjectionHashIsStableAcrossPublicMapInsertionOrder(): void
    {
        $first = $this->projection(['title' => 'Title', 'body' => 'Body']);
        $second = $this->projection(['body' => 'Body', 'title' => 'Title']);

        self::assertSame($first->projectionHash, $second->projectionHash);
    }

    public function testNestedOrObjectFieldValueFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $unsafe = ['title' => 'Public title', 'secret' => ['nested' => 'private']];

        $this->projection($unsafe);
    }

    public function testFieldOutsideExactPublicTypeVersionFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $typeVersion = $this->typeVersion(['title' => 'Title']);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $typeVersion,
            item: $this->publishedItem(),
            revision: $this->publishedRevision(),
            storageProjection: $this->storageProjection([
                'title' => 'Title',
                'private_notes' => 'TOP SECRET',
            ]),
            publicAttachments: [],
        );
    }

    public function testAttachmentPositionsMustBeUniqueAndAscending(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(attachmentCount: 2),
            storageProjection: $this->storageProjection(['title' => 'Title']),
            publicAttachments: [
                $this->publicAttachment('filesystem:logical:first', 'gallery', 1),
                $this->publicAttachment('filesystem:logical:second', 'gallery', 1),
            ],
        );
    }

    public function testProjectionAcceptsFrozenAttachmentLimit(): void
    {
        $projection = $this->projectionWithAttachments(
            $this->publicAttachments(ContentRevision::MAX_ATTACHMENTS),
        );

        self::assertCount(100, $projection->publicAttachments);
    }

    public function testProjectionRejectsAttachmentCountAboveFrozenLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projectionWithAttachments(
            $this->publicAttachments(ContentRevision::MAX_ATTACHMENTS + 1),
        );
    }

    public function testDraftRevisionCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projectionFromProof(
            $this->publishedItem(),
            $this->publishedRevision(status: ContentStatus::Draft),
        );
    }

    public function testPrivateRevisionCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projectionFromProof(
            $this->publishedItem(),
            $this->publishedRevision(visibility: ContentVisibility::Private),
        );
    }

    public function testPrivatePublishedHeadCannotForgePublicRevisionProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $item = new ContentItem(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            currentRevision: 3,
            currentSlug: new ContentSlug('published-article'),
            currentStatus: ContentStatus::Published,
            currentVisibility: ContentVisibility::Private,
            publishedRevision: 3,
            publishedSlug: new ContentSlug('published-article'),
            publishedAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );

        $this->projectionFromProof($item, $this->publishedRevision());
    }

    public function testMismatchedPublishedRevisionCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projectionFromProof(
            $this->publishedItem(),
            $this->publishedRevision(revision: 2),
        );
    }

    public function testMismatchedTypeCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projectionFromProof(
            $this->publishedItem(),
            $this->publishedRevision(
                typeKey: new ContentTypeKey('event'),
                storageSchemaRef: 'content.type.event',
            ),
        );
    }

    public function testMismatchedStorageSchemaVersionCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projectionFromProof(
            $this->publishedItem(),
            $this->publishedRevision(storageSchemaVersion: 2),
        );
    }

    public function testMismatchedStorageRecordCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(),
            storageProjection: $this->storageProjection(
                ['title' => 'Title'],
                recordId: 'record-11111111111111111111111111111111',
            ),
            publicAttachments: [],
        );
    }

    public function testMismatchedStorageRecordVersionCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(),
            storageProjection: $this->storageProjection(['title' => 'Title'], recordVersion: 4),
            publicAttachments: [],
        );
    }

    public function testMismatchedStorageOwnerCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(),
            storageProjection: $this->storageProjection(
                ['title' => 'Title'],
                ownerRef: 'content:item:11111111-1111-1111-1111-111111111111',
            ),
            publicAttachments: [],
        );
    }

    public function testMismatchedStorageSchemaCannotForgePublicProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(),
            storageProjection: $this->storageProjection(
                ['title' => 'Title'],
                schemaVersion: 2,
            ),
            publicAttachments: [],
        );
    }

    public function testAttachmentFromAnotherRevisionCannotEnterProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(attachmentCount: 1),
            storageProjection: $this->storageProjection(['title' => 'Title']),
            publicAttachments: [
                $this->publicAttachment('filesystem:logical:stale', 'hero', 0, sourceRevision: 2),
            ],
        );
    }

    public function testAttachmentFromAnotherItemCannotEnterProjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(attachmentCount: 1),
            storageProjection: $this->storageProjection(['title' => 'Title']),
            publicAttachments: [
                $this->publicAttachment(
                    'filesystem:logical:other',
                    'hero',
                    0,
                    sourceItemRef: ContentItemRef::fromUuid('11111111-1111-1111-1111-111111111111'),
                ),
            ],
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function projection(array $fields): PublishedContentProjection
    {
        return PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion($fields),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(),
            storageProjection: $this->storageProjection($fields),
            publicAttachments: [
                $this->publicAttachment('filesystem:logical:018f6d52', 'hero', 0),
            ],
        );
    }

    /**
     * @param list<PublicContentAttachment> $attachments
     */
    private function projectionWithAttachments(array $attachments): PublishedContentProjection
    {
        return PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(
                attachmentCount: min(count($attachments), ContentRevision::MAX_ATTACHMENTS),
            ),
            storageProjection: $this->storageProjection(['title' => 'Title']),
            publicAttachments: $attachments,
        );
    }

    private function projectionFromProof(
        ContentItem $item,
        ContentRevision $revision,
    ): PublishedContentProjection {
        return PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(['title' => 'Title']),
            item: $item,
            revision: $revision,
            storageProjection: $this->storageProjection(['title' => 'Title']),
            publicAttachments: [],
        );
    }

    private function publishedItem(): ContentItem
    {
        return new ContentItem(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            currentRevision: 3,
            currentSlug: new ContentSlug('published-article'),
            currentStatus: ContentStatus::Published,
            currentVisibility: ContentVisibility::Public,
            publishedRevision: 3,
            publishedSlug: new ContentSlug('published-article'),
            publishedAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    private function publishedRevision(
        ContentStatus $status = ContentStatus::Published,
        ContentVisibility $visibility = ContentVisibility::Public,
        int $revision = 3,
        ?ContentTypeKey $typeKey = null,
        string $storageSchemaRef = 'content.type.article',
        int $storageSchemaVersion = 1,
        int $attachmentCount = 1,
    ): ContentRevision {
        $typeKey ??= new ContentTypeKey('article');

        return new ContentRevision(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            revision: $revision,
            typeKey: $typeKey,
            locale: new ContentLocale(),
            typeVersion: 1,
            storageSchemaRef: $storageSchemaRef,
            storageSchemaVersion: $storageSchemaVersion,
            storageRecordRef: self::RECORD_ID,
            storageRecordVersion: 3,
            slug: new ContentSlug('published-article'),
            status: $status,
            visibility: $visibility,
            attachmentCount: $attachmentCount,
            createdBy: 'user:test',
            correlationId: 'test:published-revision',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    /**
     * @return list<PublicContentAttachment>
     */
    private function publicAttachments(int $count): array
    {
        $attachments = [];

        for ($position = 0; $position < $count; ++$position) {
            $attachments[] = $this->publicAttachment(
                sprintf('filesystem:logical:%03d', $position),
                'gallery',
                $position,
            );
        }

        return $attachments;
    }

    private function publicAttachment(
        string $logicalFileRef,
        string $role,
        int $position,
        int $sourceRevision = 3,
        ?ContentItemRef $sourceItemRef = null,
    ): PublicContentAttachment {
        return PublicContentAttachment::fromInspection(
            reference: new ContentAttachmentReference(
                itemRef: $sourceItemRef ?? ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
                revision: $sourceRevision,
                position: $position,
                logicalFileRef: $logicalFileRef,
                role: $role,
            ),
            inspection: new ContentLogicalFileInspection(
                logicalFileRef: $logicalFileRef,
                exists: true,
                available: true,
                public: true,
                safeMetadata: ['mime_type' => 'image/png'],
            ),
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function storageProjection(
        array $values,
        string $recordId = self::RECORD_ID,
        int $recordVersion = 3,
        string $ownerRef = 'content:item:018f6d52-4ef8-7bc2-9c71-3f2f4c164001',
        int $schemaVersion = 1,
    ): StoragePublicProjection {
        return new StoragePublicProjection(
            ref: new StorageRecordVersionRef('content.type.article', $recordId, $recordVersion),
            ownerRef: $ownerRef,
            schema: new StorageSchemaVersionRef('content.type.article', $schemaVersion),
            values: $values,
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function typeVersion(array $fields): ContentTypeVersion
    {
        $typeKey = new ContentTypeKey('article');
        $definitions = [];

        foreach ($fields as $key => $value) {
            $propertyType = match (true) {
                is_int($value) => 'integer',
                is_bool($value) => 'boolean',
                default => 'string',
            };
            $definitions[] = new ContentFieldDefinition(
                $key,
                $propertyType,
                ContentFieldVisibility::Public,
                $key === 'title',
            );
        }

        $contract = new ContentProjectionContract(
            version: 1,
            titleField: 'title',
            snippetField: null,
            searchableFields: ['title'],
            fieldDefinitions: $definitions,
        );

        return new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('a', 64),
            fieldDefinitions: $definitions,
            projectionContract: $contract,
            safeMetadata: ['label' => 'Article'],
            createdBy: 'user:test',
            correlationId: 'test:published-projection',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }
}

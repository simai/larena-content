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
use Larena\Content\ValueObjects\ContentSearchProjection;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Content\ValueObjects\PublicContentAttachment;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Storage\Contracts\StoragePublicProjection;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use PHPUnit\Framework\TestCase;

final class ContentSearchProjectionContractTest extends TestCase
{
    private const string RECORD_ID = 'record-018f6d524ef87bc29c713f2f4c164001';

    public function testSearchProjectionUsesDeterministicPublicScalarMappingAndMetadataOnlyPayload(): void
    {
        $search = ContentSearchProjection::fromPublished(
            $this->published([
                'title' => "  Public title \n",
                'body' => str_repeat('x', 300),
                'priority' => 12,
                'featured' => false,
            ]),
        );

        self::assertSame('content.published_items', $search->providerId);
        self::assertSame('Public title', $search->title);
        self::assertSame(240, strlen($search->snippet));
        self::assertSame(
            'Public title '.str_repeat('x', 300).' 12 false',
            $search->searchableText,
        );
        self::assertSame('/content/article/published-article?locale=en', $search->locator);
        self::assertSame(
            ['type_key', 'slug', 'locale', 'content_revision', 'projection_version'],
            array_keys($search->payload),
        );
        self::assertArrayNotHasKey('secret', $search->payload);
        self::assertArrayNotHasKey('values', $search->payload);
        self::assertArrayNotHasKey('attachments', $search->payload);
    }

    public function testBlankTitleFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentSearchProjection::fromPublished(
            $this->published([
                'title' => " \n ",
                'body' => 'Body',
                'priority' => 12,
                'featured' => true,
            ]),
        );
    }

    public function testSearchableValueTypeMismatchFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentSearchProjection::fromPublished(
            $this->published([
                'title' => 'Title',
                'body' => 'Body',
                'priority' => '12',
                'featured' => true,
            ]),
        );
    }

    private function typeVersion(): ContentTypeVersion
    {
        $typeKey = new ContentTypeKey('article');
        $fields = [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public),
            new ContentFieldDefinition('body', 'string', ContentFieldVisibility::Public),
            new ContentFieldDefinition('priority', 'integer', ContentFieldVisibility::Public),
            new ContentFieldDefinition('featured', 'boolean', ContentFieldVisibility::Public),
            new ContentFieldDefinition('secret', 'string', ContentFieldVisibility::Private),
        ];

        return new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('b', 64),
            fieldDefinitions: $fields,
            projectionContract: new ContentProjectionContract(
                version: 1,
                titleField: 'title',
                snippetField: 'body',
                searchableFields: ['title', 'body', 'priority', 'featured'],
                fieldDefinitions: $fields,
            ),
            safeMetadata: ['label' => 'Article'],
            createdBy: 'user:test',
            correlationId: 'test:search-projection',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    /**
     * @param array<string, string|int|bool|null> $fields
     */
    private function published(array $fields): PublishedContentProjection
    {
        return PublishedContentProjection::fromPublishedRevision(
            typeVersion: $this->typeVersion(),
            item: $this->publishedItem(),
            revision: $this->publishedRevision(),
            storageProjection: new StoragePublicProjection(
                ref: new StorageRecordVersionRef('content.type.article', self::RECORD_ID, 3),
                ownerRef: 'content:item:018f6d52-4ef8-7bc2-9c71-3f2f4c164001',
                schema: new StorageSchemaVersionRef('content.type.article', 1),
                values: $fields,
            ),
            publicAttachments: [
                PublicContentAttachment::fromInspection(
                    reference: new ContentAttachmentReference(
                        itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
                        revision: 3,
                        position: 0,
                        logicalFileRef: '018f6d52-4ef8-7bc2-9c71-3f2f4c164002',
                        role: 'hero',
                    ),
                    inspection: new ContentLogicalFileInspection(
                        logicalFileRef: '018f6d52-4ef8-7bc2-9c71-3f2f4c164002',
                        exists: true,
                        available: true,
                        public: true,
                        safeMetadata: [
                            'public_id' => 'public-file-1',
                            'display_name' => 'Hero image',
                            'mime_type' => 'image/png',
                            'extension' => 'png',
                            'size_bytes' => 1024,
                            'alt_text' => null,
                        ],
                        persistent: true,
                    ),
                ),
            ],
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

    private function publishedRevision(): ContentRevision
    {
        return new ContentRevision(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            revision: 3,
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            typeVersion: 1,
            storageSchemaRef: 'content.type.article',
            storageSchemaVersion: 1,
            storageRecordRef: self::RECORD_ID,
            storageRecordVersion: 3,
            slug: new ContentSlug('published-article'),
            status: ContentStatus::Published,
            visibility: ContentVisibility::Public,
            attachmentCount: 1,
            createdBy: 'user:test',
            correlationId: 'test:search-projection',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }
}

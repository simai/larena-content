<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Fixtures;

use DateTimeImmutable;
use DateTimeZone;
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

final class ContentPlatformV1Fixture
{
    private const string ARTICLE_RECORD_ID = 'record-018f62c69d277d19b9b17cddfbd9a3e1';

    /**
     * @return list<ContentFieldDefinition>
     */
    public static function articleFields(): array
    {
        return [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('body', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('featured', 'boolean', ContentFieldVisibility::Public),
            new ContentFieldDefinition('internal_notes', 'string', ContentFieldVisibility::Private),
        ];
    }

    public static function articleProjectionContract(): ContentProjectionContract
    {
        $fields = self::articleFields();

        return new ContentProjectionContract(
            version: 1,
            titleField: 'title',
            snippetField: 'body',
            searchableFields: ['title', 'body', 'featured'],
            fieldDefinitions: $fields,
        );
    }

    public static function articleTypeVersion(): ContentTypeVersion
    {
        $typeKey = new ContentTypeKey('article');
        $fields = self::articleFields();

        return new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('a', 64),
            fieldDefinitions: $fields,
            projectionContract: new ContentProjectionContract(
                version: 1,
                titleField: 'title',
                snippetField: 'body',
                searchableFields: ['title', 'body', 'featured'],
                fieldDefinitions: $fields,
            ),
            safeMetadata: ['label' => 'Article'],
            createdBy: 'user:fixture',
            correlationId: 'fixture:article-type-v1',
            createdAt: new DateTimeImmutable('2026-07-19T08:00:00+00:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * @return list<ContentFieldDefinition>
     */
    public static function eventFields(): array
    {
        return [
            new ContentFieldDefinition('name', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('starts_at_epoch', 'integer', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('registration_open', 'boolean', ContentFieldVisibility::Public),
            new ContentFieldDefinition('organizer_notes', 'string', ContentFieldVisibility::AdminOnly),
        ];
    }

    public static function eventProjectionContract(): ContentProjectionContract
    {
        $fields = self::eventFields();

        return new ContentProjectionContract(
            version: 1,
            titleField: 'name',
            snippetField: null,
            searchableFields: ['name', 'starts_at_epoch', 'registration_open'],
            fieldDefinitions: $fields,
        );
    }

    public static function publishedArticle(): PublishedContentProjection
    {
        return PublishedContentProjection::fromPublishedRevision(
            typeVersion: self::articleTypeVersion(),
            item: self::publishedArticleItem(),
            revision: self::publishedArticleRevision(),
            storageProjection: self::publishedArticleStorageProjection(),
            publicAttachments: [
                PublicContentAttachment::fromInspection(
                    reference: new ContentAttachmentReference(
                        itemRef: ContentItemRef::fromUuid('018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1'),
                        revision: 3,
                        position: 0,
                        logicalFileRef: 'logical-file:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2',
                        role: 'hero',
                    ),
                    inspection: new ContentLogicalFileInspection(
                        logicalFileRef: 'logical-file:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2',
                        exists: true,
                        available: true,
                        public: true,
                        safeMetadata: [
                            'display_name' => 'Hero image',
                            'mime_type' => 'image/png',
                            'size_bytes' => 1024,
                        ],
                    ),
                ),
            ],
        );
    }

    public static function publishedArticleItem(): ContentItem
    {
        return new ContentItem(
            itemRef: ContentItemRef::fromUuid('018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1'),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale('en'),
            currentRevision: 3,
            currentSlug: new ContentSlug('first-article'),
            currentStatus: ContentStatus::Published,
            currentVisibility: ContentVisibility::Public,
            publishedRevision: 3,
            publishedSlug: new ContentSlug('first-article'),
            publishedAt: new DateTimeImmutable('2026-07-19T09:00:00+00:00', new DateTimeZone('UTC')),
        );
    }

    public static function publishedArticleRevision(): ContentRevision
    {
        return new ContentRevision(
            itemRef: ContentItemRef::fromUuid('018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1'),
            revision: 3,
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale('en'),
            typeVersion: 1,
            storageSchemaRef: 'content.type.article',
            storageSchemaVersion: 1,
            storageRecordRef: self::ARTICLE_RECORD_ID,
            storageRecordVersion: 3,
            slug: new ContentSlug('first-article'),
            status: ContentStatus::Published,
            visibility: ContentVisibility::Public,
            attachmentCount: 1,
            createdBy: 'user:fixture',
            correlationId: 'fixture:article-publish-v3',
            createdAt: new DateTimeImmutable('2026-07-19T09:00:00+00:00', new DateTimeZone('UTC')),
        );
    }

    public static function publishedArticleStorageProjection(): StoragePublicProjection
    {
        return new StoragePublicProjection(
            ref: new StorageRecordVersionRef(
                schemaId: 'content.type.article',
                recordId: self::ARTICLE_RECORD_ID,
                revision: 3,
            ),
            ownerRef: 'content:item:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1',
            schema: new StorageSchemaVersionRef('content.type.article', 1),
            values: [
                'title' => 'First article',
                'body' => 'A deterministic public summary.',
                'featured' => true,
            ],
        );
    }

    /**
     * @return array<string, array{positive: list<string>, negative: list<string>}>
     */
    public static function featureCoverage(): array
    {
        return [
            'content.type_registry' => [
                'positive' => ['article_and_event_share_one_type_contract'],
                'negative' => ['unknown_property_type_fails_closed'],
            ],
            'content.item_lifecycle' => [
                'positive' => ['create_read_list_update'],
                'negative' => ['stale_expected_revision_has_no_partial_success'],
            ],
            'content.revision_history' => [
                'positive' => ['immutable_edit_and_restore_append_revisions'],
                'negative' => ['implicit_mutable_storage_head_is_forbidden'],
            ],
            'content.slug_routing' => [
                'positive' => ['type_and_locale_scoped_route'],
                'negative' => ['cross_mode_slug_collision_fails_closed'],
            ],
            'content.attachment_binding' => [
                'positive' => ['logical_file_reference_only'],
                'negative' => ['private_or_unavailable_file_is_omitted'],
            ],
            'content.publication_projection' => [
                'positive' => ['exact_published_public_fields_only'],
                'negative' => ['draft_private_admin_and_stale_values_are_absent'],
            ],
            'content.search_projection' => [
                'positive' => ['published_projection_maps_to_search_contract'],
                'negative' => ['unpublish_and_rename_remove_stale_documents'],
            ],
        ];
    }
}

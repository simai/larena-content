<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Storage\Contracts\StorageRecordVersionRef;

final class CmsContentModelV1RuntimeTest extends TestCase
{
    private ContentRuntimeHarness $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = ContentRuntimeHarness::create();
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        parent::tearDown();
    }

    public function test_administrator_defines_all_cms_v1_fields_without_source_changes(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $target = $scenario->createArticle('relation-target');
        $this->runtime->insertFile(ContentRuntimeHarness::PUBLIC_FILE);

        $fields = $this->cmsFields();
        $this->runtime->types->create(
            new ContentTypeKey('catalog_entry'),
            $fields,
            new ContentProjectionContract(1, 'title', 'body', [
                'title', 'body', 'price', 'active', 'available_on',
            ], $fields),
            ['label' => 'Catalog entry'],
            $this->runtime->admin,
        );

        $item = $this->runtime->items->create(
            new ContentTypeKey('catalog_entry'),
            new ContentLocale('en'),
            new ContentSlug('first-entry'),
            ContentVisibility::Public,
            [
                'title' => 'First entry',
                'body' => "Long\nmultiline text",
                'price' => '12.3400',
                'active' => true,
                'available_on' => '2026-07-28',
                'download' => strtoupper(ContentRuntimeHarness::PUBLIC_FILE),
                'related_item' => strtoupper($target->itemRef->uuid()),
            ],
            $this->runtime->admin,
        );

        $revision = $this->runtime->items->revision($item->itemRef, 1, $this->runtime->admin);
        $stored = $this->runtime->ownerStorage->readAdminVersion(
            new StorageRecordVersionRef(
                $revision->storageSchemaRef,
                $revision->storageRecordRef,
                $revision->storageRecordVersion,
            ),
            $this->runtime->admin->actorRef,
        );

        self::assertSame('12.34', $stored->values['price']);
        self::assertSame('2026-07-28', $stored->values['available_on']);
        self::assertSame(ContentRuntimeHarness::PUBLIC_FILE, $stored->values['download']);
        self::assertSame($target->itemRef->uuid(), $stored->values['related_item']);
        self::assertSame(
            ['string', 'text', 'number', 'boolean', 'date', 'file', 'relation'],
            array_map(static fn (ContentFieldDefinition $field): string => $field->propertyType, $fields),
        );

        try {
            $this->runtime->items->publish($item->itemRef, 1, $this->runtime->admin);
            self::fail('A public relation to a draft target was published.');
        } catch (ContentRejected $exception) {
            self::assertSame('publication_projection_invalid', $exception->reasonCode());
        }

        $this->runtime->items->publish($target->itemRef, 1, $this->runtime->admin);
        $published = $this->runtime->items->publish($item->itemRef, 1, $this->runtime->admin);
        self::assertSame(2, $published->publishedRevision);
        self::assertSame('12.34', $this->runtime->published->readItem($item->itemRef)->publicFields['price']);
    }

    public function test_file_and_relation_references_fail_closed_at_owner_boundaries(): void
    {
        $fields = $this->cmsFields();
        $this->runtime->types->create(
            new ContentTypeKey('catalog_entry'),
            $fields,
            new ContentProjectionContract(1, 'title', 'body', ['title', 'body'], $fields),
            ['label' => 'Catalog entry'],
            $this->runtime->admin,
        );

        foreach ([
            ['download' => ContentRuntimeHarness::PUBLIC_FILE, 'related_item' => '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e8', 'reason' => 'content_file_reference_unavailable'],
        ] as $case) {
            try {
                $this->runtime->items->create(
                    new ContentTypeKey('catalog_entry'),
                    new ContentLocale('en'),
                    new ContentSlug('rejected-entry'),
                    ContentVisibility::Public,
                    [
                        'title' => 'Rejected', 'body' => 'Rejected', 'price' => '1',
                        'active' => true, 'available_on' => '2026-07-28',
                        'download' => $case['download'], 'related_item' => $case['related_item'],
                    ],
                    $this->runtime->admin,
                );
                self::fail('Unavailable owner reference was accepted.');
            } catch (ContentRejected $exception) {
                self::assertSame($case['reason'], $exception->reasonCode());
            }
        }

        $this->runtime->insertFile(ContentRuntimeHarness::PUBLIC_FILE);
        try {
            $this->runtime->items->create(
                new ContentTypeKey('catalog_entry'),
                new ContentLocale('en'),
                new ContentSlug('missing-relation'),
                ContentVisibility::Public,
                [
                    'title' => 'Rejected', 'body' => 'Rejected', 'price' => '1',
                    'active' => true, 'available_on' => '2026-07-28',
                    'download' => ContentRuntimeHarness::PUBLIC_FILE,
                    'related_item' => '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e8',
                ],
                $this->runtime->admin,
            );
            self::fail('Unavailable relation target was accepted.');
        } catch (ContentRejected $exception) {
            self::assertSame('content_relation_target_unavailable', $exception->reasonCode());
        }

        self::assertSame(0, $this->runtime->connection->table('larena_content_items')->count());
    }

    public function test_editor_submits_immutable_review_but_cannot_publish_and_reader_is_read_only(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $draft = $this->runtime->items->create(
            new ContentTypeKey('article'),
            new ContentLocale('en'),
            new ContentSlug('editor-draft'),
            ContentVisibility::Public,
            [
                'title' => 'Editor draft',
                'body' => 'Ready for review.',
                'featured' => false,
                'internal_notes' => 'private',
            ],
            $this->runtime->editor,
        );

        $review = $this->runtime->items->submitForReview(
            $draft->itemRef,
            1,
            $this->runtime->editor,
        );

        self::assertSame(2, $review->currentRevision);
        self::assertSame('review', $review->currentStatus->value);
        self::assertSame('draft', $this->runtime->items->revision(
            $draft->itemRef,
            1,
            $this->runtime->editor,
        )->status->value);
        self::assertSame('review', $this->runtime->items->revision(
            $draft->itemRef,
            2,
            $this->runtime->editor,
        )->status->value);
        self::assertSame(1, $this->runtime->contentAuditCount('content.item.submitted_for_review'));

        try {
            $this->runtime->items->publish($draft->itemRef, 2, $this->runtime->editor);
            self::fail('Editor unexpectedly published Content.');
        } catch (AccessMutationRejected $exception) {
            self::assertSame('access_actor_forbidden', $exception->reasonCode);
        }

        try {
            $this->runtime->items->update(
                $draft->itemRef,
                2,
                new ContentSlug('reader-write'),
                ContentVisibility::Public,
                ['title' => 'No', 'body' => 'No', 'featured' => false, 'internal_notes' => 'No'],
                $this->runtime->reader,
            );
            self::fail('Reader unexpectedly updated Content.');
        } catch (AccessMutationRejected $exception) {
            self::assertSame('access_actor_forbidden', $exception->reasonCode);
        }

        $published = $this->runtime->items->publish($draft->itemRef, 2, $this->runtime->admin);
        self::assertSame('published', $published->currentStatus->value);
        self::assertSame(3, $published->publishedRevision);
    }

    /** @return list<ContentFieldDefinition> */
    private function cmsFields(): array
    {
        return [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('body', 'text', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('price', 'number', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('active', 'boolean', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('available_on', 'date', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('download', 'file', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('related_item', 'relation', ContentFieldVisibility::Public, true),
        ];
    }
}

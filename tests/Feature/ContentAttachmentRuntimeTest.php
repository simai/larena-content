<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\ValueObjects\ContentAttachmentPlacement;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;

final class ContentAttachmentRuntimeTest extends TestCase
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

    public function test_attachment_manifest_is_revisioned_and_public_projection_filters_private_files(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $scenario->insertAttachmentCorpus();
        $item = $scenario->createArticle();

        $item = $this->runtime->items->attach(
            $item->itemRef,
            1,
            ContentRuntimeHarness::PUBLIC_FILE,
            'hero',
            $this->runtime->actor(),
        );
        $item = $this->runtime->items->attach(
            $item->itemRef,
            2,
            ContentRuntimeHarness::PRIVATE_FILE,
            'download',
            $this->runtime->actor(),
        );
        $item = $this->runtime->items->reorder(
            $item->itemRef,
            3,
            [
                new ContentAttachmentPlacement(ContentRuntimeHarness::PRIVATE_FILE, 'download', 0),
                new ContentAttachmentPlacement(ContentRuntimeHarness::PUBLIC_FILE, 'hero', 1),
            ],
            $this->runtime->actor(),
        );
        $published = $this->runtime->items->publish(
            $item->itemRef,
            4,
            $this->runtime->actor(),
        );

        self::assertSame(5, $published->currentRevision);
        self::assertSame(2, $this->runtime->items->revision(
            $published->itemRef,
            5,
            $this->runtime->actor(),
        )->attachmentCount);
        $projection = $this->runtime->published->read(
            new ContentTypeKey('article'),
            new ContentSlug('first-article'),
            new ContentLocale('en'),
        );
        self::assertCount(1, $projection->publicAttachments);
        self::assertSame(ContentRuntimeHarness::PUBLIC_FILE, $projection->publicAttachments[0]->logicalFileRef);
        self::assertSame(0, $projection->publicAttachments[0]->position);
        self::assertArrayNotHasKey('storage_key', $projection->publicAttachments[0]->metadata);

        $draft = $this->runtime->items->detach(
            $published->itemRef,
            5,
            ContentRuntimeHarness::PRIVATE_FILE,
            'download',
            $this->runtime->actor(),
        );
        self::assertSame(6, $draft->currentRevision);
        self::assertSame(5, $draft->publishedRevision);
        self::assertCount(2, $this->runtime->items->attachments(
            $draft->itemRef,
            5,
            $this->runtime->actor(),
        ));
        self::assertCount(1, $this->runtime->items->attachments(
            $draft->itemRef,
            6,
            $this->runtime->actor(),
        ));
    }

    public function test_missing_persisted_attachment_row_fails_public_read_without_healing_manifest(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $scenario->insertAttachmentCorpus();
        $item = $scenario->createArticle('missing-attachment');
        $item = $this->runtime->items->attach(
            $item->itemRef,
            1,
            ContentRuntimeHarness::PUBLIC_FILE,
            'hero',
            $this->runtime->actor(),
        );
        $item = $this->runtime->items->publish(
            $item->itemRef,
            2,
            $this->runtime->actor(),
        );
        self::assertSame(3, $item->publishedRevision);

        $this->runtime->connection
            ->table('larena_content_item_revision_attachments')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 3)
            ->delete();

        try {
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('missing-attachment'),
                new ContentLocale('en'),
            );
            self::fail('A published revision with a missing attachment row leaked publicly.');
        } catch (ContentNotPublic $exception) {
            self::assertSame('content_not_public', $exception->reasonCode());
        }

        self::assertSame(1, $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 3)
            ->value('attachment_count'));
        self::assertSame(0, $this->runtime->connection
            ->table('larena_content_item_revision_attachments')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 3)
            ->count());
    }

    public function test_tampered_attachment_count_fails_closed_before_public_filtering(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $scenario->insertAttachmentCorpus();
        $item = $scenario->createArticle('tampered-count');
        $item = $this->runtime->items->attach(
            $item->itemRef,
            1,
            ContentRuntimeHarness::PRIVATE_FILE,
            'download',
            $this->runtime->actor(),
        );
        $item = $this->runtime->items->publish(
            $item->itemRef,
            2,
            $this->runtime->actor(),
        );
        $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 3)
            ->update(['attachment_count' => 2]);

        try {
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('tampered-count'),
                new ContentLocale('en'),
            );
            self::fail('A tampered attachment count was treated as a filterable private file.');
        } catch (ContentNotPublic $exception) {
            self::assertSame('content_not_public', $exception->reasonCode());
        }

        self::assertSame(2, $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 3)
            ->value('attachment_count'));
        self::assertSame(1, $this->runtime->connection
            ->table('larena_content_item_revision_attachments')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 3)
            ->count());
    }

    public function test_overflowing_persisted_attachment_manifest_is_not_truncated_to_the_declared_limit(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('overflowing-manifest');
        $item = $this->runtime->items->publish(
            $item->itemRef,
            1,
            $this->runtime->actor(),
        );
        self::assertSame(2, $item->publishedRevision);

        $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 2)
            ->update(['attachment_count' => 100]);

        $rows = [];
        for ($position = 0; $position <= 100; $position++) {
            $rows[] = [
                'item_ref' => $item->itemRef->value,
                'revision' => 2,
                'position' => $position,
                'logical_file_ref' => ContentRuntimeHarness::PUBLIC_FILE,
                'role' => sprintf('overflow-%03d', $position),
                'created_at' => '2026-07-19 00:00:00.000000',
            ];
        }
        $this->runtime->connection
            ->table('larena_content_item_revision_attachments')
            ->insert($rows);

        try {
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('overflowing-manifest'),
                new ContentLocale('en'),
            );
            self::fail('An overflowing published attachment manifest leaked publicly.');
        } catch (ContentNotPublic $exception) {
            self::assertSame('content_not_public', $exception->reasonCode());
        }

        try {
            $this->runtime->items->attachments(
                $item->itemRef,
                2,
                $this->runtime->actor(),
            );
            self::fail('An overflowing attachment manifest was exposed as an exact 100-row list.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('content', $exception->integration);
            self::assertSame('attachment_manifest_mismatch', $exception->reasonCode);
        }

        self::assertSame(101, $this->runtime->connection
            ->table('larena_content_item_revision_attachments')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 2)
            ->count());
    }
}

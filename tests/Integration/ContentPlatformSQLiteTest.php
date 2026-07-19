<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;

final class ContentPlatformSQLiteTest extends TestCase
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

    public function test_file_backed_sqlite_runs_the_shared_two_type_content_corpus(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createBothTypes();
        $article = $scenario->createArticle();
        $event = $scenario->createEvent();
        $article = $this->runtime->items->publish(
            $article->itemRef,
            1,
            $this->runtime->actor(),
        );

        self::assertSame(
            'First article',
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('first-article'),
                new ContentLocale('en'),
            )->publicFields['title'],
        );
        self::assertSame(2, $this->runtime->connection->table('larena_content_types')->count());
        self::assertSame(2, $this->runtime->connection->table('larena_content_items')->count());
        self::assertSame(3, $this->runtime->connection->table('larena_content_item_revisions')->count());
        self::assertSame(2, $this->runtime->connection->table('larena_storage_records')->count());
        self::assertSame(1, $this->runtime->connection->table('larena_search_documents')->count());
        self::assertSame('draft', $event->currentStatus->value);
        self::assertSame('published', $article->currentStatus->value);

        $before = $this->tableCounts();
        $provider = $this->runtime->dataview->forItems(
            new ContentItemQuery(limit: 100),
            $this->runtime->actor(),
        );
        self::assertFalse($provider->descriptor()->ownsCanonicalRecords);
        self::assertCount(2, $provider->rows());
        self::assertSame($before, $this->tableCounts());
        foreach ($provider->rows() as $row) {
            self::assertSame([
                'item_ref',
                'type_key',
                'locale',
                'current_revision',
                'current_slug',
                'current_status',
                'current_visibility',
                'published_revision',
                'published_slug',
                'published_at',
            ], array_keys($row));
            self::assertArrayNotHasKey('values', $row);
            self::assertArrayNotHasKey('attachments', $row);
        }
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach ([
            'larena_content_types',
            'larena_content_type_versions',
            'larena_content_items',
            'larena_content_item_revisions',
            'larena_content_item_revision_attachments',
            'larena_content_routes',
            'larena_storage_records',
            'larena_search_documents',
        ] as $table) {
            $counts[$table] = (int) $this->runtime->connection->table($table)->count();
        }

        return $counts;
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentSlug;

final class ContentConcurrencySqliteTest extends TestCase
{
    public function test_two_sqlite_runtime_instances_racing_from_one_revision_have_one_winner(): void
    {
        $first = ContentRuntimeHarness::create();
        $second = null;

        try {
            $scenario = new ContentPlatformScenario($first);
            $scenario->createArticleType();
            $item = $scenario->createArticle();
            $second = ContentRuntimeHarness::reopen($first->databasePath());

            self::assertSame(
                1,
                $second->items->read($item->itemRef, $second->actor())->currentRevision,
            );
            $winner = $first->items->update(
                $item->itemRef,
                1,
                new ContentSlug('winner'),
                ContentVisibility::Public,
                [
                    'title' => 'Winner',
                    'body' => 'CAS winner',
                    'featured' => true,
                    'internal_notes' => 'winner private',
                ],
                $first->actor(correlationId: 'sqlite-race-winner'),
            );
            self::assertSame(2, $winner->currentRevision);

            try {
                $second->items->update(
                    $item->itemRef,
                    1,
                    new ContentSlug('loser'),
                    ContentVisibility::Public,
                    [
                        'title' => 'Loser',
                        'body' => 'CAS loser',
                        'featured' => false,
                        'internal_notes' => 'loser private',
                    ],
                    $second->actor(correlationId: 'sqlite-race-loser'),
                );
                self::fail('Both stale SQLite writers committed.');
            } catch (ContentConflict $exception) {
                self::assertSame(1, $exception->expectedRevision);
                self::assertSame(2, $exception->currentRevision);
            }

            $head = $second->items->read($item->itemRef, $second->actor());
            self::assertSame(2, $head->currentRevision);
            self::assertSame('winner', $head->currentSlug->value);
            self::assertSame(2, $first->connection->table('larena_content_item_revisions')->count());
            self::assertSame(2, $first->connection->table('larena_storage_record_versions')->count());
        } finally {
            $second?->close();
            $first->close();
        }
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentSlug;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class ContentConcurrencyMySqlTest extends TestCase
{
    public function test_two_mysql_connections_from_one_head_have_exactly_one_cas_winner(): void
    {
        $mysql = ContentRuntimeMySqlTestSupport::create();
        $second = null;

        try {
            $scenario = new ContentPlatformScenario($mysql->runtime);
            $scenario->createArticleType();
            $item = $scenario->createArticle();
            $second = $mysql->secondRuntime();
            self::assertNotSame($mysql->runtime->connection, $second->connection);
            self::assertSame(1, $second->items->read(
                $item->itemRef,
                $second->actor(),
            )->currentRevision);

            $winner = $mysql->runtime->items->update(
                $item->itemRef,
                1,
                new ContentSlug('mysql-winner'),
                ContentVisibility::Public,
                [
                    'title' => 'MySQL winner',
                    'body' => 'One exact winner',
                    'featured' => true,
                    'internal_notes' => 'winner private',
                ],
                $mysql->runtime->actor(correlationId: 'mysql-cas-winner'),
            );
            self::assertSame(2, $winner->currentRevision);

            try {
                $second->items->update(
                    $item->itemRef,
                    1,
                    new ContentSlug('mysql-loser'),
                    ContentVisibility::Public,
                    [
                        'title' => 'MySQL loser',
                        'body' => 'Must roll back',
                        'featured' => false,
                        'internal_notes' => 'loser private',
                    ],
                    $second->actor(correlationId: 'mysql-cas-loser'),
                );
                self::fail('Both MySQL writers committed from one expected head.');
            } catch (ContentConflict $exception) {
                self::assertSame(1, $exception->expectedRevision);
                self::assertSame(2, $exception->currentRevision);
            }

            self::assertSame('mysql-winner', $second->items->read(
                $item->itemRef,
                $second->actor(),
            )->currentSlug->value);
            self::assertSame(2, $mysql->runtime->connection
                ->table('larena_content_item_revisions')
                ->where('item_ref', $item->itemRef->value)
                ->count());
            self::assertSame(2, $mysql->runtime->connection
                ->table('larena_storage_record_versions')
                ->count());
        } finally {
            $second?->close();
            $mysql->close();
        }
    }
}

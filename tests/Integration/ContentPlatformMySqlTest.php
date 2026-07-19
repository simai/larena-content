<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class ContentPlatformMySqlTest extends TestCase
{
    public function test_marker_owned_mysql_schema_rolls_back_reapplies_and_runs_two_type_corpus(): void
    {
        $mysql = ContentPlatformMySqlTestSupport::create();

        try {
            self::assertMatchesRegularExpression(
                '/\Alarena_content_v1_test_[a-f0-9]{12}\z/D',
                $mysql->databaseName(),
            );
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $mysql->identityHash());

            $migration = require dirname(__DIR__, 2)
                .'/database/migrations/2026_07_19_000001_create_larena_content_tables.php';
            $sentinel = [
                'type_key' => 'migration-sentinel',
                'current_version' => 1,
                'created_at' => '2026-07-19 12:00:00.000000',
                'updated_at' => '2026-07-19 12:00:00.000000',
            ];
            $mysql->runtime->connection
                ->table('larena_content_types')
                ->insert($sentinel);
            $beforeNoOp = $mysql->runtime->connection
                ->table('larena_content_types')
                ->where('type_key', 'migration-sentinel')
                ->first();
            $migration->up();
            self::assertEquals($beforeNoOp, $mysql->runtime->connection
                ->table('larena_content_types')
                ->where('type_key', 'migration-sentinel')
                ->first());
            self::assertSame(1, $mysql->runtime->connection
                ->table('larena_content_types')
                ->where('type_key', 'migration-sentinel')
                ->count());
            $mysql->runtime->connection
                ->table('larena_content_types')
                ->where('type_key', 'migration-sentinel')
                ->delete();

            $migration->down();
            foreach (ContentOwnedTableShapeGuard::tableNames() as $table) {
                self::assertFalse($mysql->runtime->connection->getSchemaBuilder()->hasTable($table));
            }
            $migration->up();
            foreach (ContentOwnedTableShapeGuard::tableNames() as $table) {
                self::assertTrue($mysql->runtime->connection->getSchemaBuilder()->hasTable($table));
            }

            $scenario = new ContentPlatformScenario($mysql->runtime);
            $scenario->createBothTypes();
            $article = $scenario->createArticle();
            $event = $scenario->createEvent();
            $article = $mysql->runtime->items->publish(
                $article->itemRef,
                1,
                $mysql->runtime->actor(),
            );

            self::assertSame('published', $article->currentStatus->value);
            self::assertSame('draft', $event->currentStatus->value);
            self::assertSame(
                'First article',
                $mysql->runtime->published->read(
                    new ContentTypeKey('article'),
                    new ContentSlug('first-article'),
                    new ContentLocale('en'),
                )->publicFields['title'],
            );
            self::assertSame(2, $mysql->runtime->connection->table('larena_storage_records')->count());
            self::assertSame(1, $mysql->runtime->connection->table('larena_search_documents')->count());
        } finally {
            $mysql->close();
        }
    }
}

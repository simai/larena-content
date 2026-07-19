<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Exceptions\ContentOwnedTableShapeRejected;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Tests\Support\ContentTestDatabase;
use Larena\Content\Tests\TestCase;

final class ContentMigrationShapeTest extends TestCase
{
    public function testCleanInstallExactNoOpAndEmptyRollbackReapply(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();

        try {
            self::assertSame([], $database->existingOwnedTables());
            $database->migrateUp();
            self::assertSame(
                ContentOwnedTableShapeGuard::tableNames(),
                $database->existingOwnedTables(),
            );

            $connection = $database->connection();
            $connection->table('larena_content_types')->insert([
                'type_key' => 'article',
                'current_version' => 1,
                'created_at' => '2026-07-19 12:00:00.000001',
                'updated_at' => '2026-07-19 12:00:00.000001',
            ]);

            $database->migrateUp();
            self::assertTrue(
                $connection
                    ->table('larena_content_types')
                    ->where('type_key', 'article')
                    ->exists(),
                'A complete exact topology must be an idempotent no-op.',
            );

            $connection->table('larena_content_types')->delete();
            $database->migrateDown();
            self::assertSame([], $database->existingOwnedTables());

            $database->migrateUp();
            self::assertSame(
                ContentOwnedTableShapeGuard::tableNames(),
                $database->existingOwnedTables(),
            );
        } finally {
            $database->close();
        }
    }

    public function testEmptyExactPartialTopologyIsDroppedThenRecreatedAsOneTopology(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();

        try {
            $database->migrateUp();
            $connection = $database->connection();
            $connection->statement(
                'CREATE TRIGGER content_types_repair_probe '
                .'AFTER INSERT ON larena_content_types BEGIN SELECT 1; END',
            );
            $connection->getSchemaBuilder()->drop('larena_content_routes');

            $database->migrateUp();

            self::assertSame(
                ContentOwnedTableShapeGuard::tableNames(),
                $database->existingOwnedTables(),
            );
            self::assertFalse(
                $connection
                    ->table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('name', 'content_types_repair_probe')
                    ->exists(),
                'The compatible empty subset was completed in place instead of being rebuilt.',
            );
        } finally {
            $database->close();
        }
    }

    public function testPopulatedPartialTopologyFailsBeforeFirstDdl(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();

        try {
            $database->migrateUp();
            $connection = $database->connection();
            $connection->getSchemaBuilder()->drop('larena_content_routes');
            $connection->table('larena_content_types')->insert([
                'type_key' => 'article',
                'current_version' => 1,
                'created_at' => '2026-07-19 12:00:00.000001',
                'updated_at' => '2026-07-19 12:00:00.000001',
            ]);
            $before = $database->existingOwnedTables();

            $this->expectShapeRejection(
                static fn () => $database->migrateUp(),
                'content_owned_table_partial_topology_contains_data',
                'types',
            );

            self::assertSame($before, $database->existingOwnedTables());
            self::assertTrue(
                $connection
                    ->table('larena_content_types')
                    ->where('type_key', 'article')
                    ->exists(),
            );
        } finally {
            $database->close();
        }
    }

    public function testIncompatiblePartialTopologyFailsBeforeFirstDdl(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();

        try {
            $schema = $database->connection()->getSchemaBuilder();
            $schema->create('larena_content_types', static function (Blueprint $table): void {
                $table->string('foreign_id')->primary();
            });

            $this->expectShapeRejection(
                static fn () => $database->migrateUp(),
                'content_owned_table_columns_incompatible',
                'types',
            );

            self::assertSame(
                ['larena_content_types'],
                $database->existingOwnedTables(),
            );
        } finally {
            $database->close();
        }
    }

    public function testRollbackRefusesPartialIncompatibleAndPopulatedTopologiesBeforeDrop(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();

        try {
            $database->migrateUp();
            $connection = $database->connection();
            $connection->table('larena_content_routes')->insert([
                'type_key' => 'article',
                'locale' => 'en',
                'slug' => 'kept',
                'item_ref' => 'content:item:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2',
                'current_revision' => 1,
                'published_revision' => null,
                'created_at' => '2026-07-19 12:00:00.000001',
                'updated_at' => '2026-07-19 12:00:00.000001',
            ]);

            $this->expectShapeRejection(
                static fn () => $database->migrateDown(),
                'content_rollback_would_lose_data',
                'routes',
            );
            self::assertSame(
                ContentOwnedTableShapeGuard::tableNames(),
                $database->existingOwnedTables(),
            );

            $connection->table('larena_content_routes')->delete();
            $connection
                ->getSchemaBuilder()
                ->table(
                    'larena_content_items',
                    static fn (Blueprint $table) => $table->dropIndex('content_item_listing_index'),
                );

            $this->expectShapeRejection(
                static fn () => $database->migrateDown(),
                'content_owned_table_secondary_index_incompatible',
                'items',
            );
            self::assertSame(
                ContentOwnedTableShapeGuard::tableNames(),
                $database->existingOwnedTables(),
            );
        } finally {
            $database->close();
        }
    }

    public function testRepositoryPersistsImmutableRowsAndUsesAuthoritativeHeadCas(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();

        try {
            $database->migrateUp();
            $repository = new DatabaseContentRepository($database->connection());
            $repository->assertCompleteCompatible();
            $now = '2026-07-19 12:00:00.000001';
            $itemRef = 'content:item:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2';

            $repository->insertTypeHead([
                'type_key' => 'article',
                'current_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $repository->insertTypeVersion([
                'type_key' => 'article',
                'version' => 1,
                'storage_schema_ref' => 'content.type.article',
                'storage_schema_version' => 1,
                'schema_hash' => str_repeat('a', 64),
                'projection_contract' => '{"version":1}',
                'safe_metadata' => '{}',
                'created_by' => 'user:admin_identity:1',
                'correlation_id' => 'content-test-1',
                'created_at' => $now,
            ]);
            $repository->insertItemHead([
                'item_ref' => $itemRef,
                'type_key' => 'article',
                'locale' => 'en',
                'current_revision' => 1,
                'current_slug' => 'first',
                'current_status' => 'draft',
                'current_visibility' => 'public',
                'published_revision' => null,
                'published_slug' => null,
                'published_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $repository->appendRevision([
                'item_ref' => $itemRef,
                'revision' => 1,
                'type_key' => 'article',
                'locale' => 'en',
                'type_version' => 1,
                'storage_schema_ref' => 'content.type.article',
                'storage_schema_version' => 1,
                'storage_record_ref' => 'record-0123456789abcdef0123456789abcdef',
                'storage_record_version' => 1,
                'slug' => 'first',
                'status' => 'draft',
                'visibility' => 'public',
                'attachment_count' => 1,
                'created_by' => 'user:admin_identity:1',
                'correlation_id' => 'content-test-1',
                'created_at' => $now,
            ], [[
                'logical_file_ref' => '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2',
                'role' => 'hero',
                'position' => 0,
            ]]);
            $repository->setRoute([
                'type_key' => 'article',
                'locale' => 'en',
                'slug' => 'first',
                'item_ref' => $itemRef,
                'current_revision' => 1,
                'published_revision' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $nextHead = [
                'current_revision' => 2,
                'current_slug' => 'second',
                'current_status' => 'draft',
                'current_visibility' => 'public',
                'published_revision' => null,
                'published_slug' => null,
                'published_at' => null,
                'updated_at' => '2026-07-19 12:00:01.000002',
            ];

            self::assertTrue($repository->compareAndSwapItemHead($itemRef, 1, $nextHead));
            self::assertFalse($repository->compareAndSwapItemHead($itemRef, 1, $nextHead));
            self::assertSame(2, $repository->itemRow($itemRef)['current_revision'] ?? null);
            self::assertSame(1, $repository->revisionRow($itemRef, 1)['revision'] ?? null);
            self::assertSame(
                '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2',
                $repository->attachmentRows($itemRef, 1)[0]['logical_file_ref'] ?? null,
            );
            self::assertSame(
                1,
                $repository->routeRow('article', 'en', 'first')['current_revision'] ?? null,
            );
        } finally {
            $database->close();
        }
    }

    /**
     * @param callable(): mixed $operation
     */
    private function expectShapeRejection(
        callable $operation,
        string $reasonCode,
        string $tableKey,
    ): void {
        try {
            $operation();
            self::fail('Content table-shape operation unexpectedly succeeded.');
        } catch (ContentOwnedTableShapeRejected $exception) {
            self::assertSame($reasonCode, $exception->reasonCode);
            self::assertSame($tableKey, $exception->tableKey);
            self::assertSame($reasonCode, $exception->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Database\SiteStructureTableShapeGuard;
use Larena\Content\Exceptions\ContentOwnedTableShapeRejected;
use Larena\Content\Tests\Support\ContentTestDatabase;
use Larena\Content\Tests\TestCase;

final class SiteStructureMigrationShapeTest extends TestCase
{
    public function test_empty_rollback_and_reapply_are_reproducible(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();
        try {
            $database->migrateUp();
            $database->migrateSiteStructureDown();
            foreach (SiteStructureTableShapeGuard::tableNames() as $table) {
                self::assertFalse($database->connection()->getSchemaBuilder()->hasTable($table));
            }
            $database->migrateSiteStructureUp();
            (new SiteStructureTableShapeGuard($database->connection()))->assertCompleteCompatible();
        } finally {
            $database->close();
        }
    }

    public function test_populated_rollback_fails_closed_before_drop(): void
    {
        $database = ContentTestDatabase::fileBackedSqlite();
        try {
            $database->migrateUp();
            $database->connection()->table('larena_content_site_structures')->insert([
                'structure_ref' => 'primary',
                'current_revision' => 1,
                'current_status' => 'draft',
                'published_revision' => null,
                'created_at' => '2026-07-28 00:00:00.000001',
                'updated_at' => '2026-07-28 00:00:00.000001',
            ]);
            try {
                $database->migrateSiteStructureDown();
                self::fail('A populated site-structure rollback unexpectedly dropped data.');
            } catch (ContentOwnedTableShapeRejected $exception) {
                self::assertSame('site_structure_rollback_would_lose_data', $exception->reasonCode);
            }
            self::assertTrue($database->connection()->getSchemaBuilder()->hasTable('larena_content_site_structures'));
            self::assertSame(1, $database->connection()->table('larena_content_site_structures')->count());
        } finally {
            $database->close();
        }
    }
}

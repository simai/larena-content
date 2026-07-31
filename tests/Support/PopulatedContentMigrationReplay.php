<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Support;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Database\SiteStructureTableShapeGuard;
use Larena\Content\Exceptions\ContentOwnedTableShapeRejected;

/**
 * Test-only snapshot/restore wrapper for the fail-closed Content migrations.
 * Production migrations remain destructive-data guards and are never relaxed.
 */
final class PopulatedContentMigrationReplay
{
    /** @return array<string, mixed> */
    public static function run(Connection $connection): array
    {
        $tables = [
            ...ContentOwnedTableShapeGuard::tableNames(),
            ...SiteStructureTableShapeGuard::tableNames(),
        ];
        $before = self::snapshot($connection, $tables);
        if (array_sum(array_column($before, 'count')) < 1) {
            throw new \RuntimeException('populated_content_replay_requires_rows');
        }

        $guardReason = null;
        try {
            self::invoke(self::siteMigration(), 'down');
        } catch (ContentOwnedTableShapeRejected $exception) {
            $guardReason = $exception->reasonCode;
        }
        if ($guardReason !== 'site_structure_rollback_would_lose_data') {
            throw new \RuntimeException('populated_content_replay_guard_not_proven');
        }
        if (self::snapshot($connection, $tables) !== $before) {
            throw new \RuntimeException('populated_content_replay_guard_mutated_rows');
        }

        foreach (array_reverse($tables) as $table) {
            $connection->table($table)->delete();
        }
        self::invoke(self::siteMigration(), 'down');
        self::invoke(self::contentMigration(), 'down');
        foreach ($tables as $table) {
            if ($connection->getSchemaBuilder()->hasTable($table)) {
                throw new \RuntimeException('populated_content_replay_down_incomplete');
            }
        }

        self::invoke(self::contentMigration(), 'up');
        self::invoke(self::siteMigration(), 'up');
        foreach ($tables as $table) {
            foreach ($before[$table]['rows'] as $row) {
                $connection->table($table)->insert($row);
            }
        }
        $after = self::snapshot($connection, $tables);
        if ($after !== $before) {
            throw new \RuntimeException('populated_content_replay_semantic_drift');
        }

        return [
            'guard_reason' => $guardReason,
            'table_count' => count($tables),
            'row_count' => array_sum(array_column($before, 'count')),
            'before_sha256' => self::digest($before),
            'after_sha256' => self::digest($after),
            'semantic_identity_preserved' => true,
        ];
    }

    /**
     * @param list<string> $tables
     * @return array<string, array{count:int,rows:list<array<string, mixed>>}>
     */
    public static function snapshot(Connection $connection, array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            if (!$connection->getSchemaBuilder()->hasTable($table)) {
                throw new \RuntimeException('populated_content_replay_table_missing');
            }
            $rows = array_map(
                static fn (object $row): array => self::normalize((array) $row),
                $connection->table($table)->get()->all(),
            );
            usort($rows, static fn (array $left, array $right): int => strcmp(self::encode($left), self::encode($right)));
            $snapshot[$table] = ['count' => count($rows), 'rows' => $rows];
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public static function digest(array $snapshot): string
    {
        return hash('sha256', self::encode($snapshot));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        ksort($row, SORT_STRING);
        foreach ($row as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $row[$key] = $value->format('Y-m-d H:i:s.u');
            }
        }

        return $row;
    }

    /** @param mixed $value */
    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function contentMigration(): Migration
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_07_19_000001_create_larena_content_tables.php';
    }

    private static function siteMigration(): Migration
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_07_28_000001_create_larena_content_site_structure_tables.php';
    }

    private static function invoke(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];
        if (!is_callable($callback)) {
            throw new \RuntimeException('populated_content_replay_migration_method_missing');
        }
        $callback();
    }
}

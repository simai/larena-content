<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Database\SiteStructureTableShapeGuard;
use RuntimeException;

final class ContentTestDatabase
{
    private bool $closed = false;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        private readonly Capsule $capsule,
        private readonly Connection $database,
        private readonly array $config,
        private readonly ?string $ownedSqlitePath,
    ) {
    }

    public static function fileBackedSqlite(
        ?string $path = null,
        bool $useNativeJson = false,
    ): self {
        $ownsPath = $path === null;
        $path ??= tempnam(sys_get_temp_dir(), 'larena-content-');

        if (!is_string($path) || $path === '') {
            throw new RuntimeException('content_test_database_tempfile_failed');
        }

        return self::fromConfig(
            [
                'driver' => 'sqlite',
                'database' => $path,
                'prefix' => '',
                'foreign_key_constraints' => false,
                'use_native_json' => $useNativeJson,
            ],
            $ownsPath ? $path : null,
        );
    }

    public static function inMemorySqlite(bool $useNativeJson = false): self
    {
        return self::fromConfig([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
            'use_native_json' => $useNativeJson,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, ?string $ownedSqlitePath = null): self
    {
        $container = new Container();
        Container::setInstance($container);

        $capsule = new Capsule($container);
        $capsule->addConnection($config, 'default');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $database = $capsule->getConnection('default');
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.connection', $database);
        $container->instance('db.schema', $database->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Schema::swap($database->getSchemaBuilder());

        return new self(
            $capsule,
            $database,
            $config,
            $ownedSqlitePath,
        );
    }

    public function connection(): Connection
    {
        return $this->database;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function migrateUp(): void
    {
        $migration = $this->migration();
        $this->invokeMigration($migration, 'up');
        $this->migrateSiteStructureUp();
    }

    public function migrateDown(): void
    {
        $schema = $this->database->getSchemaBuilder();
        $siteStructureTables = array_values(array_filter(
            SiteStructureTableShapeGuard::tableNames(),
            static fn (string $table): bool => $schema->hasTable($table),
        ));
        if ($siteStructureTables !== []) {
            $this->migrateSiteStructureDown();
        }
        $migration = $this->migration();
        $this->invokeMigration($migration, 'down');
    }

    public function migration(): Migration
    {
        return require dirname(__DIR__, 2)
            .'/database/migrations/2026_07_19_000001_create_larena_content_tables.php';
    }

    public function siteStructureMigration(): Migration
    {
        return require dirname(__DIR__, 2)
            .'/database/migrations/2026_07_28_000001_create_larena_content_site_structure_tables.php';
    }

    public function migrateSiteStructureUp(): void
    {
        $this->invokeMigration($this->siteStructureMigration(), 'up');
    }

    public function migrateSiteStructureDown(): void
    {
        $this->invokeMigration($this->siteStructureMigration(), 'down');
    }

    private function invokeMigration(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];
        if (!is_callable($callback)) {
            throw new RuntimeException('content_test_migration_'.$method.'_missing');
        }
        $callback();
    }

    /** @return list<string> */
    public function existingOwnedTables(): array
    {
        $schema = $this->database->getSchemaBuilder();

        return array_values(array_filter(
            ContentOwnedTableShapeGuard::tableNames(),
            static fn (string $table): bool => $schema->hasTable($table),
        ));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $this->capsule->getDatabaseManager()->disconnect('default');
        Facade::clearResolvedInstances();

        if ($this->ownedSqlitePath !== null) {
            foreach ([
                $this->ownedSqlitePath,
                $this->ownedSqlitePath.'-wal',
                $this->ownedSqlitePath.'-shm',
                $this->ownedSqlitePath.'-journal',
            ] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}

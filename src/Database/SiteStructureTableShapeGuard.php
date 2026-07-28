<?php

declare(strict_types=1);

namespace Larena\Content\Database;

use Illuminate\Database\Connection;
use Larena\Content\Exceptions\ContentOwnedTableShapeRejected;

final readonly class SiteStructureTableShapeGuard
{
    /** @var array<string, list<string>> */
    private const array SHAPES = [
        'larena_content_site_structures' => [
            'structure_ref', 'current_revision', 'current_status',
            'published_revision', 'created_at', 'updated_at',
        ],
        'larena_content_site_structure_revisions' => [
            'id', 'structure_ref', 'revision', 'status', 'nodes_json',
            'seo_json', 'created_by', 'correlation_id', 'created_at',
        ],
        'larena_content_redirects' => [
            'type_key', 'locale', 'source_slug', 'item_ref', 'created_at',
            'updated_at',
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::SHAPES);
    }

    public function assertCompleteCompatible(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (self::SHAPES as $table => $columns) {
            if (!$schema->hasTable($table)) {
                throw new ContentOwnedTableShapeRejected('site_structure_table_missing', $table);
            }
            $actual = $schema->getColumnListing($table);
            sort($actual, SORT_STRING);
            $expected = $columns;
            sort($expected, SORT_STRING);
            if ($actual !== $expected) {
                throw new ContentOwnedTableShapeRejected('site_structure_table_shape_incompatible', $table);
            }
        }
    }

    /** @return list<string> */
    public function preflightUp(): array
    {
        $schema = $this->connection->getSchemaBuilder();
        $existing = array_values(array_filter(
            self::tableNames(),
            static fn (string $table): bool => $schema->hasTable($table),
        ));
        if ($existing === []) {
            return [];
        }
        if (count($existing) === count(self::SHAPES)) {
            $this->assertCompleteCompatible();

            return self::tableNames();
        }
        foreach ($existing as $table) {
            if ($this->connection->table($table)->exists()) {
                throw new ContentOwnedTableShapeRejected('site_structure_partial_topology_contains_data', $table);
            }
        }

        return $existing;
    }

    public function preflightDown(): void
    {
        $this->assertCompleteCompatible();
        foreach (self::tableNames() as $table) {
            if ($this->connection->table($table)->exists()) {
                throw new ContentOwnedTableShapeRejected('site_structure_rollback_would_lose_data', $table);
            }
        }
    }
}

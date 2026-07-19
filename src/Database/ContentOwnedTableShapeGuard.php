<?php

declare(strict_types=1);

namespace Larena\Content\Database;

use Illuminate\Database\Connection;
use Larena\Content\Exceptions\ContentOwnedTableShapeRejected;
use Throwable;

final readonly class ContentOwnedTableShapeGuard
{
    public const string UP_CREATE_ALL = 'create_all';

    public const string UP_NO_OP = 'no_op';

    public const string UP_REPAIR_PARTIAL = 'repair_partial';

    /**
     * @var array<string, array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer64'|'integer32'|'json'|'text'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * }>
     */
    private const array TABLE_SHAPES = [
        'types' => [
            'table' => 'larena_content_types',
            'columns' => [
                'type_key',
                'current_version',
                'created_at',
                'updated_at',
            ],
            'primary' => [['type_key']],
            'unique' => [],
            'secondary' => [],
            'column_contracts' => [
                'type_key' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'current_version' => ['family' => 'integer64', 'nullable' => false, 'unsigned' => true],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
                'updated_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
        'type_versions' => [
            'table' => 'larena_content_type_versions',
            'columns' => [
                'id',
                'type_key',
                'version',
                'storage_schema_ref',
                'storage_schema_version',
                'schema_hash',
                'projection_contract',
                'safe_metadata',
                'created_by',
                'correlation_id',
                'created_at',
            ],
            'primary' => [['id']],
            'unique' => [
                ['type_key', 'version'],
                ['storage_schema_ref', 'storage_schema_version'],
            ],
            'secondary' => [],
            'column_contracts' => [
                'id' => [
                    'family' => 'integer64',
                    'nullable' => false,
                    'auto_increment' => true,
                    'unsigned' => true,
                ],
                'type_key' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'version' => ['family' => 'integer64', 'nullable' => false, 'unsigned' => true],
                'storage_schema_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'storage_schema_version' => [
                    'family' => 'integer64',
                    'nullable' => false,
                    'unsigned' => true,
                ],
                'schema_hash' => [
                    'family' => 'string',
                    'nullable' => false,
                    'length' => 64,
                    'fixed' => true,
                ],
                'projection_contract' => ['family' => 'text', 'nullable' => false],
                'safe_metadata' => ['family' => 'json', 'nullable' => false],
                'created_by' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'correlation_id' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
        'items' => [
            'table' => 'larena_content_items',
            'columns' => [
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
                'created_at',
                'updated_at',
            ],
            'primary' => [['item_ref']],
            'unique' => [],
            'secondary' => [
                ['type_key', 'locale', 'current_status', 'current_visibility', 'item_ref'],
                ['type_key', 'locale', 'published_revision', 'item_ref'],
            ],
            'column_contracts' => [
                'item_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'type_key' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'locale' => ['family' => 'string', 'nullable' => false, 'length' => 16],
                'current_revision' => ['family' => 'integer64', 'nullable' => false, 'unsigned' => true],
                'current_slug' => ['family' => 'string', 'nullable' => false, 'length' => 160],
                'current_status' => ['family' => 'string', 'nullable' => false, 'length' => 32],
                'current_visibility' => ['family' => 'string', 'nullable' => false, 'length' => 32],
                'published_revision' => ['family' => 'integer64', 'nullable' => true, 'unsigned' => true],
                'published_slug' => ['family' => 'string', 'nullable' => true, 'length' => 160],
                'published_at' => ['family' => 'timestamp', 'nullable' => true],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
                'updated_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
        'item_revisions' => [
            'table' => 'larena_content_item_revisions',
            'columns' => [
                'id',
                'item_ref',
                'revision',
                'type_key',
                'locale',
                'type_version',
                'storage_schema_ref',
                'storage_schema_version',
                'storage_record_ref',
                'storage_record_version',
                'slug',
                'status',
                'visibility',
                'attachment_count',
                'created_by',
                'correlation_id',
                'created_at',
            ],
            'primary' => [['id']],
            'unique' => [['item_ref', 'revision']],
            'secondary' => [
                ['storage_schema_ref', 'storage_record_ref', 'storage_record_version'],
            ],
            'column_contracts' => [
                'id' => [
                    'family' => 'integer64',
                    'nullable' => false,
                    'auto_increment' => true,
                    'unsigned' => true,
                ],
                'item_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'revision' => ['family' => 'integer64', 'nullable' => false, 'unsigned' => true],
                'type_key' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'locale' => ['family' => 'string', 'nullable' => false, 'length' => 16],
                'type_version' => ['family' => 'integer64', 'nullable' => false, 'unsigned' => true],
                'storage_schema_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'storage_schema_version' => [
                    'family' => 'integer64',
                    'nullable' => false,
                    'unsigned' => true,
                ],
                'storage_record_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'storage_record_version' => [
                    'family' => 'integer64',
                    'nullable' => false,
                    'unsigned' => true,
                ],
                'slug' => ['family' => 'string', 'nullable' => false, 'length' => 160],
                'status' => ['family' => 'string', 'nullable' => false, 'length' => 32],
                'visibility' => ['family' => 'string', 'nullable' => false, 'length' => 32],
                'attachment_count' => ['family' => 'integer32', 'nullable' => false, 'unsigned' => true],
                'created_by' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'correlation_id' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
        'item_revision_attachments' => [
            'table' => 'larena_content_item_revision_attachments',
            'columns' => [
                'id',
                'item_ref',
                'revision',
                'position',
                'logical_file_ref',
                'role',
                'created_at',
            ],
            'primary' => [['id']],
            'unique' => [
                ['item_ref', 'revision', 'position'],
                ['item_ref', 'revision', 'logical_file_ref', 'role'],
            ],
            'secondary' => [],
            'column_contracts' => [
                'id' => [
                    'family' => 'integer64',
                    'nullable' => false,
                    'auto_increment' => true,
                    'unsigned' => true,
                ],
                'item_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'revision' => ['family' => 'integer64', 'nullable' => false, 'unsigned' => true],
                'position' => ['family' => 'integer32', 'nullable' => false, 'unsigned' => true],
                'logical_file_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'role' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
        'routes' => [
            'table' => 'larena_content_routes',
            'columns' => [
                'type_key',
                'locale',
                'slug',
                'item_ref',
                'current_revision',
                'published_revision',
                'created_at',
                'updated_at',
            ],
            'primary' => [['type_key', 'locale', 'slug']],
            'unique' => [],
            'secondary' => [
                ['item_ref', 'current_revision'],
                ['item_ref', 'published_revision'],
            ],
            'column_contracts' => [
                'type_key' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'locale' => ['family' => 'string', 'nullable' => false, 'length' => 16],
                'slug' => ['family' => 'string', 'nullable' => false, 'length' => 160],
                'item_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'current_revision' => ['family' => 'integer64', 'nullable' => true, 'unsigned' => true],
                'published_revision' => ['family' => 'integer64', 'nullable' => true, 'unsigned' => true],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
                'updated_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
    ];

    /** @var array<string, array{unique: array<string, string>, secondary: array<string, string>}> */
    private const array NAMED_INDEXES = [
        'types' => [
            'unique' => [],
            'secondary' => [],
        ],
        'type_versions' => [
            'unique' => [
                'content_storage_schema_version_unique' => 'storage_schema_ref|storage_schema_version',
                'content_type_version_unique' => 'type_key|version',
            ],
            'secondary' => [],
        ],
        'items' => [
            'unique' => [],
            'secondary' => [
                'content_item_listing_index' => 'type_key|locale|current_status|current_visibility|item_ref',
                'content_item_published_index' => 'type_key|locale|published_revision|item_ref',
            ],
        ],
        'item_revisions' => [
            'unique' => [
                'content_revision_unique' => 'item_ref|revision',
            ],
            'secondary' => [
                'content_revision_storage_index' => 'storage_schema_ref|storage_record_ref|storage_record_version',
            ],
        ],
        'item_revision_attachments' => [
            'unique' => [
                'content_attachment_identity_unique' => 'item_ref|revision|logical_file_ref|role',
                'content_attachment_position_unique' => 'item_ref|revision|position',
            ],
            'secondary' => [],
        ],
        'routes' => [
            'unique' => [],
            'secondary' => [
                'content_route_current_item_index' => 'item_ref|current_revision',
                'content_route_published_item_index' => 'item_ref|published_revision',
            ],
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_values(array_map(
            static fn (array $shape): string => $shape['table'],
            self::TABLE_SHAPES,
        ));
    }

    /** @return list<string> */
    public static function dropOrder(): array
    {
        return array_reverse(self::tableNames());
    }

    /**
     * Validate all existing Content-owned tables before the caller performs
     * any DDL.
     *
     * @return array{action: self::UP_CREATE_ALL|self::UP_NO_OP|self::UP_REPAIR_PARTIAL, drop: list<string>}
     */
    public function preflightUp(): array
    {
        $this->assertSupportedDriver();
        $existing = $this->existingShapes();
        $this->assertShapesCompatible($existing);

        if ($existing === []) {
            return ['action' => self::UP_CREATE_ALL, 'drop' => []];
        }

        if (count($existing) === count(self::TABLE_SHAPES)) {
            return ['action' => self::UP_NO_OP, 'drop' => []];
        }

        foreach ($existing as $key => $shape) {
            if ($this->tableHasData($shape['table'], $key)) {
                throw new ContentOwnedTableShapeRejected(
                    'content_owned_table_partial_topology_contains_data',
                    $key,
                );
            }
        }

        $existingTables = array_fill_keys(array_column($existing, 'table'), true);
        $drop = array_values(array_filter(
            self::dropOrder(),
            static fn (string $table): bool => isset($existingTables[$table]),
        ));

        return ['action' => self::UP_REPAIR_PARTIAL, 'drop' => $drop];
    }

    public function assertCompleteCompatible(): void
    {
        $this->assertSupportedDriver();
        $existing = $this->existingShapes();
        $this->assertShapesCompatible($existing);

        if (count($existing) !== count(self::TABLE_SHAPES)) {
            throw new ContentOwnedTableShapeRejected('content_owned_table_topology_incompatible');
        }
    }

    /**
     * @return list<string> Safe reverse dependency order. An absent topology
     *     is already rolled back and therefore returns an empty list.
     */
    public function preflightDown(): array
    {
        $this->assertSupportedDriver();
        $existing = $this->existingShapes();

        if ($existing === []) {
            return [];
        }

        $this->assertShapesCompatible($existing);

        if (count($existing) !== count(self::TABLE_SHAPES)) {
            throw new ContentOwnedTableShapeRejected('content_owned_table_topology_incompatible');
        }

        foreach ($existing as $key => $shape) {
            if ($this->tableHasData($shape['table'], $key)) {
                throw new ContentOwnedTableShapeRejected(
                    'content_rollback_would_lose_data',
                    $key,
                );
            }
        }

        return self::dropOrder();
    }

    /**
     * @return array<string, array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer64'|'integer32'|'json'|'text'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * }>
     */
    private function existingShapes(): array
    {
        $schema = $this->connection->getSchemaBuilder();
        $existing = [];

        foreach (self::TABLE_SHAPES as $key => $shape) {
            try {
                $exists = $schema->hasTable($shape['table']);
            } catch (Throwable) {
                throw new ContentOwnedTableShapeRejected(
                    'content_owned_table_introspection_failed',
                    $key,
                );
            }

            if ($exists) {
                $existing[$key] = $shape;
            }
        }

        return $existing;
    }

    /**
     * @param array<string, array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer64'|'integer32'|'json'|'text'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * }> $shapes
     */
    private function assertShapesCompatible(array $shapes): void
    {
        foreach ($shapes as $key => $shape) {
            $this->assertShapeCompatible($key, $shape);
        }
    }

    /**
     * @param array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer64'|'integer32'|'json'|'text'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * } $shape
     */
    private function assertShapeCompatible(string $key, array $shape): void
    {
        $schema = $this->connection->getSchemaBuilder();

        try {
            $columnMetadata = $schema->getColumns($shape['table']);
            $actualIndexes = $schema->getIndexes($shape['table']);
        } catch (Throwable) {
            throw new ContentOwnedTableShapeRejected(
                'content_owned_table_introspection_failed',
                $key,
            );
        }

        $actualColumns = array_map(
            static fn (array $column): string => strtolower((string) $column['name']),
            $columnMetadata,
        );
        $expectedColumns = $shape['columns'];
        sort($actualColumns);
        sort($expectedColumns);

        if ($actualColumns !== $expectedColumns) {
            throw new ContentOwnedTableShapeRejected(
                'content_owned_table_columns_incompatible',
                $key,
            );
        }

        $metadataByName = [];
        foreach ($columnMetadata as $column) {
            $metadataByName[strtolower((string) $column['name'])] = $column;
        }

        foreach ($shape['column_contracts'] as $columnName => $contract) {
            if (
                !isset($metadataByName[$columnName])
                || !$this->columnContractMatches($metadataByName[$columnName], $contract)
            ) {
                throw new ContentOwnedTableShapeRejected(
                    'content_owned_table_column_contract_incompatible',
                    $key,
                );
            }
        }

        $normalized = [
            'primary' => [],
            'unique' => [],
            'secondary' => [],
        ];
        $namedIndexes = [
            'unique' => [],
            'secondary' => [],
        ];

        foreach ($actualIndexes as $index) {
            $columns = array_map(
                static fn (mixed $column): string => strtolower((string) $column),
                $index['columns'],
            );

            if ((bool) $index['primary']) {
                $normalized['primary'][] = $columns;
            } elseif ((bool) $index['unique']) {
                $normalized['unique'][] = $columns;
                $namedIndexes['unique'][strtolower((string) $index['name'])] = implode('|', $columns);
            } else {
                $normalized['secondary'][] = $columns;
                $namedIndexes['secondary'][strtolower((string) $index['name'])] = implode('|', $columns);
            }
        }

        foreach (['primary', 'unique', 'secondary'] as $type) {
            if (
                $this->normalizedCompositions($normalized[$type])
                !== $this->normalizedCompositions($shape[$type])
            ) {
                $reasonCode = match ($type) {
                    'primary' => 'content_owned_table_primary_index_incompatible',
                    'unique' => 'content_owned_table_unique_index_incompatible',
                    default => 'content_owned_table_secondary_index_incompatible',
                };

                throw new ContentOwnedTableShapeRejected($reasonCode, $key);
            }
        }

        foreach (['unique', 'secondary'] as $type) {
            $actual = $namedIndexes[$type];
            $expected = self::NAMED_INDEXES[$key][$type];
            ksort($actual);
            ksort($expected);

            if ($actual !== $expected) {
                throw new ContentOwnedTableShapeRejected(
                    $type === 'unique'
                        ? 'content_owned_table_unique_index_incompatible'
                        : 'content_owned_table_secondary_index_incompatible',
                    $key,
                );
            }
        }
    }

    /**
     * @param list<list<string>> $compositions
     * @return list<string>
     */
    private function normalizedCompositions(array $compositions): array
    {
        $normalized = array_map(
            static fn (array $columns): string => implode(
                '|',
                array_map(
                    static fn (string $column): string => strtolower($column),
                    $columns,
                ),
            ),
            $compositions,
        );
        sort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $column
     * @param array{
     *     family: 'string'|'integer64'|'integer32'|'json'|'text'|'timestamp',
     *     nullable: bool,
     *     auto_increment?: bool,
     *     length?: int,
     *     unsigned?: bool,
     *     fixed?: bool
     * } $contract
     */
    private function columnContractMatches(array $column, array $contract): bool
    {
        $driver = strtolower($this->connection->getDriverName());
        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $fullType = strtolower((string) ($column['type'] ?? ''));
        $expectedTypeName = match ($driver) {
            'mysql' => match ($contract['family']) {
                'string' => ($contract['fixed'] ?? false) ? 'char' : 'varchar',
                'integer64' => 'bigint',
                'integer32' => 'int',
                'json' => 'json',
                'text' => 'text',
                'timestamp' => 'timestamp',
            },
            'sqlite' => match ($contract['family']) {
                'string' => 'varchar',
                'integer64', 'integer32' => 'integer',
                'json' => $this->connection->getConfig('use_native_json') ? 'json' : 'text',
                'text' => 'text',
                'timestamp' => 'datetime',
            },
            default => null,
        };

        if (
            $expectedTypeName === null
            || $typeName !== $expectedTypeName
            || (bool) ($column['nullable'] ?? false) !== $contract['nullable']
        ) {
            return false;
        }

        if (
            (bool) ($column['auto_increment'] ?? false)
            !== ($contract['auto_increment'] ?? false)
        ) {
            return false;
        }

        if ($driver === 'mysql') {
            if (isset($contract['length'])) {
                if (
                    preg_match('/\((\d+)\)/', $fullType, $matches) !== 1
                    || (int) $matches[1] !== $contract['length']
                ) {
                    return false;
                }
            }

            if (
                str_contains($fullType, 'unsigned')
                !== ($contract['unsigned'] ?? false)
            ) {
                return false;
            }
        }

        return true;
    }

    private function tableHasData(string $table, string $key): bool
    {
        try {
            return $this->connection->table($table)->limit(1)->exists();
        } catch (Throwable) {
            throw new ContentOwnedTableShapeRejected(
                'content_owned_table_introspection_failed',
                $key,
            );
        }
    }

    private function assertSupportedDriver(): void
    {
        if (
            !in_array(
                strtolower($this->connection->getDriverName()),
                ['mysql', 'sqlite'],
                true,
            )
        ) {
            throw new ContentOwnedTableShapeRejected('content_owned_table_driver_unsupported');
        }
    }
}

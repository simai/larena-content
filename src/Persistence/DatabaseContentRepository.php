<?php

declare(strict_types=1);

namespace Larena\Content\Persistence;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentRejected;
use stdClass;

/**
 * Content-owned persistence primitives.
 *
 * This repository deliberately returns normalized rows rather than hydrating
 * cross-owner value objects. Typed values remain in Storage; file state,
 * Access decisions, Audit bodies and Search documents never enter these six
 * tables.
 */
final readonly class DatabaseContentRepository
{
    /** @var list<string> */
    private const array TYPE_HEAD_KEYS = [
        'type_key',
        'current_version',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const array TYPE_VERSION_KEYS = [
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
    ];

    /** @var list<string> */
    private const array ITEM_HEAD_KEYS = [
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
    ];

    /** @var list<string> */
    private const array ITEM_HEAD_MUTATION_KEYS = [
        'current_revision',
        'current_slug',
        'current_status',
        'current_visibility',
        'published_revision',
        'published_slug',
        'published_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const array REVISION_KEYS = [
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
    ];

    /** @var list<string> */
    private const array ATTACHMENT_INPUT_KEYS = [
        'logical_file_ref',
        'role',
        'position',
    ];

    /** @var list<string> */
    private const array ROUTE_KEYS = [
        'type_key',
        'locale',
        'slug',
        'item_ref',
        'current_revision',
        'published_revision',
        'created_at',
        'updated_at',
    ];

    /** @var array<string, list<string>> */
    private const array INTEGER_COLUMNS = [
        'larena_content_types' => [
            'current_version',
        ],
        'larena_content_type_versions' => [
            'id',
            'version',
            'storage_schema_version',
        ],
        'larena_content_items' => [
            'current_revision',
            'published_revision',
        ],
        'larena_content_item_revisions' => [
            'id',
            'revision',
            'type_version',
            'storage_schema_version',
            'storage_record_version',
            'attachment_count',
        ],
        'larena_content_item_revision_attachments' => [
            'id',
            'revision',
            'position',
        ],
        'larena_content_routes' => [
            'current_revision',
            'published_revision',
        ],
    ];

    public function __construct(private Connection $database)
    {
    }

    public function connection(): Connection
    {
        return $this->database;
    }

    public function assertCompleteCompatible(): void
    {
        (new ContentOwnedTableShapeGuard($this->database))->assertCompleteCompatible();
    }

    /** @return array<string, bool|int|string|null>|null */
    public function typeRow(string $typeKey, bool $forUpdate = false): ?array
    {
        $query = $this->database
            ->table('larena_content_types')
            ->where('type_key', $typeKey);

        return $this->one(
            $forUpdate ? $query->lockForUpdate() : $query,
            'larena_content_types',
        );
    }

    /** @return array<string, bool|int|string|null>|null */
    public function typeVersionRow(string $typeKey, int $version): ?array
    {
        return $this->one(
            $this->database
                ->table('larena_content_type_versions')
                ->where('type_key', $typeKey)
                ->where('version', $version),
            'larena_content_type_versions',
        );
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function typeVersionRows(
        string $typeKey,
        ?int $afterVersion,
        int $limit,
    ): array {
        $this->assertLimit($limit);
        $query = $this->database
            ->table('larena_content_type_versions')
            ->where('type_key', $typeKey)
            ->orderBy('version');

        if ($afterVersion !== null) {
            $query->where('version', '>', $afterVersion);
        }

        return $this->many(
            $query->limit($limit),
            'larena_content_type_versions',
        );
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function typeRows(?string $afterTypeKey, int $limit): array
    {
        $this->assertLimit($limit);
        $query = $this->database
            ->table('larena_content_types')
            ->orderBy('type_key');

        if ($afterTypeKey !== null) {
            $query->where('type_key', '>', $afterTypeKey);
        }

        return $this->many(
            $query->limit($limit),
            'larena_content_types',
        );
    }

    /** @param array<string, bool|int|string|null> $row */
    public function insertTypeHead(array $row): void
    {
        $this->assertExactKeys($row, self::TYPE_HEAD_KEYS, 'type head');
        $this->assertPositiveInteger($row['current_version'], 'current_version');
        $this->assertMutableRevision((int) $row['current_version']);

        if ((int) $row['current_version'] !== 1) {
            throw new ContentRejected('type_initial_version_invalid');
        }

        $this->database->table('larena_content_types')->insert($row);
    }

    /** @param array<string, bool|int|string|null> $row */
    public function insertTypeVersion(array $row): void
    {
        $this->assertExactKeys($row, self::TYPE_VERSION_KEYS, 'type version');
        $this->assertPositiveInteger($row['version'], 'version');
        $this->assertPositiveInteger($row['storage_schema_version'], 'storage_schema_version');
        $this->assertMutableRevision((int) $row['version']);

        if ((string) $row['storage_schema_ref'] !== 'content.type.'.(string) $row['type_key']) {
            throw new ContentRejected('storage_schema_ref_invalid');
        }

        if (preg_match('/\A[0-9a-f]{64}\z/D', (string) $row['schema_hash']) !== 1) {
            throw new ContentRejected('schema_hash_invalid');
        }

        $this->database->table('larena_content_type_versions')->insert($row);
    }

    public function compareAndSwapTypeHead(
        string $typeKey,
        int $expectedVersion,
        int $nextVersion,
        string $updatedAt,
    ): bool {
        $this->assertMutableRevision($expectedVersion);
        $this->assertPositiveInteger($nextVersion, 'next_version');

        if ($nextVersion !== $expectedVersion + 1) {
            throw new ContentRejected('type_next_version_invalid');
        }
        $this->assertMutableRevision($nextVersion);

        return $this->database
            ->table('larena_content_types')
            ->where('type_key', $typeKey)
            ->where('current_version', $expectedVersion)
            ->update([
                'current_version' => $nextVersion,
                'updated_at' => $updatedAt,
            ]) === 1;
    }

    /** @return array<string, bool|int|string|null>|null */
    public function itemRow(string $itemRef, bool $forUpdate = false): ?array
    {
        $query = $this->database
            ->table('larena_content_items')
            ->where('item_ref', $itemRef);

        return $this->one(
            $forUpdate ? $query->lockForUpdate() : $query,
            'larena_content_items',
        );
    }

    /**
     * @param array{type_key?: string, locale?: string, status?: string, visibility?: string} $filters
     * @return list<array<string, bool|int|string|null>>
     */
    public function itemRows(array $filters, ?string $afterItemRef, int $limit): array
    {
        $this->assertLimit($limit);
        $unknownFilters = array_diff(
            array_keys($filters),
            ['type_key', 'locale', 'status', 'visibility'],
        );

        if ($unknownFilters !== []) {
            throw new ContentRejected('item_query_filter_invalid');
        }

        $query = $this->database
            ->table('larena_content_items')
            ->orderBy('item_ref');

        if (isset($filters['type_key'])) {
            $query->where('type_key', $filters['type_key']);
        }
        if (isset($filters['locale'])) {
            $query->where('locale', $filters['locale']);
        }
        if (isset($filters['status'])) {
            $query->where('current_status', $filters['status']);
        }
        if (isset($filters['visibility'])) {
            $query->where('current_visibility', $filters['visibility']);
        }
        if ($afterItemRef !== null) {
            $query->where('item_ref', '>', $afterItemRef);
        }

        return $this->many(
            $query->limit($limit),
            'larena_content_items',
        );
    }

    /**
     * Locks every current item of one type in deterministic owner order for
     * the all-or-nothing schema migration boundary.
     *
     * @return list<array<string, bool|int|string|null>>
     */
    public function itemRowsForType(string $typeKey, bool $forUpdate = false): array
    {
        $query = $this->database
            ->table('larena_content_items')
            ->where('type_key', $typeKey)
            ->orderBy('item_ref');

        return $this->many(
            $forUpdate ? $query->lockForUpdate() : $query,
            'larena_content_items',
        );
    }

    /** @return array<string, bool|int|string|null>|null */
    public function revisionRow(string $itemRef, int $revision): ?array
    {
        return $this->one(
            $this->database
                ->table('larena_content_item_revisions')
                ->where('item_ref', $itemRef)
                ->where('revision', $revision),
            'larena_content_item_revisions',
        );
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function revisionRows(
        string $itemRef,
        ?int $afterRevision,
        int $limit,
    ): array {
        $this->assertLimit($limit);
        $query = $this->database
            ->table('larena_content_item_revisions')
            ->where('item_ref', $itemRef)
            ->orderBy('revision');

        if ($afterRevision !== null) {
            $query->where('revision', '>', $afterRevision);
        }

        return $this->many(
            $query->limit($limit),
            'larena_content_item_revisions',
        );
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function attachmentRows(string $itemRef, int $revision): array
    {
        return $this->many(
            $this->database
                ->table('larena_content_item_revision_attachments')
                ->where('item_ref', $itemRef)
                ->where('revision', $revision)
                ->orderBy('position')
                // Read one overflow sentinel so a corrupted persisted manifest
                // cannot be truncated into an apparently valid 100-row list.
                ->limit(101),
            'larena_content_item_revision_attachments',
        );
    }

    /** @return array<string, bool|int|string|null>|null */
    public function routeRow(
        string $typeKey,
        string $locale,
        string $slug,
        bool $forUpdate = false,
    ): ?array {
        $query = $this->database
            ->table('larena_content_routes')
            ->where('type_key', $typeKey)
            ->where('locale', $locale)
            ->where('slug', $slug);

        return $this->one(
            $forUpdate ? $query->lockForUpdate() : $query,
            'larena_content_routes',
        );
    }

    /**
     * @param list<array{type_key: string, locale: string, slug: string}> $identities
     * @return list<array<string, bool|int|string|null>>
     */
    public function lockRouteRows(array $identities): array
    {
        if (count($identities) > 100) {
            throw new ContentRejected('route_lock_limit_exceeded');
        }

        usort(
            $identities,
            static fn (array $left, array $right): int => [
                $left['type_key'],
                $left['locale'],
                $left['slug'],
            ] <=> [
                $right['type_key'],
                $right['locale'],
                $right['slug'],
            ],
        );

        $locked = [];
        $seen = [];

        foreach ($identities as $identity) {
            $key = implode("\0", [
                $identity['type_key'],
                $identity['locale'],
                $identity['slug'],
            ]);

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $row = $this->routeRow(
                $identity['type_key'],
                $identity['locale'],
                $identity['slug'],
                true,
            );

            if ($row !== null) {
                $locked[] = $row;
            }
        }

        return $locked;
    }

    /** @return array<string, bool|int|string|null>|null */
    public function publishedRouteRow(
        string $typeKey,
        string $locale,
        string $slug,
    ): ?array {
        return $this->one(
            $this->database
                ->table('larena_content_routes')
                ->where('type_key', $typeKey)
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->whereNotNull('published_revision'),
            'larena_content_routes',
        );
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function publishedItemRows(?string $afterItemRef, int $limit): array
    {
        $this->assertLimit($limit);
        $query = $this->database
            ->table('larena_content_items')
            ->whereNotNull('published_revision')
            ->orderBy('item_ref');

        if ($afterItemRef !== null) {
            $query->where('item_ref', '>', $afterItemRef);
        }

        return $this->many(
            $query->limit($limit),
            'larena_content_items',
        );
    }

    /** @param array<string, bool|int|string|null> $row */
    public function insertItemHead(array $row): void
    {
        $this->assertExactKeys($row, self::ITEM_HEAD_KEYS, 'item head');
        $this->assertPositiveInteger($row['current_revision'], 'current_revision');

        if ((int) $row['current_revision'] !== 1) {
            throw new ContentRejected('item_initial_revision_invalid');
        }

        $this->assertItemHeadState($row);
        $this->database->table('larena_content_items')->insert($row);
    }

    /**
     * @param array<string, bool|int|string|null> $row
     * @param list<array{logical_file_ref: string, role: string, position: int}> $attachments
     */
    public function appendRevision(array $row, array $attachments): void
    {
        $this->assertExactKeys($row, self::REVISION_KEYS, 'item revision');
        $this->assertPositiveInteger($row['revision'], 'revision');
        $this->assertPositiveInteger($row['type_version'], 'type_version');
        $this->assertPositiveInteger($row['storage_schema_version'], 'storage_schema_version');
        $this->assertPositiveInteger($row['storage_record_version'], 'storage_record_version');
        $this->assertMutableRevision((int) $row['revision']);
        $this->assertMutableRevision((int) $row['storage_record_version']);
        $this->assertStatusAndVisibility(
            (string) $row['status'],
            (string) $row['visibility'],
        );

        if ((int) $row['attachment_count'] !== count($attachments)) {
            throw new ContentRejected('attachment_count_mismatch');
        }
        if (count($attachments) > 100) {
            throw new ContentRejected('attachment_limit_exceeded');
        }

        $normalizedAttachments = $this->normalizeAttachments($attachments);

        $this->database->transaction(function () use ($row, $normalizedAttachments): void {
            $this->database
                ->table('larena_content_item_revisions')
                ->insert($row);

            if ($normalizedAttachments === []) {
                return;
            }

            $attachmentRows = [];
            foreach ($normalizedAttachments as $attachment) {
                $attachmentRows[] = [
                    'item_ref' => $row['item_ref'],
                    'revision' => $row['revision'],
                    'position' => $attachment['position'],
                    'logical_file_ref' => $attachment['logical_file_ref'],
                    'role' => $attachment['role'],
                    'created_at' => $row['created_at'],
                ];
            }

            $this->database
                ->table('larena_content_item_revision_attachments')
                ->insert($attachmentRows);
        });
    }

    /**
     * @param array<string, bool|int|string|null> $nextHead
     */
    public function compareAndSwapItemHead(
        string $itemRef,
        int $expectedRevision,
        array $nextHead,
    ): bool {
        $this->assertExactKeys($nextHead, self::ITEM_HEAD_MUTATION_KEYS, 'item head mutation');
        $this->assertMutableRevision($expectedRevision);
        $this->assertPositiveInteger($nextHead['current_revision'], 'current_revision');

        if ((int) $nextHead['current_revision'] !== $expectedRevision + 1) {
            throw new ContentRejected('item_next_revision_invalid');
        }
        $this->assertMutableRevision((int) $nextHead['current_revision']);

        $this->assertItemHeadState($nextHead);

        return $this->database
            ->table('larena_content_items')
            ->where('item_ref', $itemRef)
            ->where('current_revision', $expectedRevision)
            ->update($nextHead) === 1;
    }

    /**
     * Store one route reservation without ever transferring it to another
     * item. Both null pointers mean delete this item's now-unused reservation.
     *
     * @param array<string, bool|int|string|null> $row
     */
    public function setRoute(array $row): void
    {
        $this->assertExactKeys($row, self::ROUTE_KEYS, 'route reservation');
        $currentRevision = $row['current_revision'];
        $publishedRevision = $row['published_revision'];

        if ($currentRevision !== null) {
            $this->assertPositiveInteger($currentRevision, 'current_revision');
            $this->assertMutableRevision((int) $currentRevision);
        }
        if ($publishedRevision !== null) {
            $this->assertPositiveInteger($publishedRevision, 'published_revision');
            $this->assertMutableRevision((int) $publishedRevision);
        }

        $query = $this->database
            ->table('larena_content_routes')
            ->where('type_key', $row['type_key'])
            ->where('locale', $row['locale'])
            ->where('slug', $row['slug']);

        /** @var stdClass|null $existing */
        $existing = (clone $query)->lockForUpdate()->first();

        if (
            $existing instanceof stdClass
            && (string) $existing->item_ref !== (string) $row['item_ref']
        ) {
            throw new ContentRejected('slug_conflict');
        }

        if ($currentRevision === null && $publishedRevision === null) {
            if ($existing instanceof stdClass) {
                (clone $query)
                    ->where('item_ref', $row['item_ref'])
                    ->delete();
            }

            return;
        }

        if ($existing instanceof stdClass) {
            (clone $query)
                ->where('item_ref', $row['item_ref'])
                ->update([
                    'current_revision' => $currentRevision,
                    'published_revision' => $publishedRevision,
                    'updated_at' => $row['updated_at'],
                ]);

            return;
        }

        $this->database->table('larena_content_routes')->insert($row);
    }

    /**
     * @param list<array{logical_file_ref: string, role: string, position: int}> $attachments
     * @return list<array{logical_file_ref: string, role: string, position: int}>
     */
    private function normalizeAttachments(array $attachments): array
    {
        $seen = [];

        foreach ($attachments as $attachment) {
            $this->assertExactKeys($attachment, self::ATTACHMENT_INPUT_KEYS, 'attachment placement');

            if (
                preg_match(
                    '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',
                    $attachment['logical_file_ref'],
                ) !== 1
            ) {
                throw new ContentRejected('logical_file_ref_invalid');
            }
            if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $attachment['role']) !== 1) {
                throw new ContentRejected('attachment_role_invalid');
            }
            if ($attachment['position'] < 0) {
                throw new ContentRejected('attachment_position_invalid');
            }

            $identity = $attachment['logical_file_ref']."\0".$attachment['role'];
            if (isset($seen[$identity])) {
                throw new ContentRejected('attachment_identity_duplicate');
            }
            $seen[$identity] = true;
        }

        usort(
            $attachments,
            static fn (array $left, array $right): int => $left['position'] <=> $right['position'],
        );

        foreach ($attachments as $expectedPosition => $attachment) {
            if ($attachment['position'] !== $expectedPosition) {
                throw new ContentRejected('attachment_positions_not_contiguous');
            }
        }

        return $attachments;
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function assertItemHeadState(array $row): void
    {
        $this->assertStatusAndVisibility(
            (string) $row['current_status'],
            (string) $row['current_visibility'],
        );

        $currentRevision = (int) $row['current_revision'];
        $publishedRevision = $row['published_revision'];
        $publishedSlug = $row['published_slug'];
        $publishedAt = $row['published_at'];
        $publishedPresence = [
            $publishedRevision !== null,
            $publishedSlug !== null,
            $publishedAt !== null,
        ];

        if (count(array_unique($publishedPresence, SORT_REGULAR)) !== 1) {
            throw new ContentRejected('published_pointer_incomplete');
        }

        if ($publishedRevision !== null) {
            $this->assertPositiveInteger($publishedRevision, 'published_revision');
            if ((int) $publishedRevision > $currentRevision) {
                throw new ContentRejected('published_revision_invalid');
            }
        }

        if (
            $row['current_status'] === ContentStatus::Published->value
            && (
                (int) $publishedRevision !== $currentRevision
                || $publishedSlug !== $row['current_slug']
            )
        ) {
            throw new ContentRejected('published_head_pointer_invalid');
        }

        if (
            $row['current_status'] === ContentStatus::Draft->value
            && $publishedRevision !== null
            && (int) $publishedRevision === $currentRevision
        ) {
            throw new ContentRejected('draft_head_pointer_invalid');
        }
    }

    private function assertStatusAndVisibility(string $status, string $visibility): void
    {
        if (ContentStatus::tryFrom($status) === null) {
            throw new ContentRejected('status_invalid');
        }
        if (ContentVisibility::tryFrom($visibility) === null) {
            throw new ContentRejected('visibility_invalid');
        }
    }

    private function assertMutableRevision(int $revision): void
    {
        if ($revision < 1 || $revision > PHP_INT_MAX - 1) {
            throw new ContentRejected('revision_limit_exceeded');
        }
    }

    private function assertPositiveInteger(mixed $value, string $field): void
    {
        if (!is_int($value) || $value < 1) {
            throw new ContentRejected($field.'_invalid');
        }
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new ContentRejected('query_limit_invalid');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $expectedKeys
     */
    private function assertExactKeys(array $row, array $expectedKeys, string $label): void
    {
        $actualKeys = array_keys($row);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new ContentRejected(
                'persistence_row_shape_invalid',
                sprintf('Invalid Content %s persistence row.', $label),
            );
        }
    }

    /** @return array<string, bool|int|string|null>|null */
    private function one(Builder $query, string $table): ?array
    {
        /** @var stdClass|null $row */
        $row = $query->first();

        return $row instanceof stdClass
            ? $this->normalizeRow((array) $row, $table)
            : null;
    }

    /** @return list<array<string, bool|int|string|null>> */
    private function many(Builder $query, string $table): array
    {
        $rows = [];

        foreach ($query->get() as $row) {
            $rows[] = $this->normalizeRow((array) $row, $table);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, bool|int|string|null>
     */
    private function normalizeRow(array $row, string $table): array
    {
        foreach (self::INTEGER_COLUMNS[$table] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }

        $normalized = [];
        foreach ($row as $key => $value) {
            if ($value !== null && !is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new ContentRejected('persistence_row_shape_invalid');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}

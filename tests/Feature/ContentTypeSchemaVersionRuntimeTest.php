<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeVersionQuery;

final class ContentTypeSchemaVersionRuntimeTest extends TestCase
{
    private ContentRuntimeHarness $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = ContentRuntimeHarness::create();
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        parent::tearDown();
    }

    public function test_preview_create_publish_and_cross_schema_restore_preserve_owner_history(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $draft = $scenario->createArticle('schema-versioned');
        $published = $this->runtime->items->publish(
            $draft->itemRef,
            1,
            $this->runtime->actor(),
        );
        self::assertSame(2, $published->publishedRevision);

        $beforePreview = $this->mutationTableCounts();
        $fields = $this->versionTwoFields();
        $projection = $this->versionTwoProjection($fields);
        $preview = $this->runtime->types->previewVersion(
            new ContentTypeKey('article'),
            1,
            $fields,
            $projection,
            ['label' => 'Article', 'description' => 'Versioned articles'],
            $this->runtime->actor(correlationId: 'schema-preview'),
        );

        self::assertTrue($preview->compatible);
        self::assertSame('optional_additions', $preview->compatibilityClass);
        self::assertSame(1, $preview->addedOptionalFieldCount);
        self::assertSame(1, $preview->itemCount);
        self::assertSame([], $preview->reasonCodes);
        self::assertSame($beforePreview, $this->mutationTableCounts());
        self::assertSame(1, $this->runtime->contentAuditCount('content.type.version.previewed'));
        $previewAudit = $this->runtime->connection
            ->table('larena_audit_events')
            ->where('event_type', 'content.type.version.previewed')
            ->first();
        self::assertNotNull($previewAudit);
        $previewPayload = json_decode((string) $previewAudit->payload, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'actor_ref',
                'actor_type',
                'added_optional_count',
                'correlation_id',
                'field_count',
                'item_count',
                'operation',
                'source_version',
                'target_version',
                'timestamp',
                'type_key',
            ],
            array_keys($previewPayload),
        );

        $head = $this->runtime->types->createVersion(
            new ContentTypeKey('article'),
            1,
            $fields,
            $projection,
            ['label' => 'Article', 'description' => 'Versioned articles'],
            $this->runtime->actor(correlationId: 'schema-create'),
        );
        self::assertSame('article', $head->typeKey->value);
        self::assertSame(2, $head->currentVersion);
        $version = $this->runtime->types->version(
            new ContentTypeKey('article'),
            2,
            $this->runtime->actor(),
        );
        self::assertSame(2, $version->version);
        self::assertSame(2, $version->storageSchemaVersion);
        self::assertSame(
            [1, 2],
            array_map(
                static fn ($item): int => $item->version,
                $this->runtime->types->versions(
                    new ContentTypeVersionQuery(new ContentTypeKey('article'), limit: 100),
                    $this->runtime->actor(),
                )->items,
            ),
        );

        $migrated = $this->runtime->items->read($draft->itemRef, $this->runtime->actor());
        self::assertSame(3, $migrated->currentRevision);
        self::assertSame(2, $migrated->publishedRevision);
        self::assertSame('draft', $migrated->currentStatus->value);
        $migratedRevision = $this->runtime->items->revision(
            $draft->itemRef,
            3,
            $this->runtime->actor(),
        );
        self::assertSame(2, $migratedRevision->typeVersion);
        self::assertSame(2, $migratedRevision->storageSchemaVersion);

        $stillPublished = $this->runtime->published->read(
            new ContentTypeKey('article'),
            new ContentSlug('schema-versioned'),
            new ContentLocale('en'),
        );
        self::assertSame(2, $stillPublished->publishedRevision);
        self::assertArrayNotHasKey('summary', $stillPublished->publicFields);

        $updated = $this->runtime->items->update(
            $draft->itemRef,
            3,
            new ContentSlug('schema-versioned'),
            ContentVisibility::Public,
            [
                'title' => 'Second schema',
                'body' => 'Current schema body',
                'featured' => false,
                'internal_notes' => 'current private',
                'summary' => 'New optional field',
            ],
            $this->runtime->actor(),
        );
        self::assertSame(4, $updated->currentRevision);

        $restored = $this->runtime->items->restore(
            $draft->itemRef,
            1,
            4,
            $this->runtime->actor(correlationId: 'cross-schema-restore'),
        );
        self::assertSame(5, $restored->currentRevision);
        $restoredRevision = $this->runtime->items->revision(
            $draft->itemRef,
            5,
            $this->runtime->actor(),
        );
        self::assertSame(2, $restoredRevision->typeVersion);
        self::assertSame(2, $restoredRevision->storageSchemaVersion);
        $restoredStorage = $this->runtime->storage->readAdminVersion(
            new \Larena\Storage\Contracts\StorageRecordVersionRef(
                $restoredRevision->storageSchemaRef,
                $restoredRevision->storageRecordRef,
                $restoredRevision->storageRecordVersion,
            ),
            $this->runtime->actor(),
        );
        self::assertSame('First article', $restoredStorage->values['title']);
        self::assertArrayNotHasKey('summary', $restoredStorage->values);
        self::assertSame(1, $this->runtime->contentAuditCount('content.type.versioned'));
    }

    public function test_non_additive_and_stale_candidates_leave_schema_and_item_heads_unchanged(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('schema-reject');
        $before = $this->mutationTableCounts();

        $removed = array_slice(ContentPlatformV1Fixture::articleFields(), 0, 3);
        try {
            $this->runtime->types->createVersion(
                new ContentTypeKey('article'),
                1,
                $removed,
                new ContentProjectionContract(
                    1,
                    'title',
                    'body',
                    ['title', 'body', 'featured'],
                    $removed,
                ),
                ['label' => 'Article'],
                $this->runtime->actor(),
            );
            self::fail('A destructive Content schema candidate was accepted.');
        } catch (ContentRejected $exception) {
            self::assertSame('type_schema_incompatible', $exception->reasonCode());
        }
        self::assertSame($before, $this->mutationTableCounts());

        $fields = $this->versionTwoFields();
        $this->runtime->types->createVersion(
            new ContentTypeKey('article'),
            1,
            $fields,
            $this->versionTwoProjection($fields),
            ['label' => 'Article'],
            $this->runtime->actor(),
        );
        $afterVersion = $this->mutationTableCounts();
        try {
            $this->runtime->types->previewVersion(
                new ContentTypeKey('article'),
                1,
                $fields,
                $this->versionTwoProjection($fields),
                ['label' => 'Article'],
                $this->runtime->actor(),
            );
            self::fail('A stale Content type head was previewed.');
        } catch (ContentConflict $exception) {
            self::assertSame('stale_type_version', $exception->reasonCode());
            self::assertSame(1, $exception->expectedRevision);
            self::assertSame(2, $exception->currentRevision);
        }
        self::assertSame($afterVersion, $this->mutationTableCounts());
        self::assertSame(2, $this->runtime->items->read(
            $item->itemRef,
            $this->runtime->actor(),
        )->currentRevision);
    }

    public function test_same_fields_never_create_a_projection_or_metadata_only_type_version(): void
    {
        $fields = ContentPlatformV1Fixture::articleFields();
        $this->runtime->types->create(
            new ContentTypeKey('article'),
            $fields,
            ContentPlatformV1Fixture::articleProjectionContract(),
            ['label' => 'Article', 'description' => 'Article type'],
            $this->runtime->actor(),
        );
        $before = $this->mutationTableCounts();

        $candidates = [
            [
                ContentPlatformV1Fixture::articleProjectionContract(),
                ['description' => 'Article type', 'label' => 'Article'],
            ],
            [
                ContentPlatformV1Fixture::articleProjectionContract(),
                ['label' => 'Article v2', 'description' => 'Changed metadata only'],
            ],
            [
                new ContentProjectionContract(
                    1,
                    'title',
                    'title',
                    ['title', 'featured'],
                    $fields,
                ),
                ['label' => 'Article', 'description' => 'Article type'],
            ],
        ];

        foreach ($candidates as [$projection, $metadata]) {
            foreach (['previewVersion', 'createVersion'] as $operation) {
                try {
                    $this->runtime->types->{$operation}(
                        new ContentTypeKey('article'),
                        1,
                        $fields,
                        $projection,
                        $metadata,
                        $this->runtime->actor(),
                    );
                    self::fail("{$operation} accepted a same-field type version candidate.");
                } catch (ContentRejected $exception) {
                    self::assertSame('type_version_no_change', $exception->reasonCode());
                }
            }
        }

        self::assertSame($before, $this->mutationTableCounts());
    }

    /**
     * @return list<ContentFieldDefinition>
     */
    private function versionTwoFields(): array
    {
        return [
            ...ContentPlatformV1Fixture::articleFields(),
            new ContentFieldDefinition(
                'summary',
                'string',
                ContentFieldVisibility::Public,
            ),
        ];
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     */
    private function versionTwoProjection(array $fields): ContentProjectionContract
    {
        return new ContentProjectionContract(
            1,
            'title',
            'body',
            ['title', 'body', 'featured', 'summary'],
            $fields,
        );
    }

    /**
     * @return array<string, int>
     */
    private function mutationTableCounts(): array
    {
        $tables = [
            'larena_content_types',
            'larena_content_type_versions',
            'larena_content_items',
            'larena_content_item_revisions',
            'larena_content_item_revision_attachments',
            'larena_content_routes',
            'larena_storage_schemas',
            'larena_storage_schema_versions',
            'larena_storage_records',
            'larena_storage_record_versions',
            'larena_storage_schema_migration_plans',
            'larena_storage_schema_migration_plan_records',
            'larena_storage_schema_migration_results',
            'larena_storage_schema_migration_result_records',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->runtime->connection->table($table)->count();
        }

        return $counts;
    }
}

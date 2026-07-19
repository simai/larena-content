<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentRevisionQuery;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Storage\Contracts\StorageRecordVersionRef;

final class ContentRevisionRuntimeTest extends TestCase
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

    public function test_restore_appends_new_content_and_storage_versions_without_mutating_history(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $created = $scenario->createArticle();
        $updated = $this->runtime->items->update(
            $created->itemRef,
            1,
            new ContentSlug('second-title'),
            ContentVisibility::Public,
            [
                'title' => 'Second title',
                'body' => 'Second body',
                'featured' => false,
                'internal_notes' => 'second private',
            ],
            $this->runtime->actor(),
        );
        $restored = $this->runtime->items->restore(
            $updated->itemRef,
            1,
            2,
            $this->runtime->actor(correlationId: 'scenario-restore'),
        );

        self::assertSame(3, $restored->currentRevision);
        self::assertSame('first-article', $restored->currentSlug->value);
        self::assertSame('draft', $restored->currentStatus->value);

        $revisions = $this->runtime->items->revisions(
            new ContentRevisionQuery($restored->itemRef, limit: 100),
            $this->runtime->actor(),
        )->items;
        self::assertSame([1, 2, 3], array_map(
            static fn ($revision): int => $revision->revision,
            $revisions,
        ));
        self::assertSame([1, 2, 3], array_map(
            static fn ($revision): int => $revision->storageRecordVersion,
            $revisions,
        ));

        $latest = $revisions[2];
        $storage = $this->runtime->ownerStorage->readAdminVersion(
            new StorageRecordVersionRef(
                $latest->storageSchemaRef,
                $latest->storageRecordRef,
                $latest->storageRecordVersion,
            ),
            $this->runtime->admin->actorRef,
        );
        self::assertSame('First article', $storage->values['title']);
        self::assertSame('never public', $storage->values['internal_notes']);
        self::assertSame(3, $this->runtime->connection->table('larena_content_item_revisions')->count());
        self::assertSame(3, $this->runtime->connection->table('larena_storage_record_versions')->count());
    }

    public function test_corrupted_current_content_storage_schema_version_blocks_update_without_owner_write(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('corrupt-update');
        $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 1)
            ->update(['storage_schema_version' => 999]);
        $beforeStorage = $this->runtime->connection->table('larena_storage_record_versions')->count();

        try {
            $this->runtime->items->update(
                $item->itemRef,
                1,
                new ContentSlug('corrupt-update-next'),
                ContentVisibility::Public,
                [
                    'title' => 'Must not persist',
                    'body' => 'Must roll back',
                    'featured' => false,
                    'internal_notes' => 'private',
                ],
                $this->runtime->actor(),
            );
            self::fail('A corrupted Content-to-Storage schema pointer was updated.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('content', $exception->integration);
        }

        self::assertSame($beforeStorage, $this->runtime->connection
            ->table('larena_storage_record_versions')
            ->count());
        self::assertSame(1, $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->count());
    }

    public function test_corrupted_restore_target_storage_schema_version_blocks_restore_without_owner_write(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('corrupt-restore');
        $item = $this->runtime->items->update(
            $item->itemRef,
            1,
            new ContentSlug('corrupt-restore-next'),
            ContentVisibility::Public,
            [
                'title' => 'Second version',
                'body' => 'Second body',
                'featured' => false,
                'internal_notes' => 'private',
            ],
            $this->runtime->actor(),
        );
        $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 1)
            ->update(['storage_schema_version' => 999]);
        $beforeStorage = $this->runtime->connection->table('larena_storage_record_versions')->count();

        try {
            $this->runtime->items->restore(
                $item->itemRef,
                1,
                2,
                $this->runtime->actor(),
            );
            self::fail('A corrupted historical schema pointer was restored.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('content', $exception->integration);
        }

        self::assertSame($beforeStorage, $this->runtime->connection
            ->table('larena_storage_record_versions')
            ->count());
        self::assertSame(2, $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->count());
    }
}

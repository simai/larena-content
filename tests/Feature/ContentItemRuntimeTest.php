<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Storage\Contracts\StorageRecordVersionRef;

final class ContentItemRuntimeTest extends TestCase
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

    public function test_create_update_read_and_list_keep_typed_values_owned_by_storage(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $created = $scenario->createArticle();

        self::assertSame(1, $created->currentRevision);
        self::assertSame('draft', $created->currentStatus->value);
        self::assertNull($created->publishedRevision);

        $updated = $this->runtime->items->update(
            $created->itemRef,
            1,
            new ContentSlug('renamed-article'),
            ContentVisibility::Public,
            [
                'title' => 'Renamed article',
                'body' => 'Updated deterministic summary.',
                'featured' => false,
                'internal_notes' => 'updated private notes',
            ],
            $this->runtime->actor(correlationId: 'scenario-update'),
        );

        self::assertSame(2, $updated->currentRevision);
        self::assertSame('renamed-article', $updated->currentSlug->value);
        self::assertSame(
            $updated->itemRef->value,
            $this->runtime->items->read(
                $updated->itemRef,
                $this->runtime->actor(),
            )->itemRef->value,
        );
        self::assertSame(
            [$updated->itemRef->value],
            array_map(
                static fn ($item): string => $item->itemRef->value,
                $this->runtime->items->list(
                    new ContentItemQuery(limit: 100),
                    $this->runtime->actor(),
                )->items,
            ),
        );

        $revision = $this->runtime->items->revision(
            $updated->itemRef,
            2,
            $this->runtime->actor(),
        );
        $storage = $this->runtime->ownerStorage->readAdminVersion(
            new StorageRecordVersionRef(
                $revision->storageSchemaRef,
                $revision->storageRecordRef,
                $revision->storageRecordVersion,
            ),
            $this->runtime->admin->actorRef,
        );
        self::assertSame('Renamed article', $storage->values['title']);
        self::assertSame('updated private notes', $storage->values['internal_notes']);

        $contentRows = json_encode(
            $this->runtime->connection->table('larena_content_item_revisions')->get()->all(),
            JSON_THROW_ON_ERROR,
        );
        $contentAudit = json_encode(
            $this->runtime->connection
                ->table('larena_audit_events')
                ->where('source_package', 'larena/content')
                ->get()
                ->all(),
            JSON_THROW_ON_ERROR,
        );
        foreach (['Renamed article', 'updated private notes', 'Updated deterministic summary.'] as $secret) {
            self::assertStringNotContainsString($secret, $contentRows);
            self::assertStringNotContainsString($secret, $contentAudit);
        }
        self::assertSame(2, $this->runtime->connection->table('larena_storage_record_versions')->count());
    }
}

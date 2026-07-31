<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\Support\PopulatedContentMigrationReplay;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;

final class PopulatedContentMigrationReplayTest extends TestCase
{
    public function test_sqlite_snapshot_down_up_restore_preserves_populated_content_identity(): void
    {
        $runtime = ContentRuntimeHarness::create();
        try {
            $scenario = new ContentPlatformScenario($runtime);
            $scenario->createArticleType();
            $item = $scenario->createArticle('populated-replay');
            $item = $runtime->items->publish($item->itemRef, 1, $runtime->admin);
            $structure = $runtime->siteStructure->replace(0, [
                new SiteStructureNode(
                    '128f62c6-9d27-4d19-89b1-7cddfbd9a371',
                    null,
                    0,
                    'Populated replay',
                    true,
                    'content',
                    $item->itemRef,
                ),
            ], [
                new SiteSeoMetadata($item->itemRef, '/populated-replay', 'Replay', 'Preserved', 'index,follow'),
            ], $runtime->editor);
            $structure = $runtime->siteStructure->submitForReview($structure->revision, $runtime->editor);
            $runtime->siteStructure->publish($structure->revision, $runtime->admin);

            $externalTables = ['larena_storage_records', 'larena_storage_record_versions', 'larena_search_documents', 'larena_audit_events'];
            $externalBefore = PopulatedContentMigrationReplay::snapshot($runtime->connection, $externalTables);
            $receipt = PopulatedContentMigrationReplay::run($runtime->connection);
            $externalAfter = PopulatedContentMigrationReplay::snapshot($runtime->connection, $externalTables);

            self::assertSame('site_structure_rollback_would_lose_data', $receipt['guard_reason']);
            self::assertGreaterThanOrEqual(9, $receipt['table_count']);
            self::assertGreaterThanOrEqual(9, $receipt['row_count']);
            self::assertSame($receipt['before_sha256'], $receipt['after_sha256']);
            self::assertTrue($receipt['semantic_identity_preserved']);
            self::assertSame($externalBefore, $externalAfter);
            self::assertSame('First article', $runtime->published->read(
                $item->typeKey,
                $item->currentSlug,
                $item->locale,
            )->publicFields['title']);
            self::assertSame('/populated-replay', $runtime->siteStructure->published()['seo'][$item->itemRef->value]['canonical_path']);
        } finally {
            $runtime->close();
        }
    }
}

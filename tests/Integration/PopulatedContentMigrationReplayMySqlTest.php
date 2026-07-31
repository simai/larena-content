<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\PopulatedContentMigrationReplay;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class PopulatedContentMigrationReplayMySqlTest extends TestCase
{
    public function test_mysql_snapshot_down_up_restore_preserves_populated_content_identity_and_cleans_schema(): void
    {
        $mysql = ContentPlatformMySqlTestSupport::create();
        try {
            $scenario = new ContentPlatformScenario($mysql->runtime);
            $scenario->createArticleType();
            $item = $scenario->createArticle('mysql-populated-replay');
            $item = $mysql->runtime->items->publish($item->itemRef, 1, $mysql->runtime->admin);
            $structure = $mysql->runtime->siteStructure->replace(0, [
                new SiteStructureNode(
                    '128f62c6-9d27-4d19-89b1-7cddfbd9a372',
                    null,
                    0,
                    'MySQL populated replay',
                    true,
                    'content',
                    $item->itemRef,
                ),
            ], [
                new SiteSeoMetadata($item->itemRef, '/mysql-populated-replay', 'Replay', 'Preserved', 'index,follow'),
            ], $mysql->runtime->editor);
            $structure = $mysql->runtime->siteStructure->submitForReview($structure->revision, $mysql->runtime->editor);
            $mysql->runtime->siteStructure->publish($structure->revision, $mysql->runtime->admin);

            $externalTables = ['larena_storage_records', 'larena_storage_record_versions', 'larena_search_documents', 'larena_audit_events'];
            $externalBefore = PopulatedContentMigrationReplay::snapshot($mysql->runtime->connection, $externalTables);
            $receipt = PopulatedContentMigrationReplay::run($mysql->runtime->connection);
            $externalAfter = PopulatedContentMigrationReplay::snapshot($mysql->runtime->connection, $externalTables);

            self::assertSame('site_structure_rollback_would_lose_data', $receipt['guard_reason']);
            self::assertSame($receipt['before_sha256'], $receipt['after_sha256']);
            self::assertTrue($receipt['semantic_identity_preserved']);
            self::assertSame($externalBefore, $externalAfter);
            self::assertSame('First article', $mysql->runtime->published->read(
                $item->typeKey,
                $item->currentSlug,
                $item->locale,
            )->publicFields['title']);
            self::assertSame('/mysql-populated-replay', $mysql->runtime->siteStructure->published()['seo'][$item->itemRef->value]['canonical_path']);
        } finally {
            $mysql->close();
        }
    }
}

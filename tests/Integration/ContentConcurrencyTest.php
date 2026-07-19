<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;

final class ContentConcurrencyTest extends TestCase
{
    public function test_publish_compare_and_swap_rejects_the_second_same_head_writer(): void
    {
        $runtime = ContentRuntimeHarness::create();

        try {
            $scenario = new ContentPlatformScenario($runtime);
            $scenario->createArticleType();
            $item = $scenario->createArticle();
            $published = $runtime->items->publish(
                $item->itemRef,
                1,
                $runtime->actor(correlationId: 'publish-winner'),
            );
            self::assertSame(2, $published->currentRevision);

            try {
                $runtime->items->publish(
                    $item->itemRef,
                    1,
                    $runtime->actor(correlationId: 'publish-loser'),
                );
                self::fail('The second same-head publish committed.');
            } catch (ContentConflict $exception) {
                self::assertSame(2, $exception->currentRevision);
            }

            self::assertSame(2, $runtime->connection->table('larena_content_item_revisions')->count());
            self::assertSame(1, $runtime->connection->table('larena_search_documents')->count());
        } finally {
            $runtime->close();
        }
    }
}

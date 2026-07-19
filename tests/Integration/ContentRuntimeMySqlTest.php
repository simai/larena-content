<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Search\Contracts\SearchQuery;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class ContentRuntimeMySqlTest extends TestCase
{
    public function test_mysql_public_search_restart_and_failure_atomicity_use_one_database(): void
    {
        $mysql = ContentRuntimeMySqlTestSupport::create();
        $second = null;

        try {
            $scenario = new ContentPlatformScenario($mysql->runtime);
            $scenario->createArticleType();
            $published = $scenario->createArticle();
            $published = $mysql->runtime->items->publish(
                $published->itemRef,
                1,
                $mysql->runtime->actor(),
            );

            self::assertSame(
                'First article',
                $mysql->runtime->published->read(
                    new ContentTypeKey('article'),
                    new ContentSlug('first-article'),
                    new ContentLocale('en'),
                )->publicFields['title'],
            );
            self::assertCount(1, $mysql->runtime->searchIndex->query(new SearchQuery(
                term: 'deterministic',
                providerId: 'content.published_items',
            )));

            $second = $mysql->secondRuntime();
            self::assertSame(
                $published->itemRef->value,
                $second->items->read($published->itemRef, $second->actor())->itemRef->value,
            );
            self::assertSame(
                'First article',
                $second->published->read(
                    new ContentTypeKey('article'),
                    new ContentSlug('first-article'),
                    new ContentLocale('en'),
                )->publicFields['title'],
            );

            $draft = $scenario->createArticle('search-failure');
            $before = [
                'head_revision' => $draft->currentRevision,
                'revisions' => $mysql->runtime->connection
                    ->table('larena_content_item_revisions')
                    ->where('item_ref', $draft->itemRef->value)
                    ->count(),
                'documents' => $mysql->runtime->connection
                    ->table('larena_search_documents')
                    ->count(),
            ];
            $mysql->runtime->connection->unprepared(
                "CREATE TRIGGER fail_content_search_insert
                 BEFORE INSERT ON larena_search_documents
                 FOR EACH ROW
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'private_fixture_search_failure'",
            );
            try {
                $mysql->runtime->items->publish(
                    $draft->itemRef,
                    1,
                    $mysql->runtime->actor(),
                );
                self::fail('MySQL publication unexpectedly survived Search failure.');
            } catch (\Throwable $exception) {
                self::assertStringNotContainsString(
                    'private_fixture_search_failure',
                    $exception->getMessage(),
                );
            } finally {
                $mysql->runtime->connection->unprepared(
                    'DROP TRIGGER IF EXISTS fail_content_search_insert',
                );
            }

            self::assertSame($before['head_revision'], $mysql->runtime->items->read(
                $draft->itemRef,
                $mysql->runtime->actor(),
            )->currentRevision);
            self::assertSame($before['revisions'], $mysql->runtime->connection
                ->table('larena_content_item_revisions')
                ->where('item_ref', $draft->itemRef->value)
                ->count());
            self::assertSame($before['documents'], $mysql->runtime->connection
                ->table('larena_search_documents')
                ->count());
        } finally {
            $second?->close();
            $mysql->close();
        }
    }
}

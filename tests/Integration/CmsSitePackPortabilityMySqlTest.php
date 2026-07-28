<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Tests\TestCase;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
final class CmsSitePackPortabilityMySqlTest extends TestCase
{
    public function test_mysql_export_clean_import_replay_and_restart_preserve_published_page_and_file(): void
    {
        $sitePackRoot = sys_get_temp_dir().'/larena-sitepack-mysql-'.bin2hex(random_bytes(8));
        $source = ContentRuntimeMySqlTestSupport::create($sitePackRoot);
        $destination = ContentRuntimeMySqlTestSupport::create($sitePackRoot);

        try {
            $fields = [
                new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
                new ContentFieldDefinition('hero', 'file', ContentFieldVisibility::Public, true),
            ];
            $source->runtime->types->create(
                new ContentTypeKey('page'),
                $fields,
                new ContentProjectionContract(1, 'title', null, ['title'], $fields),
                ['label' => 'Page'],
                $source->runtime->actor(),
            );
            $source->runtime->insertFile(ContentRuntimeHarness::PUBLIC_FILE);
            $page = $source->runtime->items->create(
                new ContentTypeKey('page'),
                new ContentLocale('en'),
                new ContentSlug('mysql-portable'),
                ContentVisibility::Public,
                ['title' => 'MySQL portable', 'hero' => ContentRuntimeHarness::PUBLIC_FILE],
                $source->runtime->actor(),
            );
            $page = $source->runtime->items->submitForReview($page->itemRef, $page->currentRevision, $source->runtime->actor());
            $page = $source->runtime->items->publish($page->itemRef, $page->currentRevision, $source->runtime->actor());

            $exported = $source->runtime->sitePacks->export($source->runtime->actor());
            self::assertSame('verified', $source->runtime->sitePacks->verify($exported->packageRef, $source->runtime->actor())->status);
            self::assertSame(3, $destination->runtime->sitePacks->dryRun($exported->packageRef, $destination->runtime->actor())->counts['created_count']);
            $destination->runtime->sitePacks->import($exported->packageRef, $destination->runtime->actor());
            $replay = $destination->runtime->sitePacks->import($exported->packageRef, $destination->runtime->actor());
            self::assertSame(0, $replay->counts['created_count']);
            self::assertSame(3, $replay->counts['unchanged_count']);

            $restarted = $destination->secondRuntime();
            try {
                $restored = $restarted->published->read(
                    new ContentTypeKey('page'),
                    new ContentSlug('mysql-portable'),
                    new ContentLocale('en'),
                );
                self::assertSame($page->itemRef->value, $restored->itemRef->value);
                self::assertSame('MySQL portable', $restored->publicFields['title']);
                self::assertSame(ContentRuntimeHarness::PUBLIC_FILE, $restored->publicFields['hero']);
                self::assertSame(3, $restarted->connection->table('larena_content_item_revisions')->count());
                self::assertSame(1, $restarted->connection->table('larena_files')->count());
            } finally {
                $restarted->close(false);
            }
        } finally {
            $destination->close();
            $source->close();
            $this->removeTree($sitePackRoot);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}

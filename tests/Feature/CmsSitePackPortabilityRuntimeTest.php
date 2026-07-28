<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;

final class CmsSitePackPortabilityRuntimeTest extends TestCase
{
    private string $sitePackRoot;
    private ContentRuntimeHarness $source;
    private ContentRuntimeHarness $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitePackRoot = sys_get_temp_dir().'/larena-sitepack-'.bin2hex(random_bytes(8));
        $this->source = ContentRuntimeHarness::createWithSitePackRoot($this->sitePackRoot);
        $this->destination = ContentRuntimeHarness::createWithSitePackRoot($this->sitePackRoot);
    }

    protected function tearDown(): void
    {
        $this->source->close();
        $this->destination->close();
        $this->removeTree($this->sitePackRoot);
        parent::tearDown();
    }

    public function test_export_verify_dry_run_import_and_replay_preserve_cms_graph(): void
    {
        $categoryFields = [new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true)];
        $this->source->types->create(
            new ContentTypeKey('category'),
            $categoryFields,
            new ContentProjectionContract(1, 'title', null, ['title'], $categoryFields),
            ['label' => 'Category'],
            $this->source->actor(correlationId: 'sitepack-category-type'),
        );
        $articleFields = [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('hero', 'file', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('category', 'relation', ContentFieldVisibility::Public, true),
        ];
        $this->source->types->create(
            new ContentTypeKey('article'),
            $articleFields,
            new ContentProjectionContract(1, 'title', null, ['title'], $articleFields),
            ['label' => 'Article'],
            $this->source->actor(correlationId: 'sitepack-article-type'),
        );
        $category = $this->source->items->create(
            new ContentTypeKey('category'),
            new ContentLocale('en'),
            new ContentSlug('news'),
            ContentVisibility::Public,
            ['title' => 'News'],
            $this->source->actor(correlationId: 'sitepack-category'),
        );
        $category = $this->source->items->submitForReview(
            $category->itemRef,
            $category->currentRevision,
            $this->source->actor(correlationId: 'sitepack-category-review'),
        );
        $category = $this->source->items->publish(
            $category->itemRef,
            $category->currentRevision,
            $this->source->actor(correlationId: 'sitepack-category-publish'),
        );
        $this->source->insertFile(ContentRuntimeHarness::PUBLIC_FILE);
        $article = $this->source->items->create(
            new ContentTypeKey('article'),
            new ContentLocale('en'),
            new ContentSlug('portable-article'),
            ContentVisibility::Public,
            [
                'title' => 'Portable article',
                'hero' => ContentRuntimeHarness::PUBLIC_FILE,
                'category' => $category->itemRef->uuid(),
            ],
            $this->source->actor(correlationId: 'sitepack-article'),
        );
        $article = $this->source->items->attach(
            $article->itemRef,
            $article->currentRevision,
            ContentRuntimeHarness::PUBLIC_FILE,
            'hero',
            $this->source->actor(correlationId: 'sitepack-attach'),
        );
        $article = $this->source->items->submitForReview(
            $article->itemRef,
            $article->currentRevision,
            $this->source->actor(correlationId: 'sitepack-review'),
        );
        $article = $this->source->items->publish(
            $article->itemRef,
            $article->currentRevision,
            $this->source->actor(correlationId: 'sitepack-publish'),
        );

        $first = $this->source->sitePacks->export($this->source->actor(correlationId: 'sitepack-export-1'));
        $second = $this->source->sitePacks->export($this->source->actor(correlationId: 'sitepack-export-2'));
        self::assertSame($first->packageRef, $second->packageRef);
        self::assertSame($first->digest, $second->digest);
        self::assertSame('verified', $this->source->sitePacks->verify($first->packageRef, $this->source->actor())->status);
        self::assertSame(5, $this->source->sitePacks->dryRun($first->packageRef, $this->source->actor())->counts['unchanged_count']);

        $plan = $this->destination->sitePacks->dryRun($first->packageRef, $this->destination->actor());
        self::assertSame(5, $plan->counts['created_count']);
        $imported = $this->destination->sitePacks->import($first->packageRef, $this->destination->actor());
        self::assertSame('imported', $imported->status);
        self::assertSame(2, $this->destination->connection->table('larena_content_types')->count());
        self::assertSame(2, $this->destination->connection->table('larena_content_items')->count());
        self::assertSame(7, $this->destination->connection->table('larena_content_item_revisions')->count());
        self::assertSame(1, $this->destination->connection->table('larena_files')->count());

        $restored = $this->destination->published->read(
            new ContentTypeKey('article'),
            new ContentSlug('portable-article'),
            new ContentLocale('en'),
        );
        self::assertSame($article->itemRef->value, $restored->itemRef->value);
        self::assertSame('Portable article', $restored->publicFields['title']);
        self::assertSame($category->itemRef->uuid(), $restored->publicFields['category']);
        self::assertSame(ContentRuntimeHarness::PUBLIC_FILE, $restored->publicFields['hero']);
        self::assertCount(1, $restored->publicAttachments);

        $replay = $this->destination->sitePacks->import($first->packageRef, $this->destination->actor());
        self::assertSame(0, $replay->counts['created_count']);
        self::assertSame(5, $replay->counts['unchanged_count']);
        self::assertSame(7, $this->destination->connection->table('larena_content_item_revisions')->count());

        $payloads = $this->destination->connection->table('larena_audit_events')
            ->where('source_package', 'larena/content')
            ->whereIn('event_type', ['content.sitepack.import_planned', 'content.sitepack.imported'])
            ->pluck('payload');
        self::assertNotEmpty($payloads);
        foreach ($payloads as $payload) {
            self::assertIsString($payload);
            self::assertStringNotContainsString('storage_key', $payload);
            self::assertStringNotContainsString('file_bytes', $payload);
            self::assertStringNotContainsString('password', $payload);
            self::assertStringNotContainsString('token', $payload);
        }

        $database = $this->destination->databasePath();
        $this->destination->close(false);
        $reopened = ContentRuntimeHarness::reopen($database, true);
        try {
            $afterRestart = $reopened->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('portable-article'),
                new ContentLocale('en'),
            );
            self::assertSame($article->itemRef->value, $afterRestart->itemRef->value);
            self::assertSame('Portable article', $afterRestart->publicFields['title']);
            self::assertSame(7, $reopened->connection->table('larena_content_item_revisions')->count());
        } finally {
            $reopened->close();
        }
    }

    public function test_corrupt_package_fails_closed_and_is_audited(): void
    {
        $fields = [new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true)];
        $this->source->types->create(
            new ContentTypeKey('page'),
            $fields,
            new ContentProjectionContract(1, 'title', null, ['title'], $fields),
            ['label' => 'Page'],
            $this->source->actor(),
        );
        $report = $this->source->sitePacks->export($this->source->actor());
        $path = $this->sitePackRoot.'/'.$report->packageRef;
        file_put_contents($path, 'corrupt');

        try {
            $this->source->sitePacks->verify($report->packageRef, $this->source->actor());
            self::fail('Corrupt SitePack must fail closed.');
        } catch (\Larena\Content\Exceptions\ContentRejected $exception) {
            self::assertSame('sitepack_package_integrity_failed', $exception->reasonCode());
        }
        self::assertSame(1, $this->source->contentAuditCount('content.sitepack.failed'));
        self::assertSame(0, $this->destination->connection->table('larena_content_types')->count());
        self::assertSame(0, $this->destination->connection->table('larena_content_items')->count());
    }

    public function test_reader_and_editor_cannot_run_sitepack_operations(): void
    {
        $missingRef = 'cms-'.str_repeat('0', 64).'.sitepack';
        $attempts = [
            fn () => $this->source->sitePacks->export($this->source->reader),
            fn () => $this->source->sitePacks->verify($missingRef, $this->source->editor),
            fn () => $this->source->sitePacks->dryRun($missingRef, $this->source->reader),
            fn () => $this->source->sitePacks->import($missingRef, $this->source->editor),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Non-administrator SitePack operation must fail closed.');
            } catch (AccessMutationRejected $exception) {
                self::assertStringStartsWith('access_', $exception->reasonCode);
            }
        }

        self::assertSame(4, $this->source->accessDenialCount());
        self::assertSame([], glob($this->sitePackRoot.'/*.sitepack') ?: []);
    }

    public function test_incompatible_profile_fails_closed_before_any_write(): void
    {
        $fields = [new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true)];
        $this->source->types->create(
            new ContentTypeKey('page'),
            $fields,
            new ContentProjectionContract(1, 'title', null, ['title'], $fields),
            ['label' => 'Page'],
            $this->source->actor(),
        );
        $report = $this->source->sitePacks->export($this->source->actor());
        $path = $this->sitePackRoot.'/'.$report->packageRef;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $manifestBytes = $zip->getFromName('sitepack.manifest.json');
        self::assertIsString($manifestBytes);
        $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $manifest['annotations']['profileVersion'] = 2;
        $zip->addFromString('sitepack.manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        $zip->close();
        $digest = hash_file('sha256', $path);
        self::assertIsString($digest);
        $incompatibleRef = 'cms-'.$digest.'.sitepack';
        rename($path, $this->sitePackRoot.'/'.$incompatibleRef);

        try {
            $this->destination->sitePacks->import($incompatibleRef, $this->destination->actor());
            self::fail('Incompatible SitePack must fail closed.');
        } catch (\Larena\Content\Exceptions\ContentRejected $exception) {
            self::assertSame('sitepack_manifest_incompatible', $exception->reasonCode());
        }
        self::assertSame(0, $this->destination->connection->table('larena_content_types')->count());
        self::assertSame(0, $this->destination->connection->table('larena_files')->count());
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
}

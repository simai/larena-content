<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Storage\Runtime\VersionedStorage;
use ReflectionClass;

final class ContentFreshProcessRestartTest extends TestCase
{
    public function test_published_content_owner_data_search_and_access_survive_fresh_composition(): void
    {
        $first = ContentRuntimeHarness::create();
        $path = $first->databasePath();
        $cleanup = null;

        try {
            $scenario = new ContentPlatformScenario($first);
            $scenario->createBothTypes();
            $article = $scenario->createArticle();
            $scenario->createEvent();
            $article = $first->items->publish(
                $article->itemRef,
                1,
                $first->actor(),
            );
            $itemRef = $article->itemRef;
            $first->close(false);

            $ownerFile = (new ReflectionClass(VersionedStorage::class))->getFileName();
            self::assertIsString($ownerFile);
            $autoload = dirname($ownerFile, 5).'/autoload.php';
            self::assertFileExists($autoload);
            $worker = <<<'PHP'
require $argv[1];

$runtime = \Larena\Content\Tests\Support\ContentRuntimeHarness::reopen($argv[2]);
try {
    $itemRef = new \Larena\Content\ValueObjects\ContentItemRef($argv[3]);
    $projection = $runtime->published->read(
        new \Larena\Content\ValueObjects\ContentTypeKey('article'),
        new \Larena\Content\ValueObjects\ContentSlug('first-article'),
        new \Larena\Content\ValueObjects\ContentLocale('en'),
    );
    $head = $runtime->items->read($itemRef, $runtime->actor());
    $hits = $runtime->searchIndex->query(new \Larena\Search\Contracts\SearchQuery(
        term: 'deterministic',
        providerId: 'content.published_items',
    ));

    echo json_encode([
        'ok' => true,
        'item_ref_matches' => $head->itemRef->value === $itemRef->value
            && $projection->itemRef->value === $itemRef->value,
        'published_revision' => $projection->publishedRevision,
        'search_hits' => count($hits),
        'access_assignments' => (int) $runtime->connection
            ->table('larena_access_subject_roles')->count(),
        'storage_records' => (int) $runtime->connection
            ->table('larena_storage_records')->count(),
    ], JSON_THROW_ON_ERROR);
} finally {
    $runtime->close(false);
}
PHP;
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $worker, $autoload, $path, $itemRef->value],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 2),
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), is_string($stderr) ? $stderr : '');
            self::assertIsString($stdout);
            $receipt = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            self::assertSame([
                'ok' => true,
                'item_ref_matches' => true,
                'published_revision' => 2,
                'search_hits' => 1,
                'access_assignments' => 2,
                'storage_records' => 2,
            ], $receipt);

            $cleanup = ContentRuntimeHarness::reopen($path, true);
            $cleanup->close();
            $cleanup = null;
        } finally {
            $cleanup?->close();
            $first->close();
            if (is_file($path)) {
                $lateCleanup = ContentRuntimeHarness::reopen($path, true);
                $lateCleanup->close();
            }
        }
    }
}

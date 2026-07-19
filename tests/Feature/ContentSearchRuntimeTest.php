<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Illuminate\Database\Connection;
use Larena\Audit\Contracts\ConnectionBoundAuditEventPipeline;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Search\ContentSearchContract;
use Larena\Content\Search\DatabaseContentSearchSourceProvider;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\Tests\Support\ContentTestDatabase;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Storage\Contracts\VersionedStorage;
use PHPUnit\Framework\TestCase;

final class ContentSearchRuntimeTest extends TestCase
{
    /** @var list<ContentTestDatabase> */
    private array $databases = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->databases) as $database) {
            $database->close();
        }
        $this->databases = [];

        parent::tearDown();
    }

    public function testReindexReadsExactPublicProjectionWithBoundedStableCursor(): void
    {
        $connection = $this->database();
        $this->insertPublishedHead($connection);
        $projection = ContentPlatformV1Fixture::publishedArticle();
        $reader = new class($projection) implements PublishedContentReader {
            /** @var list<string> */
            public array $locators = [];

            public function __construct(private readonly PublishedContentProjection $projection)
            {
            }

            public function read(
                ContentTypeKey $typeKey,
                ContentSlug $slug,
                ContentLocale $locale,
            ): PublishedContentProjection {
                $this->locators[] = sprintf(
                    '%s/%s?locale=%s',
                    $typeKey->value,
                    $slug->value,
                    $locale->value,
                );

                return $this->projection;
            }
        };
        $source = new DatabaseContentSearchSourceProvider(
            new DatabaseContentRepository($connection),
            $reader,
            $this->participants($connection),
        );

        self::assertSame(ContentSearchContract::PROVIDER_ID, $source->providerId());
        self::assertTrue($source->descriptor()->isValid());
        self::assertSame('public', $source->descriptor()->accessScope);

        $batch = $source->readBatch(null, 100);

        self::assertFalse($batch->hasMore);
        self::assertSame(
            'content:item:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1',
            $batch->nextCursor,
        );
        self::assertCount(1, $batch->projections);
        self::assertSame('content.published_items', $batch->projections[0]->providerId);
        self::assertSame('First article', $batch->projections[0]->title);
        self::assertSame(
            '/content/article/first-article?locale=en',
            $batch->projections[0]->locator,
        );
        self::assertSame(
            ['article/first-article?locale=en'],
            $reader->locators,
        );

        $finished = $source->readBatch($batch->nextCursor, 100);
        self::assertSame([], $finished->projections);
        self::assertFalse($finished->hasMore);
        self::assertSame($batch->nextCursor, $finished->nextCursor);
    }

    public function testNonPublicProjectionIsOmittedAndCursorStillAdvances(): void
    {
        $connection = $this->database();
        $this->insertPublishedHead($connection);
        $source = new DatabaseContentSearchSourceProvider(
            new DatabaseContentRepository($connection),
            new class implements PublishedContentReader {
                public function read(
                    ContentTypeKey $typeKey,
                    ContentSlug $slug,
                    ContentLocale $locale,
                ): PublishedContentProjection {
                    throw new ContentNotPublic();
                }
            },
            $this->participants($connection),
        );

        $batch = $source->readBatch(null, 100);

        self::assertSame([], $batch->projections);
        self::assertFalse($batch->hasMore);
        self::assertSame(
            'content:item:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1',
            $batch->nextCursor,
        );
    }

    public function testProjectionIdentityMismatchFailsClosed(): void
    {
        $connection = $this->database();
        $this->insertPublishedHead(
            $connection,
            'content:item:11111111-1111-1111-1111-111111111111',
        );
        $source = new DatabaseContentSearchSourceProvider(
            new DatabaseContentRepository($connection),
            new class implements PublishedContentReader {
                public function read(
                    ContentTypeKey $typeKey,
                    ContentSlug $slug,
                    ContentLocale $locale,
                ): PublishedContentProjection {
                    return ContentPlatformV1Fixture::publishedArticle();
                }
            },
            $this->participants($connection),
        );

        try {
            $source->readBatch(null, 100);
            self::fail('A mismatched public projection must fail closed.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('search', $exception->integration);
            self::assertSame(
                'published_projection_identity_mismatch',
                $exception->reasonCode,
            );
        }
    }

    public function testReindexRejectsRowsAboveFrozenLimitBeforeDatabaseRead(): void
    {
        $connection = $this->database();
        $source = new DatabaseContentSearchSourceProvider(
            new DatabaseContentRepository($connection),
            new class implements PublishedContentReader {
                public function read(
                    ContentTypeKey $typeKey,
                    ContentSlug $slug,
                    ContentLocale $locale,
                ): PublishedContentProjection {
                    throw new \LogicException('The reader must not be called.');
                }
            },
            $this->participants($connection),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('content_search_reindex_limit_invalid');

        $source->readBatch(null, 101);
    }

    public function testParticipantMismatchFailsBeforeOwnedShapeOrContentSql(): void
    {
        $contentDatabase = ContentTestDatabase::inMemorySqlite();
        $otherDatabase = ContentTestDatabase::inMemorySqlite();
        $this->databases[] = $contentDatabase;
        $this->databases[] = $otherDatabase;
        $content = $contentDatabase->connection();
        $other = $otherDatabase->connection();
        $source = new DatabaseContentSearchSourceProvider(
            new DatabaseContentRepository($content),
            new class implements PublishedContentReader {
                public function read(
                    ContentTypeKey $typeKey,
                    ContentSlug $slug,
                    ContentLocale $locale,
                ): PublishedContentProjection {
                    throw new \LogicException('The reader must not be called.');
                }
            },
            $this->participants($content, $other),
        );

        try {
            $source->readBatch(null, 100);
            self::fail('A connection mismatch must fail before querying Content tables.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('connection', $exception->integration);
            self::assertSame(
                'participant_connection_mismatch',
                $exception->reasonCode,
            );
        }
    }

    private function database(): Connection
    {
        $database = ContentTestDatabase::inMemorySqlite();
        $database->migrateUp();
        $this->databases[] = $database;

        return $database->connection();
    }

    private function insertPublishedHead(
        Connection $connection,
        string $itemRef = 'content:item:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1',
    ): void {
        $connection->table('larena_content_items')->insert([
            'item_ref' => $itemRef,
            'type_key' => 'article',
            'locale' => 'en',
            'current_revision' => 3,
            'current_slug' => 'first-article',
            'current_status' => 'published',
            'current_visibility' => 'public',
            'published_revision' => 3,
            'published_slug' => 'first-article',
            'published_at' => '2026-07-19T09:00:00.000000Z',
            'created_at' => '2026-07-19T08:00:00.000000Z',
            'updated_at' => '2026-07-19T09:00:00.000000Z',
        ]);
    }

    private function participants(
        Connection $connection,
        ?Connection $storageConnection = null,
    ): ContentParticipantGuard {
        $storage = $this->createStub(VersionedStorage::class);
        $storage->method('connection')->willReturn($storageConnection ?? $connection);
        $audit = $this->createStub(ConnectionBoundAuditEventPipeline::class);
        $audit->method('connection')->willReturn($connection);

        return new ContentParticipantGuard(
            $connection,
            $storage,
            new DatabaseSearchIndex($connection),
            $audit,
        );
    }
}

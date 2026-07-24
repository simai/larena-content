<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Contracts\ContentProductSearchProjector;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Content\ValueObjects\ContentSearchProjection;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Contracts\SearchProjection;
use Larena\Search\Contracts\SearchWriteResult;
use Larena\Search\Persistence\DatabaseSearchIndex;

final class PublishedContentRuntimeTest extends TestCase
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

    public function test_public_and_search_use_one_exact_projection_and_unpublish_leaves_a_tombstone(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $draft = $scenario->createArticle();

        $this->assertNotPublic('first-article');

        $published = $this->runtime->items->publish(
            $draft->itemRef,
            1,
            $this->runtime->actor(),
        );
        $projection = $this->runtime->published->read(
            new ContentTypeKey('article'),
            new ContentSlug('first-article'),
            new ContentLocale('en'),
        );
        $byItem = $this->runtime->published->readItem($published->itemRef);
        self::assertSame($projection->itemRef->value, $byItem->itemRef->value);
        self::assertSame($projection->publishedRevision, $byItem->publishedRevision);
        self::assertSame($projection->publicFields, $byItem->publicFields);
        self::assertSame([
            'title' => 'First article',
            'body' => 'A deterministic public summary.',
            'featured' => true,
        ], $projection->publicFields);
        self::assertArrayNotHasKey('internal_notes', $projection->publicFields);

        $hits = $this->runtime->searchIndex->query(new SearchQuery(
            term: 'deterministic',
            providerId: 'content.published_items',
            locale: 'en',
        ));
        self::assertCount(1, $hits);
        self::assertSame($projection->itemRef->value, $hits[0]->sourceRef);
        self::assertSame($projection->publishedRevision, $hits[0]->sourceRevision);
        self::assertSame([
            'type_key' => 'article',
            'slug' => 'first-article',
            'locale' => 'en',
            'content_revision' => 2,
            'projection_version' => 1,
        ], $hits[0]->payload);
        $searchProjection = ContentSearchProjection::fromPublished($projection)
            ->toSearchProjection();
        $document = $this->runtime->connection
            ->table('larena_search_documents')
            ->where('provider_id', 'content.published_items')
            ->where('source_ref', $projection->itemRef->value)
            ->first();
        self::assertNotNull($document);
        self::assertSame(
            $searchProjection->contentHash(),
            (string) $document->projection_hash,
        );

        $batch = $this->runtime->searchSource->readBatch(null, 100);
        self::assertCount(1, $batch->projections);
        self::assertSame($hits[0]->sourceRef, $batch->projections[0]->sourceRef);
        self::assertSame($hits[0]->sourceRevision, $batch->projections[0]->sourceRevision);

        $unpublished = $this->runtime->items->unpublish(
            $published->itemRef,
            2,
            $this->runtime->actor(),
        );
        self::assertSame(3, $unpublished->currentRevision);
        self::assertNull($unpublished->publishedRevision);
        $this->assertNotPublic('first-article');
        self::assertSame([], $this->runtime->searchIndex->query(new SearchQuery(
            term: 'deterministic',
            providerId: 'content.published_items',
        )));

        $state = $this->runtime->connection
            ->table('larena_search_source_states')
            ->where('provider_id', 'content.published_items')
            ->where('source_ref', $published->itemRef->value)
            ->first();
        self::assertNotNull($state);
        self::assertSame('removed', (string) $state->state);
        self::assertSame(3, (int) $state->source_revision);
    }

    public function test_delegated_product_projection_is_the_only_mutation_and_rebuild_document(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $projector = new class($this->runtime->searchIndex) implements ContentProductSearchProjector {
            public function __construct(private readonly DatabaseSearchIndex $index)
            {
            }

            public function providerId(): string
            {
                return 'product.article';
            }

            public function sourceRef(ContentItemRef $itemRef): string
            {
                return 'product:'.$itemRef->value;
            }

            public function upsert(PublishedContentProjection $projection): SearchWriteResult
            {
                return $this->index->upsert(new SearchProjection(
                    providerId: $this->providerId(),
                    sourceRef: $this->sourceRef($projection->itemRef),
                    sourceRevision: $projection->publishedRevision,
                    title: (string) $projection->publicFields['title'],
                    locator: '/product/'.$projection->slug->value,
                    snippet: (string) $projection->publicFields['body'],
                    locale: $projection->locale->value,
                    accessScope: 'public',
                    searchableText: (string) $projection->publicFields['body'],
                    payload: ['item_ref' => $projection->itemRef->value],
                ));
            }

            public function remove(ContentItemRef $itemRef, int $sourceRevision): SearchWriteResult
            {
                return $this->index->remove(
                    $this->providerId(),
                    $this->sourceRef($itemRef),
                    $sourceRevision,
                );
            }
        };
        self::assertTrue($this->runtime->searchDelegations->delegate('article', $projector));
        $draft = $scenario->createArticle();

        $published = $this->runtime->items->publish(
            $draft->itemRef,
            1,
            $this->runtime->actor(),
        );
        self::assertSame(1, $this->runtime->connection
            ->table('larena_search_documents')
            ->where('provider_id', 'product.article')
            ->where('source_ref', 'product:'.$published->itemRef->value)
            ->count());
        self::assertSame(0, $this->runtime->connection
            ->table('larena_search_documents')
            ->where('provider_id', 'content.published_items')
            ->count());
        $batch = $this->runtime->searchSource->readBatch(null, 100);
        self::assertSame([], $batch->projections);
        self::assertNull($batch->nextCursor);

        $this->runtime->items->unpublish(
            $published->itemRef,
            $published->currentRevision,
            $this->runtime->actor(),
        );
        self::assertSame(0, $this->runtime->connection
            ->table('larena_search_documents')
            ->where('provider_id', 'product.article')
            ->count());
        $state = $this->runtime->connection
            ->table('larena_search_source_states')
            ->where('provider_id', 'product.article')
            ->where('source_ref', 'product:'.$published->itemRef->value)
            ->first();
        self::assertNotNull($state);
        self::assertSame('removed', $state->state);
    }

    public function test_private_item_never_publishes_or_enters_search(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $private = $scenario->createArticle(
            'private-article',
            ContentVisibility::Private,
        );

        try {
            $this->runtime->items->publish(
                $private->itemRef,
                1,
                $this->runtime->actor(),
            );
            self::fail('A private Content item was published.');
        } catch (ContentRejected $exception) {
            self::assertSame('private_item_cannot_publish', $exception->reasonCode());
        }

        $this->assertNotPublic('private-article');
        self::assertSame([], $this->runtime->searchIndex->query(new SearchQuery(
            term: 'First',
            providerId: 'content.published_items',
        )));
    }

    public function test_null_published_timestamp_is_never_public(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('null-published-at');
        $item = $this->runtime->items->publish(
            $item->itemRef,
            1,
            $this->runtime->actor(),
        );
        $this->runtime->connection
            ->table('larena_content_items')
            ->where('item_ref', $item->itemRef->value)
            ->update(['published_at' => null]);

        $this->assertNotPublic('null-published-at');
        self::assertNull($this->runtime->connection
            ->table('larena_content_items')
            ->where('item_ref', $item->itemRef->value)
            ->value('published_at'));
    }

    public function test_invalid_persisted_item_enums_are_uniformly_not_public(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('corrupt-item-enums');
        $item = $this->runtime->items->publish(
            $item->itemRef,
            1,
            $this->runtime->actor(),
        );

        $this->runtime->connection
            ->table('larena_content_items')
            ->where('item_ref', $item->itemRef->value)
            ->update(['current_status' => 'corrupt']);
        $this->assertNotPublic('corrupt-item-enums');

        $this->runtime->connection
            ->table('larena_content_items')
            ->where('item_ref', $item->itemRef->value)
            ->update([
                'current_status' => 'published',
                'current_visibility' => 'corrupt',
            ]);
        $this->assertNotPublic('corrupt-item-enums');
    }

    public function test_tampered_but_rehashed_storage_projection_is_uniformly_not_public(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('corrupt-storage-projection');
        $item = $this->runtime->items->publish(
            $item->itemRef,
            1,
            $this->runtime->actor(),
        );
        $contentRevision = $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $item->itemRef->value)
            ->where('revision', 2)
            ->first();
        self::assertNotNull($contentRevision);
        $storageVersion = $this->runtime->connection
            ->table('larena_storage_record_versions')
            ->where('record_id', (string) $contentRevision->storage_record_ref)
            ->where('revision', (int) $contentRevision->storage_record_version)
            ->first();
        self::assertNotNull($storageVersion);

        $values = json_decode(
            (string) $storageVersion->values_json,
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($values);
        $values['title'] = ['nested' => 'corrupt'];
        $valuesJson = (new ContentCanonicalJson())->encode($values);
        $this->runtime->connection
            ->table('larena_storage_record_versions')
            ->where('record_id', (string) $contentRevision->storage_record_ref)
            ->where('revision', (int) $contentRevision->storage_record_version)
            ->update([
                'values_json' => $valuesJson,
                'content_hash' => hash('sha256', $valuesJson),
            ]);

        $this->assertNotPublic('corrupt-storage-projection');
    }

    public function test_invalid_projection_after_access_emits_one_content_denial_and_rolls_back(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('blank-title', overrides: [
            'title' => '   ',
        ]);
        $beforeAccess = $this->runtime->accessDenialCount();
        $beforeContent = $this->runtime->contentAuditCount('content.operation.denied');

        try {
            $this->runtime->items->publish(
                $item->itemRef,
                1,
                $this->runtime->actor(),
            );
            self::fail('An invalid Search/public title was published.');
        } catch (ContentRejected $exception) {
            self::assertSame('publication_projection_invalid', $exception->reasonCode());
        }

        self::assertSame($beforeAccess, $this->runtime->accessDenialCount());
        self::assertSame(
            $beforeContent + 1,
            $this->runtime->contentAuditCount('content.operation.denied'),
        );
        self::assertSame(1, $this->runtime->items->read(
            $item->itemRef,
            $this->runtime->actor(),
        )->currentRevision);
        self::assertSame(0, $this->runtime->connection->table('larena_search_documents')->count());
    }

    private function assertNotPublic(string $slug): void
    {
        try {
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug($slug),
                new ContentLocale('en'),
            );
            self::fail('A protected Content projection leaked publicly.');
        } catch (ContentNotPublic $exception) {
            self::assertSame('content_not_public', $exception->reasonCode());
        }
    }
}

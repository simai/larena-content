<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use DateTimeImmutable;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemPage;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use PHPUnit\Framework\TestCase;

final class ContentItemContractTest extends TestCase
{
    public function testItemCarriesLifecyclePointersWithoutTypedValues(): void
    {
        $item = $this->publishedItem();

        self::assertSame(4, $item->currentRevision);
        self::assertSame(3, $item->publishedRevision);
        self::assertTrue($item->hasPublishedRevision());
        self::assertObjectNotHasProperty('values', $item);
        self::assertObjectNotHasProperty('typedValues', $item);
        self::assertObjectNotHasProperty('storageRecord', $item);

        $page = new ContentItemPage([$item], $item->itemRef);
        $query = new ContentItemQuery(
            typeKey: $item->typeKey,
            locale: $item->locale,
            status: ContentStatus::Draft,
            visibility: ContentVisibility::Private,
            limit: 25,
        );

        self::assertCount(1, $page->items);
        self::assertSame(25, $query->limit);
    }

    public function testPartialPublishedPointerFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentItem(
            itemRef: $this->itemRef(),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            currentRevision: 1,
            currentSlug: new ContentSlug('article-one'),
            currentStatus: ContentStatus::Draft,
            currentVisibility: ContentVisibility::Public,
            publishedRevision: 1,
            publishedSlug: null,
            publishedAt: null,
        );
    }

    public function testPublishedHeadRequiresExactCurrentPublishedPointer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentItem(
            itemRef: $this->itemRef(),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            currentRevision: 2,
            currentSlug: new ContentSlug('published-article'),
            currentStatus: ContentStatus::Published,
            currentVisibility: ContentVisibility::Public,
            publishedRevision: null,
            publishedSlug: null,
            publishedAt: null,
        );
    }

    public function testDraftHeadCannotBeTheExactPublishedRevision(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentItem(
            itemRef: $this->itemRef(),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            currentRevision: 2,
            currentSlug: new ContentSlug('draft-article'),
            currentStatus: ContentStatus::Draft,
            currentVisibility: ContentVisibility::Private,
            publishedRevision: 2,
            publishedSlug: new ContentSlug('draft-article'),
            publishedAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    private function publishedItem(): ContentItem
    {
        return new ContentItem(
            itemRef: $this->itemRef(),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            currentRevision: 4,
            currentSlug: new ContentSlug('next-draft-slug'),
            currentStatus: ContentStatus::Draft,
            currentVisibility: ContentVisibility::Private,
            publishedRevision: 3,
            publishedSlug: new ContentSlug('published-slug'),
            publishedAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    private function itemRef(): ContentItemRef
    {
        return ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001');
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use DateTimeImmutable;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Dataview\DefaultContentDataviewSourceFactory;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemPage;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use PHPUnit\Framework\TestCase;

final class ContentDataviewRuntimeTest extends TestCase
{
    public function testFactoryMaterializesOnlyBoundedAccessScopedHeadMetadata(): void
    {
        $query = new ContentItemQuery(limit: 100);
        $actor = new ActorContext(
            'user',
            'user:admin_identity:7',
            'content-dataview-runtime',
        );
        $item = new ContentItem(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164021'),
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale('en'),
            currentRevision: 4,
            currentSlug: new ContentSlug('current-draft'),
            currentStatus: ContentStatus::Draft,
            currentVisibility: ContentVisibility::Private,
            publishedRevision: 3,
            publishedSlug: new ContentSlug('published-article'),
            publishedAt: new DateTimeImmutable('2026-07-19T10:11:12.123456Z'),
        );

        $items = $this->createMock(ContentItemService::class);
        $items
            ->expects(self::once())
            ->method('list')
            ->with(self::identicalTo($query), self::identicalTo($actor))
            ->willReturn(new ContentItemPage([$item]));

        $provider = (new DefaultContentDataviewSourceFactory($items))
            ->forItems($query, $actor);

        $descriptor = $provider->descriptor();
        self::assertSame('content.items', $descriptor->sourceKey);
        self::assertSame('larena/content', $descriptor->ownerPackage);
        self::assertTrue($descriptor->accessScoped);
        self::assertFalse($descriptor->ownsCanonicalRecords);
        self::assertSame([
            [
                'item_ref' => 'content:item:018f6d52-4ef8-7bc2-9c71-3f2f4c164021',
                'type_key' => 'article',
                'locale' => 'en',
                'current_revision' => 4,
                'current_slug' => 'current-draft',
                'current_status' => 'draft',
                'current_visibility' => 'private',
                'published_revision' => 3,
                'published_slug' => 'published-article',
                'published_at' => '2026-07-19T10:11:12.123456Z',
            ],
        ], $provider->rows());

        $serialized = json_encode($provider->rows(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('values', $serialized);
        self::assertStringNotContainsString('attachment', $serialized);
        self::assertStringNotContainsString('body', $serialized);
    }
}

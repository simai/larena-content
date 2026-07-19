<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentRouteReservation;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use PHPUnit\Framework\TestCase;

final class ContentSlugContractTest extends TestCase
{
    public function testRouteIdentityIsTypeAndLocaleScopedAcrossBothPointers(): void
    {
        $route = new ContentRouteReservation(
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale('en'),
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            slug: new ContentSlug('stable-slug'),
            currentRevision: 4,
            publishedRevision: 3,
        );

        self::assertSame('article:en:stable-slug', $route->identity());
        self::assertSame(4, $route->currentRevision);
        self::assertSame(3, $route->publishedRevision);
    }

    public function testEmptyRouteReservationFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentRouteReservation(
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            slug: new ContentSlug('stable-slug'),
            currentRevision: null,
            publishedRevision: null,
        );
    }
}

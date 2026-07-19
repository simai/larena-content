<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentIdentityContractTest extends TestCase
{
    public function testFrozenIdentityShapesAreAccepted(): void
    {
        $typeKey = new ContentTypeKey('news.article');
        $itemRef = ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001');
        $locale = new ContentLocale('en-us');
        $slug = new ContentSlug('stable-content-slug');
        $actor = new ActorContext('user', 'user:1', 'request-018f6d52');

        self::assertSame('content.type.news.article', $typeKey->storageSchemaRef());
        self::assertSame('018f6d52-4ef8-7bc2-9c71-3f2f4c164001', $itemRef->uuid());
        self::assertSame('en-us', (string) $locale);
        self::assertSame('stable-content-slug', (string) $slug);
        self::assertSame('request-018f6d52', $actor->correlationId);
        self::assertSame('en', (new ContentLocale('en'))->value);
        self::assertSame('zh-hans', (new ContentLocale('zh-hans'))->value);
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function invalidIdentityProvider(): iterable
    {
        yield 'uppercase type key' => [ContentTypeKey::class, 'News.Article'];
        yield 'type key separator run' => [ContentTypeKey::class, 'news..article'];
        yield 'non canonical item reference' => [ContentItemRef::class, 'item:1'];
        yield 'uppercase locale' => [ContentLocale::class, 'en-US'];
        yield 'locale underscore' => [ContentLocale::class, 'en_us'];
        yield 'locale second extension' => [ContentLocale::class, 'en-us-posix'];
        yield 'slug underscore' => [ContentSlug::class, 'not_allowed'];
        yield 'slug leading hyphen' => [ContentSlug::class, '-not-allowed'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('invalidIdentityProvider')]
    public function testInvalidIdentityFailsClosed(string $class, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new $class($value);
    }
}

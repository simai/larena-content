<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Illuminate\Http\Request;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Http\Controllers\PublishedContentController;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\PublishedContentProjection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublicContentHttpTest extends TestCase
{
    public function testAnonymousControllerReturnsOnlyExactPublicProjection(): void
    {
        $projection = ContentPlatformV1Fixture::publishedArticle();
        $reader = new class($projection) implements PublishedContentReader {
            /** @var list<array{type_key: string, slug: string, locale: string}> */
            public array $calls = [];

            public function __construct(private readonly PublishedContentProjection $projection)
            {
            }

            public function read(
                ContentTypeKey $typeKey,
                ContentSlug $slug,
                ContentLocale $locale,
            ): PublishedContentProjection {
                $this->calls[] = [
                    'type_key' => $typeKey->value,
                    'slug' => $slug->value,
                    'locale' => $locale->value,
                ];

                return $this->projection;
            }
        };

        $response = (new PublishedContentController($reader))->show(
            Request::create('/content/article/first-article?locale=en', 'GET'),
            'article',
            'first-article',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->headers->get('Content-Type'),
        );
        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
        self::assertSame($projection->toArray(), $response->getData(true));
        self::assertSame([
            [
                'type_key' => 'article',
                'slug' => 'first-article',
                'locale' => 'en',
            ],
        ], $reader->calls);

        $body = (string) $response->getContent();
        foreach ([
            'internal_notes',
            'storage_record_ref',
            'current_revision',
            'actor_ref',
            'session',
        ] as $protectedField) {
            self::assertStringNotContainsString($protectedField, $body);
        }
    }

    public function testNonPublicAndInvalidLocaleBothFailAsSame404(): void
    {
        $reader = new class implements PublishedContentReader {
            public int $calls = 0;

            public function read(
                ContentTypeKey $typeKey,
                ContentSlug $slug,
                ContentLocale $locale,
            ): PublishedContentProjection {
                $this->calls++;

                throw new ContentNotPublic();
            }
        };
        $controller = new PublishedContentController($reader);

        try {
            $controller->show(
                Request::create('/content/article/private?locale=en', 'GET'),
                'article',
                'private',
            );
            self::fail('A protected projection must return 404.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
        self::assertSame(1, $reader->calls);

        try {
            $controller->show(
                Request::create('/content/article/private?locale=INVALID', 'GET'),
                'article',
                'private',
            );
            self::fail('An invalid locale must return the same 404.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }

        // Invalid public identity is rejected before the reader boundary and
        // therefore cannot disclose whether a matching protected row exists.
        self::assertSame(1, $reader->calls);
    }
}

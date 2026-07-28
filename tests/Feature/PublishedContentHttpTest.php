<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Contracts\ManagedContentRedirectReader;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\PublishedContentProjection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublishedContentHttpTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testPublicRouteIsGetOnlySessionlessAndDispatchesAnonymousRead(): void
    {
        $projection = ContentPlatformV1Fixture::publishedArticle();
        $reader = new class($projection) implements PublishedContentReader {
            public int $calls = 0;

            public function __construct(private readonly PublishedContentProjection $projection)
            {
            }

            public function read(
                ContentTypeKey $typeKey,
                ContentSlug $slug,
                ContentLocale $locale,
            ): PublishedContentProjection {
                $this->calls++;

                return $this->projection;
            }
        };
        $router = $this->router($reader);
        $route = $router->getRoutes()->getByName('larena.content.public.show');

        self::assertNotNull($route);
        self::assertSame(['GET', 'HEAD'], $route->methods());
        self::assertSame([], $route->gatherMiddleware());
        self::assertSame(
            'content/{typeKey}/{slug}',
            $route->uri(),
        );

        $response = $router->dispatch(Request::create(
            '/content/article/first-article?locale=en',
            'GET',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($projection->toArray(), json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame(1, $reader->calls);
    }

    public function testRouteConstraintsRejectInvalidTypeAndSlugBeforeReader(): void
    {
        $reader = new class implements PublishedContentReader {
            public int $calls = 0;

            public function read(
                ContentTypeKey $typeKey,
                ContentSlug $slug,
                ContentLocale $locale,
            ): PublishedContentProjection {
                $this->calls++;

                return ContentPlatformV1Fixture::publishedArticle();
            }
        };
        $router = $this->router($reader);

        foreach ([
            '/content/Article/first-article',
            '/content/article/First_Article',
        ] as $uri) {
            try {
                $router->dispatch(Request::create($uri, 'GET'));
                self::fail('An invalid public Content route must return 404.');
            } catch (NotFoundHttpException $exception) {
                self::assertSame(404, $exception->getStatusCode());
            }
        }

        self::assertSame(0, $reader->calls);
    }

    public function testOldPublishedLocatorReturnsOneDirectPermanentRedirect(): void
    {
        $reader = new class implements PublishedContentReader {
            public function read(ContentTypeKey $typeKey, ContentSlug $slug, ContentLocale $locale): PublishedContentProjection
            {
                throw new ContentNotPublic();
            }
        };
        $redirects = new class implements ManagedContentRedirectReader {
            public function resolve(ContentTypeKey $typeKey, ContentSlug $slug, ContentLocale $locale): array
            {
                return ['type_key' => 'article', 'locale' => 'en', 'slug' => 'new-slug', 'status' => 301];
            }
        };
        $response = $this->router($reader, $redirects)->dispatch(Request::create('/content/article/old-slug', 'GET'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/content/article/new-slug', $response->headers->get('Location'));
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    public function testAnonymousSiteStructureRouteReturnsOnlyPublishedProjection(): void
    {
        $reader = $this->createStub(PublishedContentReader::class);
        $structures = $this->createStub(SiteStructureService::class);
        $projection = [
            'structure_ref' => 'primary',
            'revision' => 3,
            'nodes' => [],
            'seo' => [],
        ];
        $structures->method('published')->willReturn($projection);
        $router = $this->router($reader, null, $structures);
        $route = $router->getRoutes()->getByName('larena.content.structure.public');

        self::assertNotNull($route);
        self::assertSame(['GET', 'HEAD'], $route->methods());
        self::assertSame([], $route->gatherMiddleware());
        $response = $router->dispatch(Request::create('/content/site-structure', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($projection, json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    private function router(
        PublishedContentReader $reader,
        ?ManagedContentRedirectReader $redirects = null,
        ?SiteStructureService $structures = null,
    ): Router
    {
        $container = new Container();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        $container->instance(PublishedContentReader::class, $reader);
        $container->instance(ManagedContentRedirectReader::class, $redirects ?? new class implements ManagedContentRedirectReader {
            public function resolve(ContentTypeKey $typeKey, ContentSlug $slug, ContentLocale $locale): ?array
            {
                return null;
            }
        });
        $container->instance(SiteStructureService::class, $structures ?? $this->createStub(SiteStructureService::class));

        Facade::setFacadeApplication(null);
        Route::swap($router);
        require dirname(__DIR__, 2) . '/routes/public.php';
        $router->getRoutes()->refreshNameLookups();

        return $router;
    }
}

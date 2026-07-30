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
        Container::setInstance(null);

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

    public function testOldBrowserPageLocatorRedirectsToTheCurrentBrowserPage(): void
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
                return ['type_key' => 'page', 'locale' => 'en', 'slug' => 'current-page', 'status' => 301];
            }
        };

        $response = $this->router($reader, $redirects)->dispatch(Request::create('/pages/page/old-page', 'GET'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/pages/page/current-page', $response->headers->get('Location'));
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

    public function testNavigationAliasRobotsAndSitemapAreSessionlessPublishedHttpContracts(): void
    {
        $reader = $this->createStub(PublishedContentReader::class);
        $structures = $this->createStub(SiteStructureService::class);
        $structures->method('published')->willReturn([
            'structure_ref' => 'primary',
            'revision' => 4,
            'nodes' => [[
                'node_ref' => '128f62c6-9d27-4d19-89b1-7cddfbd9a301',
                'parent_ref' => null,
                'position' => 0,
                'label' => 'Page',
                'target' => ['type' => 'content', 'item_ref' => 'content:item:128f62c6-9d27-4d19-89b1-7cddfbd9a399', 'url' => '/content/article/page'],
            ]],
            'seo' => [
                'content:item:128f62c6-9d27-4d19-89b1-7cddfbd9a399' => [
                    'item_ref' => 'content:item:128f62c6-9d27-4d19-89b1-7cddfbd9a399',
                    'canonical_path' => '/knowledge/page',
                    'robots' => 'index,follow',
                ],
                'content:item:128f62c6-9d27-4d19-89b1-7cddfbd9a398' => [
                    'item_ref' => 'content:item:128f62c6-9d27-4d19-89b1-7cddfbd9a398',
                    'canonical_path' => '/private/page',
                    'robots' => 'noindex,follow',
                ],
            ],
        ]);
        $router = $this->router($reader, null, $structures);

        $navigation = $router->dispatch(Request::create('/content/navigation', 'GET'));
        self::assertSame(200, $navigation->getStatusCode());
        self::assertFalse($navigation->headers->has('Set-Cookie'));
        $robotsRequest = Request::create('/robots.txt', 'GET', [], [], [], ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on']);
        Container::getInstance()->instance('request', $robotsRequest);
        Container::getInstance()->instance(Request::class, $robotsRequest);
        $robots = $router->dispatch($robotsRequest);
        self::assertSame("User-agent: *\nAllow: /\nSitemap: https://example.test/sitemap.xml\n", $robots->getContent());
        self::assertFalse($robots->headers->has('Set-Cookie'));
        $sitemapRequest = Request::create('/sitemap.xml', 'GET', [], [], [], ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on']);
        Container::getInstance()->instance('request', $sitemapRequest);
        Container::getInstance()->instance(Request::class, $sitemapRequest);
        $sitemap = $router->dispatch($sitemapRequest);
        self::assertStringContainsString('<loc>https://example.test/knowledge/page</loc>', (string) $sitemap->getContent());
        self::assertStringNotContainsString('/private/page', (string) $sitemap->getContent());
        self::assertFalse($sitemap->headers->has('Set-Cookie'));
    }

    public function testPublishedContentCarriesCanonicalAndRobotsHttpMetadata(): void
    {
        $projection = ContentPlatformV1Fixture::publishedArticle();
        $reader = new class($projection) implements PublishedContentReader {
            public function __construct(private readonly PublishedContentProjection $projection) {}
            public function read(ContentTypeKey $typeKey, ContentSlug $slug, ContentLocale $locale): PublishedContentProjection { return $this->projection; }
        };
        $structures = $this->createStub(SiteStructureService::class);
        $structures->method('published')->willReturn([
            'structure_ref' => 'primary', 'revision' => 2, 'nodes' => [],
            'seo' => [$projection->itemRef->value => [
                'item_ref' => $projection->itemRef->value,
                'canonical_path' => '/knowledge/first-article',
                'robots' => 'index,nofollow',
            ]],
        ]);

        $router = $this->router($reader, null, $structures);
        $contentRequest = Request::create(
            '/content/article/first-article', 'GET', [], [], [], ['HTTP_HOST' => 'example.test', 'HTTPS' => 'on'],
        );
        Container::getInstance()->instance('request', $contentRequest);
        Container::getInstance()->instance(Request::class, $contentRequest);
        $response = $router->dispatch($contentRequest);

        self::assertSame('<https://example.test/knowledge/first-article>; rel="canonical"', $response->headers->get('Link'));
        self::assertSame('index,nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    private function router(
        PublishedContentReader $reader,
        ?ManagedContentRedirectReader $redirects = null,
        ?SiteStructureService $structures = null,
    ): Router
    {
        $container = new Container();
        Container::setInstance($container);
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

<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Larena\Audit\Contracts\ConnectionBoundAuditEventPipeline;
use Larena\Content\Contracts\ContentSearchSourceProvider;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Providers\ContentServiceProvider;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Search\ContentSearchContract;
use Larena\Content\Search\DatabaseContentSearchSourceProvider;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Search\Runtime\SearchSourceRegistry;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Storage\Contracts\VersionedStorage;
use PHPUnit\Framework\TestCase;

final class ContentProviderBindingTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testSearchSourceRegistersWhenOwnerRegistryResolvesAfterContentProvider(): void
    {
        $container = new Container();
        $container->singleton(
            SearchSourceRegistry::class,
            static fn (): SearchSourceRegistry => new SearchSourceRegistry(),
        );

        $provider = new ContentServiceProvider($this->applicationFor($container));
        $provider->register();

        $connection = (new ConnectionFactory($container))->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $container->instance(
            DatabaseContentRepository::class,
            new DatabaseContentRepository($connection),
        );
        $container->instance(
            PublishedContentReader::class,
            new class implements PublishedContentReader {
                public function read(
                    ContentTypeKey $typeKey,
                    ContentSlug $slug,
                    ContentLocale $locale,
                ): PublishedContentProjection {
                    return ContentPlatformV1Fixture::publishedArticle();
                }
            },
        );
        $container->instance(
            ContentParticipantGuard::class,
            $this->participants($connection),
        );

        $registry = $container->make(SearchSourceRegistry::class);
        $source = $registry->get(ContentSearchContract::PROVIDER_ID);

        self::assertInstanceOf(DatabaseContentSearchSourceProvider::class, $source);
        self::assertSame($source, $container->make(ContentSearchSourceProvider::class));
        self::assertSame('content.published_items', $source->providerId());
        self::assertSame([$source], $registry->all());

        // Resolving the singleton registry again must not duplicate or replace
        // the already registered owner source.
        self::assertSame($registry, $container->make(SearchSourceRegistry::class));
        self::assertSame([$source], $registry->all());
    }

    public function testBootRegistersMigrationPathAndLoadsOnlyPublicSessionlessRoute(): void
    {
        $container = new Container();
        $application = $this->applicationFor($container);
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Route::swap($router);
        $migrator = new class {
            /** @var list<string> */
            public array $paths = [];

            public function path(string $path): void
            {
                $this->paths[] = $path;
            }
        };
        $container->singleton('migrator', static fn (): object => $migrator);

        $provider = new ContentServiceProvider($application);
        $provider->register();
        $provider->boot();
        $router->getRoutes()->refreshNameLookups();

        $container->make('migrator');

        $route = $router->getRoutes()->getByName('larena.content.public.show');
        self::assertNotNull($route);
        self::assertSame(['GET', 'HEAD'], $route->methods());
        self::assertSame([], $route->gatherMiddleware());
        self::assertCount(1, $migrator->paths);
        $expectedMigrationPath = realpath(
            dirname(__DIR__, 2) . '/database/migrations',
        );
        $registeredMigrationPath = realpath($migrator->paths[0]);
        self::assertIsString($expectedMigrationPath);
        self::assertIsString($registeredMigrationPath);
        self::assertSame($expectedMigrationPath, $registeredMigrationPath);
    }

    private function applicationFor(Container $container): Application
    {
        $application = $this->createStub(Application::class);
        $application->method('singleton')->willReturnCallback(
            static function (string $abstract, mixed $concrete = null) use ($container): void {
                $container->singleton($abstract, $concrete);
            },
        );
        $application->method('scoped')->willReturnCallback(
            static function (string $abstract, mixed $concrete = null) use ($container): void {
                $container->scoped($abstract, $concrete);
            },
        );
        $application->method('alias')->willReturnCallback(
            static function (string $abstract, string $alias) use ($container): void {
                $container->alias($abstract, $alias);
            },
        );
        $application->method('afterResolving')->willReturnCallback(
            static function (string $abstract, ?\Closure $callback = null) use ($container): void {
                $container->afterResolving($abstract, $callback);
            },
        );
        $application->method('bound')->willReturnCallback(
            static fn (string $abstract): bool => $container->bound($abstract),
        );
        $application->method('resolved')->willReturnCallback(
            static fn (string $abstract): bool => $container->resolved($abstract),
        );
        $application->method('make')->willReturnCallback(
            static fn (string $abstract, array $parameters = []): mixed => $container->make(
                $abstract,
                $parameters,
            ),
        );

        return $application;
    }

    private function participants(Connection $connection): ContentParticipantGuard
    {
        $storage = $this->createStub(VersionedStorage::class);
        $storage->method('connection')->willReturn($connection);
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

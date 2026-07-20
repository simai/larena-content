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
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentSearchSourceProvider;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Providers\ContentServiceProvider;
use Larena\Content\Rest\ContentAdminApiOperationHandler;
use Larena\Content\Rest\ContentAdminReadModel;
use Larena\Content\Rest\ContentAdminValueCodec;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Search\ContentSearchContract;
use Larena\Content\Search\DatabaseContentSearchSourceProvider;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Rest\Contracts\OperationHandlerRegistry;
use Larena\Rest\Exceptions\ApiOperationException;
use Larena\Rest\Providers\RestServiceProvider;
use Larena\Rest\Registry\PackageApiContractLoader;
use Larena\Search\Runtime\SearchSourceRegistry;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Storage\Contracts\VersionedStorage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
        $nextConnection = (new ConnectionFactory($container))->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $currentConnection = $connection;
        $sourceResolutions = 0;
        $container->scoped(
            DatabaseContentRepository::class,
            static function () use (&$currentConnection): DatabaseContentRepository {
                return new DatabaseContentRepository($currentConnection);
            },
        );
        $container->scoped(
            PublishedContentReader::class,
            static fn (): PublishedContentReader => new class implements PublishedContentReader {
                public function read(
                    ContentTypeKey $typeKey,
                    ContentSlug $slug,
                    ContentLocale $locale,
                ): PublishedContentProjection {
                    return ContentPlatformV1Fixture::publishedArticle();
                }
            },
        );
        $container->scoped(
            ContentParticipantGuard::class,
            function () use (&$currentConnection): ContentParticipantGuard {
                return $this->participants($currentConnection);
            },
        );
        $container->scoped(
            DatabaseContentSearchSourceProvider::class,
            static function (Container $app) use (&$sourceResolutions): DatabaseContentSearchSourceProvider {
                $sourceResolutions++;

                return new DatabaseContentSearchSourceProvider(
                    $app->make(DatabaseContentRepository::class),
                    $app->make(PublishedContentReader::class),
                    $app->make(ContentParticipantGuard::class),
                );
            },
        );

        $registry = $container->make(SearchSourceRegistry::class);
        self::assertSame(0, $sourceResolutions);
        self::assertTrue($registry->has(ContentSearchContract::PROVIDER_ID));
        self::assertSame([ContentSearchContract::PROVIDER_ID], $registry->providerIds());
        self::assertSame(0, $sourceResolutions);

        $source = $registry->get(ContentSearchContract::PROVIDER_ID);

        self::assertInstanceOf(DatabaseContentSearchSourceProvider::class, $source);
        self::assertSame($source, $container->make(ContentSearchSourceProvider::class));
        self::assertSame('content.published_items', $source->providerId());
        self::assertSame([$source], $registry->all());
        self::assertSame(1, $sourceResolutions);
        self::assertSame(
            $connection,
            $container->make(DatabaseContentRepository::class)->connection(),
        );

        // The singleton registry keeps only the lightweight factory. Clearing
        // scoped instances must yield a fresh provider graph rather than
        // retaining the old connection-bound Content source.
        $container->forgetScopedInstances();
        $currentConnection = $nextConnection;
        $fresh = $registry->get(ContentSearchContract::PROVIDER_ID);
        self::assertInstanceOf(DatabaseContentSearchSourceProvider::class, $fresh);
        self::assertNotSame($source, $fresh);
        self::assertSame($fresh, $container->make(ContentSearchSourceProvider::class));
        self::assertSame(2, $sourceResolutions);
        self::assertSame(
            $nextConnection,
            $container->make(DatabaseContentRepository::class)->connection(),
        );
        self::assertSame($registry, $container->make(SearchSourceRegistry::class));
        self::assertSame([$fresh], $registry->all());
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

    public function testDiscoveredContentBeforeRestOrderRegistersAndDispatchesAllTwentyHandlers(): void
    {
        $container = new Container();
        $application = $this->applicationFor($container);
        $config = $this->createStub(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->willReturn([]);
        $container->instance('config', $config);

        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Route::swap($router);
        $container->singleton('migrator', static fn (): object => new class {
            public function path(string $path): void
            {
            }
        });

        $content = new ContentServiceProvider($application);
        $rest = new RestServiceProvider($application);

        // This is the package-discovery order recorded by the Root cache:
        // all providers register first, then providers boot in the same order.
        $content->register();
        $rest->register();

        /** @var ContentAdminReadModel $reads */
        $reads = (new ReflectionClass(ContentAdminReadModel::class))
            ->newInstanceWithoutConstructor();
        $container->singleton(
            ContentAdminApiOperationHandler::class,
            fn (): ContentAdminApiOperationHandler => new ContentAdminApiOperationHandler(
                $this->createStub(ContentTypeService::class),
                $this->createStub(ContentItemService::class),
                $reads,
                new ContentAdminValueCodec(),
            ),
        );

        $content->boot();

        $registry = $container->make(OperationHandlerRegistry::class);
        $contract = (new PackageApiContractLoader())->loadFile(
            dirname(__DIR__, 2) . '/api.yaml',
            'larena/content',
        );

        self::assertCount(20, $contract->operations);
        foreach ($contract->operations as $operation) {
            $handler = $registry->get($operation->handlerReference);
            self::assertIsCallable($handler);

            try {
                $handler(
                    ['path' => [], 'query' => [], 'body' => []],
                    new OperationDescriptor(
                        $operation->operationKey,
                        OperationExecutionMode::Sync,
                    ),
                    new OperationContext(
                        'user:admin_identity:1',
                        'content-before-rest-provider-order',
                    ),
                );
                self::fail('The Content handler accepted a missing validated session context.');
            } catch (ApiOperationException $exception) {
                self::assertSame(
                    'content_admin_api_session_context_invalid',
                    $exception->errorCode,
                );
                self::assertSame(403, $exception->httpStatus);
            }
        }
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
        $application->method('bind')->willReturnCallback(
            static function (string $abstract, mixed $concrete = null) use ($container): void {
                $container->bind($abstract, $concrete);
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

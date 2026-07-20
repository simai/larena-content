<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Content\Contracts\ContentClock;
use Larena\Content\Contracts\ContentDataviewSourceFactory;
use Larena\Content\Contracts\ContentIdGenerator;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Contracts\ContentSearchSourceProvider;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Dataview\DefaultContentDataviewSourceFactory;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Providers\ContentServiceProvider;
use Larena\Content\Runtime\SystemContentClock;
use Larena\Content\Runtime\SystemContentIdGenerator;
use Larena\Content\Search\DatabaseContentSearchSourceProvider;
use Larena\Content\Services\DatabaseContentItemService;
use Larena\Content\Services\DatabaseContentTypeService;
use Larena\Content\Services\DatabasePublishedContentReader;
use PHPUnit\Framework\TestCase;

final class ContentServiceProviderTest extends TestCase
{
    public function testRegisterDeclaresExactContractsAndRegistersAccessCatalogIdempotently(): void
    {
        $container = new Container();
        $container->singleton(
            AccessOperationRegistry::class,
            static fn (): AccessOperationRegistry => new AccessOperationRegistry(),
        );

        $provider = new ContentServiceProvider($this->applicationFor($container));
        $provider->register();

        self::assertTrue($container->bound(DatabaseContentRepository::class));
        self::assertTrue($container->bound(ContentLogicalFileInspector::class));
        self::assertTrue($container->bound(ContentClock::class));
        self::assertTrue($container->bound(ContentIdGenerator::class));
        self::assertTrue($container->bound(ContentTypeService::class));
        self::assertTrue($container->bound(ContentItemService::class));
        self::assertTrue($container->bound(PublishedContentReader::class));
        self::assertTrue($container->bound(ContentDataviewSourceFactory::class));
        self::assertTrue($container->bound(ContentSearchSourceProvider::class));

        self::assertSame(
            DatabaseContentTypeService::class,
            $container->getAlias(ContentTypeService::class),
        );
        self::assertSame(
            DatabaseContentItemService::class,
            $container->getAlias(ContentItemService::class),
        );
        self::assertSame(
            DatabasePublishedContentReader::class,
            $container->getAlias(PublishedContentReader::class),
        );
        self::assertSame(
            DefaultContentDataviewSourceFactory::class,
            $container->getAlias(ContentDataviewSourceFactory::class),
        );
        self::assertSame(
            DatabaseContentSearchSourceProvider::class,
            $container->getAlias(ContentSearchSourceProvider::class),
        );

        self::assertInstanceOf(SystemContentClock::class, $container->make(ContentClock::class));
        self::assertInstanceOf(SystemContentIdGenerator::class, $container->make(ContentIdGenerator::class));

        $registry = $container->make(AccessOperationRegistry::class);
        self::assertCount(18, $registry->all());
        self::assertSame(
            [
                'content.attachment.attach',
                'content.attachment.detach',
                'content.attachment.list',
                'content.attachment.reorder',
                'content.item.create',
                'content.item.list',
                'content.item.publish',
                'content.item.read',
                'content.item.restore',
                'content.item.unpublish',
                'content.item.update',
                'content.revision.list',
                'content.revision.read',
                'content.type.create',
                'content.type.list',
                'content.type.read',
                'content.type.version.create',
                'content.type.version.preview',
            ],
            array_map(
                static fn ($operation): string => $operation->code,
                $registry->all(),
            ),
        );

        $provider->register();
        self::assertCount(18, $registry->all());
        self::assertNull($registry->get('content.public.read'));
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

        return $application;
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\Runtime\PersistentGlobalRoleQueryScopeProvider;
use Larena\Audit\Contracts\ConnectionBoundAuditEventPipeline;
use Larena\Content\Access\ContentAccessOperationCatalog;
use Larena\Content\Access\ContentAuthorizer;
use Larena\Content\Audit\ContentAuditEmitter;
use Larena\Content\Contracts\ContentClock;
use Larena\Content\Contracts\ContentDataviewSourceFactory;
use Larena\Content\Contracts\ContentIdGenerator;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Contracts\ContentSearchSourceProvider;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Dataview\DefaultContentDataviewSourceFactory;
use Larena\Content\Filesystem\FilesystemContentLogicalFileInspector;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\Runtime\PublishedContentProjectionBuilder;
use Larena\Content\Runtime\SystemContentClock;
use Larena\Content\Runtime\SystemContentIdGenerator;
use Larena\Content\Rest\ContentAdminApiOperationHandler;
use Larena\Content\Search\ContainerContentSearchSourceFactory;
use Larena\Content\Search\DatabaseContentSearchSourceProvider;
use Larena\Content\Services\DatabaseContentItemService;
use Larena\Content\Services\DatabaseContentTypeService;
use Larena\Content\Services\DatabasePublishedContentReader;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\Storage\ContentStorageSchemaEvolutionAuthority;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Runtime\SearchSourceRegistry;
use Larena\Rest\Contracts\OperationHandlerRegistry;
use Larena\Storage\Contracts\StorageSchemaEvolution;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use WeakMap;

final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $schemaEvolutionAuthority = new ContentStorageSchemaEvolutionAuthority();
        /** @var WeakMap<StorageSchemaEvolutionOwnerPolicyRegistry, true> $protectedRegistries */
        $protectedRegistries = new WeakMap();
        $installOwnerPolicy = static function (
            StorageSchemaEvolutionOwnerPolicyRegistry $registry,
        ) use ($schemaEvolutionAuthority, $protectedRegistries): void {
            if (isset($protectedRegistries[$registry])) {
                return;
            }
            $registry->protect(
                ownerPackage: 'larena/content',
                validator: $schemaEvolutionAuthority->ownerValidator(),
                schemaPrefix: 'content.type.',
            );
            $protectedRegistries[$registry] = true;
        };
        $this->app->afterResolving(
            StorageSchemaEvolutionOwnerPolicyRegistry::class,
            $installOwnerPolicy,
        );
        if ($this->app->resolved(StorageSchemaEvolutionOwnerPolicyRegistry::class)) {
            $installOwnerPolicy(
                $this->app->make(StorageSchemaEvolutionOwnerPolicyRegistry::class),
            );
        }

        $this->app->singleton(
            ContentClock::class,
            static fn (): ContentClock => new SystemContentClock(),
        );
        $this->app->singleton(
            ContentIdGenerator::class,
            static fn (): ContentIdGenerator => new SystemContentIdGenerator(),
        );
        $this->app->singleton(
            ContentCanonicalJson::class,
            static fn (): ContentCanonicalJson => new ContentCanonicalJson(),
        );
        $this->app->singleton(
            ContentInputGuard::class,
            static fn (Container $app): ContentInputGuard => new ContentInputGuard(
                $app->make(ContentCanonicalJson::class),
            ),
        );
        $this->app->singleton(
            ContentSchemaMapper::class,
            static fn (Container $app): ContentSchemaMapper => new ContentSchemaMapper(
                $app->make(PropertyTypeRegistry::class),
                $app->make(ContentInputGuard::class),
                $app->make(ContentCanonicalJson::class),
            ),
        );

        $this->app->scoped(
            DatabaseContentRepository::class,
            static fn (Container $app): DatabaseContentRepository => new DatabaseContentRepository(
                $app->make(DatabaseManager::class)->connection(),
            ),
        );
        $this->app->scoped(
            ContentAuthorizer::class,
            static fn (Container $app): ContentAuthorizer => new ContentAuthorizer(
                $app->make(ActorOperationAuthorizer::class),
                $app->make(PersistentGlobalRoleQueryScopeProvider::class),
            ),
        );
        $this->app->scoped(
            ContentLogicalFileInspector::class,
            static fn (Container $app): ContentLogicalFileInspector => new FilesystemContentLogicalFileInspector(
                $app->make(PersistentLogicalFileInspector::class),
                $app->make(ContentInputGuard::class),
            ),
        );
        $this->app->scoped(
            ContentStorageGateway::class,
            static fn (Container $app): ContentStorageGateway => new ContentStorageGateway(
                $app->make(VersionedStorage::class),
                $app->make(ContentSchemaMapper::class),
                $app->make(ContentInputGuard::class),
                $app->make(StorageSchemaEvolution::class),
                $app->make(StorageSchemaEvolutionOwnerPolicyRegistry::class),
                $schemaEvolutionAuthority,
            ),
        );
        $this->app->scoped(
            ContentAuditEmitter::class,
            static fn (Container $app): ContentAuditEmitter => new ContentAuditEmitter(
                $app->make(ConnectionBoundAuditEventPipeline::class),
                $app->make(ContentClock::class),
            ),
        );
        $this->app->scoped(
            ContentParticipantGuard::class,
            static fn (Container $app): ContentParticipantGuard => new ContentParticipantGuard(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(VersionedStorage::class),
                $app->make(DatabaseSearchIndex::class),
                $app->make(ConnectionBoundAuditEventPipeline::class),
            ),
        );
        $this->app->scoped(
            PublishedContentProjectionBuilder::class,
            static fn (Container $app): PublishedContentProjectionBuilder => new PublishedContentProjectionBuilder(
                $app->make(ContentStorageGateway::class),
                $app->make(ContentLogicalFileInspector::class),
            ),
        );

        $this->app->scoped(DatabaseContentTypeService::class);
        $this->app->alias(DatabaseContentTypeService::class, ContentTypeService::class);
        $this->app->scoped(DatabaseContentItemService::class);
        $this->app->alias(DatabaseContentItemService::class, ContentItemService::class);
        $this->app->scoped(DatabasePublishedContentReader::class);
        $this->app->alias(DatabasePublishedContentReader::class, PublishedContentReader::class);
        $this->app->scoped(DefaultContentDataviewSourceFactory::class);
        $this->app->alias(
            DefaultContentDataviewSourceFactory::class,
            ContentDataviewSourceFactory::class,
        );

        $this->app->scoped(
            DatabaseContentSearchSourceProvider::class,
            static fn (Container $app): DatabaseContentSearchSourceProvider => new DatabaseContentSearchSourceProvider(
                $app->make(DatabaseContentRepository::class),
                $app->make(PublishedContentReader::class),
                $app->make(ContentParticipantGuard::class),
            ),
        );
        $this->app->alias(
            DatabaseContentSearchSourceProvider::class,
            ContentSearchSourceProvider::class,
        );
        $this->app->singleton(
            ContainerContentSearchSourceFactory::class,
            static fn (Container $app): ContainerContentSearchSourceFactory => new ContainerContentSearchSourceFactory(
                $app,
            ),
        );

        $this->app->afterResolving(
            AccessOperationRegistry::class,
            static fn (AccessOperationRegistry $registry): bool => self::registerAccessOperations($registry),
        );
        $this->app->afterResolving(
            SearchSourceRegistry::class,
            static fn (SearchSourceRegistry $registry, Container $app): bool => self::registerSearchSource(
                $registry,
                $app,
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/public.php');

        if ($this->app->bound(AccessOperationRegistry::class)) {
            self::registerAccessOperations(
                $this->app->make(AccessOperationRegistry::class),
            );
        }

        if ($this->app->bound(SearchSourceRegistry::class)) {
            self::registerSearchSource(
                $this->app->make(SearchSourceRegistry::class),
                $this->app,
            );
        }

        if ($this->app->bound(OperationHandlerRegistry::class)) {
            ContentAdminApiOperationHandler::registerLazy(
                $this->app->make(OperationHandlerRegistry::class),
                fn (): ContentAdminApiOperationHandler => $this->app->make(
                    ContentAdminApiOperationHandler::class,
                ),
            );
        }
    }

    private static function registerAccessOperations(
        AccessOperationRegistry $registry,
    ): bool {
        $registered = false;

        foreach (ContentAccessOperationCatalog::operations() as $operation) {
            $registered = $registry->register($operation) || $registered;
        }

        return $registered;
    }

    private static function registerSearchSource(
        SearchSourceRegistry $registry,
        Container $app,
    ): bool {
        return $registry->registerFactory(
            $app->make(ContainerContentSearchSourceFactory::class),
        );
    }
}

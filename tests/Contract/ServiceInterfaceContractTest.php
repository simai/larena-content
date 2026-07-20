<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\Contracts\ContentDataviewSourceProvider;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Contracts\ContentSearchSourceProvider;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentType;
use Larena\Dataview\Contracts\DataviewSourceProvider;
use Larena\Search\Contracts\ReindexSource;
use ReflectionClass;
use ReflectionMethod;

final class ServiceInterfaceContractTest extends TestCase
{
    public function test_type_service_exposes_head_and_exact_schema_version_reads(): void
    {
        self::assertSame(
            ['create', 'createVersion', 'list', 'previewVersion', 'read', 'version', 'versions'],
            $this->publicMethodNames(ContentTypeService::class),
        );
        self::assertSame(
            ContentType::class,
            (string) (new ReflectionMethod(
                ContentTypeService::class,
                'createVersion',
            ))->getReturnType(),
        );
    }

    public function test_item_service_exposes_lifecycle_revision_attachment_and_publication_operations(): void
    {
        self::assertSame(
            [
                'attach',
                'attachments',
                'create',
                'currentAttachments',
                'detach',
                'list',
                'publish',
                'read',
                'reorder',
                'restore',
                'revision',
                'revisions',
                'unpublish',
                'update',
            ],
            $this->publicMethodNames(ContentItemService::class),
        );
    }

    public function test_public_reader_is_sessionless_and_has_no_actor_parameter(): void
    {
        $method = new ReflectionMethod(PublishedContentReader::class, 'read');

        self::assertCount(3, $method->getParameters());
        self::assertSame(['typeKey', 'slug', 'locale'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));
    }

    public function test_search_and_dataview_contracts_use_exact_dependency_boundaries(): void
    {
        self::assertTrue(
            (new ReflectionClass(ContentSearchSourceProvider::class))
                ->implementsInterface(ReindexSource::class),
        );
        self::assertTrue(
            (new ReflectionClass(ContentDataviewSourceProvider::class))
                ->implementsInterface(DataviewSourceProvider::class),
        );
    }

    public function test_filesystem_port_is_read_only(): void
    {
        self::assertSame(
            ['inspect'],
            $this->publicMethodNames(ContentLogicalFileInspector::class),
        );
    }

    /**
     * @param class-string $interface
     * @return list<string>
     */
    private function publicMethodNames(string $interface): array
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($interface))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        return $methods;
    }
}

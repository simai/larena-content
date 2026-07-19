<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\Contracts\ContentDataviewSourceProvider;
use Larena\Content\Dataview\ContentDataviewContract;
use Larena\Content\Tests\TestCase;
use Larena\Dataview\Contracts\DataviewSourceProvider;
use ReflectionClass;

final class DataviewBoundaryContractTest extends TestCase
{
    public function test_dataview_descriptor_is_access_scoped_and_never_owns_content(): void
    {
        $descriptor = ContentDataviewContract::descriptor();

        self::assertTrue($descriptor->isValid());
        self::assertSame('content.items', $descriptor->sourceKey);
        self::assertSame('larena/content', $descriptor->ownerPackage);
        self::assertTrue($descriptor->accessScoped);
        self::assertFalse($descriptor->ownsCanonicalRecords);
        self::assertSame([], ContentDataviewContract::mutationOperations());
    }

    public function test_content_provider_only_extends_read_only_dataview_boundary(): void
    {
        $interface = new ReflectionClass(ContentDataviewSourceProvider::class);
        $methods = array_map(
            static fn ($method): string => $method->getName(),
            $interface->getMethods(),
        );
        sort($methods);

        self::assertTrue($interface->isInterface());
        self::assertTrue($interface->implementsInterface(DataviewSourceProvider::class));
        self::assertSame(['descriptor', 'rows'], $methods);

        foreach (['create', 'update', 'publish', 'delete', 'store', 'persist'] as $mutation) {
            self::assertFalse($interface->hasMethod($mutation));
        }
    }
}

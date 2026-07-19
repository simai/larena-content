<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\Access\ContentAccessOperationCatalog;
use Larena\Content\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AccessDescriptorContractTest extends TestCase
{
    private const EXPECTED_CODES = [
        'content.type.list',
        'content.type.read',
        'content.type.create',
        'content.item.list',
        'content.item.read',
        'content.item.create',
        'content.item.update',
        'content.item.restore',
        'content.revision.list',
        'content.revision.read',
        'content.item.publish',
        'content.item.unpublish',
        'content.attachment.list',
        'content.attachment.attach',
        'content.attachment.detach',
        'content.attachment.reorder',
    ];

    public function test_catalog_contains_exact_frozen_operations(): void
    {
        self::assertSame(self::EXPECTED_CODES, ContentAccessOperationCatalog::codes());
        self::assertCount(16, ContentAccessOperationCatalog::operations());

        foreach (ContentAccessOperationCatalog::operations() as $operation) {
            self::assertSame('larena/content', $operation->ownerPackage);
            self::assertTrue($operation->auditDenials);
            self::assertContains($operation->risk, ['normal', 'high', 'critical']);
            self::assertNotSame('', $operation->requiredGrant);
        }

        self::assertFalse(ContentAccessOperationCatalog::contains('content.item.delete'));
        self::assertFalse(ContentAccessOperationCatalog::contains('content.public.read'));
    }

    public function test_yaml_and_php_catalogs_are_identical(): void
    {
        $descriptor = Yaml::parseFile(dirname(__DIR__, 2) . '/access.yaml');

        self::assertIsArray($descriptor);
        self::assertSame('larena.access.descriptor.v1', $descriptor['schema']);
        self::assertSame('larena/content', $descriptor['package']);
        self::assertSame(
            self::EXPECTED_CODES,
            array_column($descriptor['operations'], 'code'),
        );
        self::assertFalse($descriptor['nonclaims']['production_ready']);
        self::assertFalse($descriptor['nonclaims']['all_packages_ready']);
    }
}

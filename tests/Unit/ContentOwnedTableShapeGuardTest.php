<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Unit;

use Illuminate\Database\Connection;
use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Exceptions\ContentOwnedTableShapeRejected;
use Larena\Content\Tests\TestCase;
use RuntimeException;

final class ContentOwnedTableShapeGuardTest extends TestCase
{
    public function testFrozenOwnedTableNamesAndDropOrderAreExact(): void
    {
        $tables = [
            'larena_content_types',
            'larena_content_type_versions',
            'larena_content_items',
            'larena_content_item_revisions',
            'larena_content_item_revision_attachments',
            'larena_content_routes',
        ];

        self::assertSame($tables, ContentOwnedTableShapeGuard::tableNames());
        self::assertSame(array_reverse($tables), ContentOwnedTableShapeGuard::dropOrder());
    }

    public function testUnsupportedDriverFailsBeforePdoOrSchemaAccess(): void
    {
        $pdoRequested = false;
        $connection = new Connection(
            static function () use (&$pdoRequested): never {
                $pdoRequested = true;
                throw new RuntimeException('Unsupported-driver guard reached PDO.');
            },
            'not-used',
            '',
            ['driver' => 'pgsql'],
        );
        $guard = new ContentOwnedTableShapeGuard($connection);

        foreach (['preflightUp', 'assertCompleteCompatible', 'preflightDown'] as $method) {
            try {
                $guard->{$method}();
                self::fail($method.' unexpectedly accepted an unsupported driver.');
            } catch (ContentOwnedTableShapeRejected $exception) {
                self::assertSame('content_owned_table_driver_unsupported', $exception->reasonCode);
                self::assertSame('topology', $exception->tableKey);
                self::assertSame($exception->reasonCode, $exception->getMessage());
            }
        }

        self::assertFalse($pdoRequested);
    }
}

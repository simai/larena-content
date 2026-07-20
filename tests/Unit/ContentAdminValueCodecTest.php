<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Unit;

use InvalidArgumentException;
use Larena\Content\Rest\ContentAdminValueCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentAdminValueCodecTest extends TestCase
{
    public function test_value_list_preserves_declared_order_and_scalar_types(): void
    {
        $values = (new ContentAdminValueCodec())->values([
            ['key' => 'title', 'value' => 'Article'],
            ['key' => 'count', 'value' => 3],
            ['key' => 'featured', 'value' => true],
            ['key' => 'summary', 'value' => null],
        ]);

        self::assertSame(
            ['title', 'count', 'featured', 'summary'],
            array_keys($values),
        );
        self::assertSame(
            ['title' => 'Article', 'count' => 3, 'featured' => true, 'summary' => null],
            $values,
        );
    }

    /** @param array<array-key, mixed> $input */
    #[DataProvider('invalidValueLists')]
    public function test_value_list_rejects_ambiguous_or_unbounded_shapes(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ContentAdminValueCodec())->values($input);
    }

    /**
     * @return iterable<string, array{0:array<mixed>}>
     */
    public static function invalidValueLists(): iterable
    {
        yield 'duplicate key' => [[
            ['key' => 'title', 'value' => 'One'],
            ['key' => 'title', 'value' => 'Two'],
        ]];
        yield 'object instead of list' => [[
            'title' => ['key' => 'title', 'value' => 'One'],
        ]];
        yield 'nested value' => [[
            ['key' => 'title', 'value' => ['not' => 'scalar']],
        ]];
        yield 'extra entry field' => [[
            ['key' => 'title', 'value' => 'One', 'raw' => 'forbidden'],
        ]];
    }

    public function test_safe_metadata_rejects_private_or_unknown_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ContentAdminValueCodec())->safeMetadata([
            'label' => 'Article',
            'credentials' => 'forbidden',
        ]);
    }
}

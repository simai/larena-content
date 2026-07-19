<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Unit;

use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentAttachmentPlacement;
use Larena\Content\ValueObjects\ContentFieldDefinition;

final class ContentRuntimeLimitsTest extends TestCase
{
    public function testCanonicalJsonSortsObjectsAndPreservesLists(): void
    {
        $json = (new ContentCanonicalJson())->encode([
            'z' => ['b' => 2, 'a' => 1],
            'a' => [['z' => 2, 'a' => 1], 3],
        ]);

        self::assertSame('{"a":[{"a":1,"z":2},3],"z":{"a":1,"b":2}}', $json);
    }

    public function testFieldAndValueBoundariesAreExactlyOneHundred(): void
    {
        $guard = new ContentInputGuard();
        $fields = [];
        $values = [];

        for ($index = 0; $index < 100; $index++) {
            $key = sprintf('field_%03d', $index);
            $fields[] = new ContentFieldDefinition(
                $key,
                'string',
                ContentFieldVisibility::Public,
            );
            $values[$key] = 'value';
        }

        $guard->assertFields($fields);
        $guard->assertSubmittedValues($values);

        $this->expectReason(
            'field_limit_exceeded',
            function () use ($guard, $fields): void {
                $guard->assertFields([
                    ...$fields,
                    new ContentFieldDefinition(
                        'field_100',
                        'string',
                        ContentFieldVisibility::Public,
                    ),
                ]);
            },
        );

        $this->expectReason(
            'value_limit_exceeded',
            function () use ($guard, $values): void {
                $guard->assertSubmittedValues([
                    ...$values,
                    'field_100' => 'value',
                ]);
            },
        );
    }

    public function testStringAndCanonicalJsonLimitsFailClosed(): void
    {
        $guard = new ContentInputGuard();
        $guard->assertSubmittedValues([
            'body' => str_repeat('я', ContentInputGuard::MAX_STRING_CODEPOINTS),
        ]);

        $this->expectReason(
            'string_value_too_long',
            function () use ($guard): void {
                $guard->assertSubmittedValues([
                    'body' => str_repeat('я', ContentInputGuard::MAX_STRING_CODEPOINTS + 1),
                ]);
            },
        );

        $large = [];
        for ($index = 0; $index < 17; $index++) {
            $large[sprintf('field_%02d', $index)] = str_repeat(
                'x',
                ContentInputGuard::MAX_STRING_CODEPOINTS,
            );
        }

        $this->expectReason(
            'submitted_values_too_large',
            function () use ($guard, $large): void {
                $guard->assertSubmittedValues($large);
            },
        );
    }

    public function testMetadataPageRevisionAndAttachmentLimitsAreFrozen(): void
    {
        $guard = new ContentInputGuard();
        $guard->assertSafeTypeMetadata([
            'label' => 'Article',
            'sort' => 100,
            'hidden' => false,
        ]);
        $guard->assertPageLimit(100);
        $guard->assertMutableRevision(ContentInputGuard::MAX_MUTABLE_REVISION);
        $guard->assertCanIncrementRevision(ContentInputGuard::MAX_MUTABLE_REVISION - 1);

        $placements = [];
        for ($index = 0; $index < 100; $index++) {
            $placements[] = new ContentAttachmentPlacement(
                sprintf('018f62c6-9d27-7d19-b9b1-%012x', $index),
                'gallery',
                $index,
            );
        }
        $guard->assertAttachmentManifest($placements);

        $this->expectReason(
            'safe_type_metadata_too_large',
            function () use ($guard): void {
                $guard->assertSafeTypeMetadata([
                    'description' => str_repeat('x', ContentInputGuard::MAX_SAFE_METADATA_JSON_BYTES),
                ]);
            },
        );
        $this->expectReason(
            'safe_type_metadata_invalid',
            function () use ($guard): void {
                $guard->assertSafeTypeMetadata(['icon' => 'javascript:alert(1)']);
            },
        );
        $this->expectReason(
            'page_limit_invalid',
            function () use ($guard): void {
                $guard->assertPageLimit(101);
            },
        );
        $this->expectReason(
            'revision_limit_exceeded',
            function () use ($guard): void {
                $guard->assertMutableRevision(PHP_INT_MAX);
            },
        );
        $this->expectReason(
            'revision_limit_exceeded',
            function () use ($guard): void {
                $guard->assertCanIncrementRevision(ContentInputGuard::MAX_MUTABLE_REVISION);
            },
        );
        $this->expectReason(
            'attachment_limit_exceeded',
            function () use ($guard, $placements): void {
                $guard->assertAttachmentManifest([
                    ...$placements,
                    new ContentAttachmentPlacement(
                        '018f62c6-9d27-7d19-b9b1-000000000100',
                        'gallery',
                        100,
                    ),
                ]);
            },
        );
    }

    public function testStringConstraintCannotExceedSixtyFiveThousandCodepoints(): void
    {
        $guard = new ContentInputGuard();
        $guard->assertFields([
            new ContentFieldDefinition(
                'body',
                'string',
                ContentFieldVisibility::Public,
                false,
                ['max_length' => ContentInputGuard::MAX_STRING_CODEPOINTS],
            ),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        new ContentFieldDefinition(
            'body',
            'string',
            ContentFieldVisibility::Public,
            false,
            ['max_length' => ContentInputGuard::MAX_STRING_CODEPOINTS + 1],
        );
    }

    /**
     * @param callable(): mixed $callback
     */
    private function expectReason(string $reasonCode, callable $callback): void
    {
        try {
            $callback();
            self::fail(sprintf('Expected Content rejection "%s".', $reasonCode));
        } catch (ContentRejected $exception) {
            self::assertSame($reasonCode, $exception->reasonCode());
        }
    }
}

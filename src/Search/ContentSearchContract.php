<?php

declare(strict_types=1);

namespace Larena\Content\Search;

use Larena\Search\Contracts\SourceProvider;

final class ContentSearchContract
{
    public const PROVIDER_ID = 'content.published_items';

    public const OWNER_PACKAGE = 'larena/content';

    public const ACCESS_SCOPE = 'public';

    /**
     * @var list<string>
     */
    private const PROJECTION_FIELDS = [
        'provider_id',
        'source_ref',
        'source_revision',
        'title',
        'locator',
        'snippet',
        'locale',
        'access_scope',
        'searchable_text',
        'payload',
    ];

    /**
     * @var list<string>
     */
    private const PAYLOAD_FIELDS = [
        'type_key',
        'slug',
        'locale',
        'content_revision',
        'projection_version',
    ];

    public static function descriptor(): SourceProvider
    {
        return SourceProvider::declare(
            self::PROVIDER_ID,
            self::OWNER_PACKAGE,
            self::PROJECTION_FIELDS,
            self::ACCESS_SCOPE,
        );
    }

    /**
     * @return list<string>
     */
    public static function projectionFields(): array
    {
        return self::PROJECTION_FIELDS;
    }

    /**
     * @return list<string>
     */
    public static function payloadFields(): array
    {
        return self::PAYLOAD_FIELDS;
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function hasExactPayloadShape(array $payload): bool
    {
        $fields = array_keys($payload);
        sort($fields);
        $expected = self::PAYLOAD_FIELDS;
        sort($expected);

        return $fields === $expected;
    }
}

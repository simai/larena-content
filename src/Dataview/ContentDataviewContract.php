<?php

declare(strict_types=1);

namespace Larena\Content\Dataview;

use Larena\Dataview\Contracts\DataviewSourceDescriptor;

final class ContentDataviewContract
{
    public const SOURCE_KEY = 'content.items';

    public const OWNER_PACKAGE = 'larena/content';

    public static function descriptor(): DataviewSourceDescriptor
    {
        return new DataviewSourceDescriptor(
            sourceKey: self::SOURCE_KEY,
            ownerPackage: self::OWNER_PACKAGE,
            accessScoped: true,
            ownsCanonicalRecords: false,
        );
    }

    /**
     * Dataview is deliberately not a Content mutation surface.
     *
     * @return list<string>
     */
    public static function mutationOperations(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Services;

use Larena\Content\Contracts\ManagedContentRedirectReader;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Persistence\DatabaseSiteStructureRepository;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;

final readonly class DatabaseManagedContentRedirectReader implements ManagedContentRedirectReader
{
    public function __construct(
        private DatabaseSiteStructureRepository $siteStructure,
        private DatabaseContentRepository $content,
    ) {
    }

    public function resolve(ContentTypeKey $typeKey, ContentSlug $slug, ContentLocale $locale): ?array
    {
        $this->siteStructure->assertCompleteCompatible();
        $redirect = $this->siteStructure->redirect($typeKey->value, $locale->value, $slug->value);
        if ($redirect === null) {
            return null;
        }
        $item = $this->content->itemRow((string) $redirect['item_ref']);
        if (
            $item === null
            || $item['published_revision'] === null
            || !is_string($item['published_slug'])
            || $item['published_slug'] === ''
            || $item['published_slug'] === $slug->value
            || (string) $item['type_key'] !== $typeKey->value
            || (string) $item['locale'] !== $locale->value
        ) {
            return null;
        }

        return [
            'type_key' => $typeKey->value,
            'locale' => $locale->value,
            'slug' => (string) $item['published_slug'],
            'status' => 301,
        ];
    }
}

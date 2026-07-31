<?php

declare(strict_types=1);

namespace Larena\Content\FirstRun;

use Illuminate\Database\Connection;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Contracts\StarterSiteInitializer;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;
use Larena\Core\Contracts\FirstRunContributor;

final readonly class ContentStarterSiteService implements StarterSiteInitializer
{
    public function __construct(
        private Connection $connection,
        private ContentTypeService $types,
        private ContentItemService $items,
        private SiteStructureService $structures,
    ) {
    }

    public function firstRunState(): string
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (['larena_content_types', 'larena_content_items', 'larena_content_site_structures'] as $table) {
            if (!$schema->hasTable($table)) {
                return FirstRunContributor::STATE_PARTIAL;
            }
        }
        $types = $this->connection->table('larena_content_types')->count();
        $items = $this->connection->table('larena_content_items')->count();
        $structures = $this->connection->table('larena_content_site_structures')->count();
        if ($types === 0 && $items === 0 && $structures === 0) {
            return FirstRunContributor::STATE_EMPTY;
        }
        if ($this->connection->table('larena_content_types')->where('type_key', 'page')->exists()
            && $items > 0 && $structures > 0) {
            return FirstRunContributor::STATE_INITIALIZED;
        }

        return FirstRunContributor::STATE_PARTIAL;
    }

    public function initialize(string $subjectRef, string $siteName, string $locale): string
    {
        if ($this->firstRunState() !== FirstRunContributor::STATE_EMPTY) {
            throw new \DomainException('content_first_run_closed');
        }

        $actor = new ActorContext('user', $subjectRef, 'first-run-content-' . bin2hex(random_bytes(12)));
        $fields = [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('body', 'text', ContentFieldVisibility::Public, true),
        ];
        $typeKey = new ContentTypeKey('page');
        $this->types->create(
            $typeKey,
            $fields,
            new ContentProjectionContract(1, 'title', 'body', ['title', 'body'], $fields),
            ['label' => $locale === 'ru' ? 'Страницы' : 'Pages'],
            $actor,
        );

        $title = $locale === 'ru' ? 'Главная' : 'Home';
        $body = $locale === 'ru'
            ? 'Добро пожаловать на сайт «' . trim($siteName) . '». Отредактируйте и опубликуйте эту страницу.'
            : 'Welcome to ' . trim($siteName) . '. Edit and publish this page.';
        $item = $this->items->create(
            $typeKey,
            new ContentLocale($locale),
            new ContentSlug('home'),
            ContentVisibility::Public,
            ['title' => $title, 'body' => $body],
            $actor,
        );

        $this->structures->replace(
            0,
            [new SiteStructureNode($this->uuid(), null, 0, $title, true, 'content', $item->itemRef)],
            [new SiteSeoMetadata($item->itemRef, '/pages/page/home', $title, $body)],
            $actor,
        );

        return $item->itemRef->value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}

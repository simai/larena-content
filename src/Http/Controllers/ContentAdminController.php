<?php

declare(strict_types=1);

namespace Larena\Content\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Content\Admin\ContentAdminPresenter;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Rest\ContentAdminReadModel;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentRevisionQuery;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeQuery;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Filesystem\Contracts\ManagedFileSnapshotSource;
use Throwable;

final readonly class ContentAdminController
{
    /** @var list<string> */
    private const FIELD_TYPES = ['string', 'text', 'number', 'boolean', 'date', 'file', 'relation'];

    public function __construct(
        private ContentTypeService $types,
        private ContentItemService $items,
        private ContentAdminReadModel $reads,
        private ManagedFileSnapshotSource $files,
        private ContentLogicalFileInspector $fileInspector,
        private SiteStructureService $structures,
        private Factory $views,
        private Redirector $redirector,
        private AccessOperationAuthorizer $access,
        private ContentAdminPresenter $ui,
        private Translator $translator,
    ) {
    }

    public function workspace(Request $request): mixed
    {
        $actor = $this->actor($request);
        $types = $this->typeOptions($actor);
        $materials = $this->items->list(new ContentItemQuery(limit: 100), $actor)->items;
        $published = count(array_filter(
            $materials,
            static fn (ContentItem $item): bool => $item->publishedRevision !== null,
        ));
        $structure = null;
        try {
            $structure = $this->structures->read($actor);
        } catch (ContentRejected) {
            // A clean installation has no site-structure draft yet.
        }
        $fileOptions = $this->fileOptions();

        return $this->views->make('larena-content::admin.workspace', [
            'counts' => [
                'types' => count($types),
                'materials' => count($materials),
                'published' => $published,
                'files' => count($fileOptions['options']),
                'navigation' => $structure === null ? 0 : count($structure->nodes),
            ],
            'canCreateType' => $this->allowed($request, 'content.type.create'),
            'canCreateMaterial' => $this->allowed($request, 'content.item.create'),
            'canEditStructure' => $this->allowed($request, 'content.structure.update'),
            'fileIntegrationFailed' => $fileOptions['failed'],
            'ui' => $this->ui,
        ]);
    }

    public function types(Request $request): mixed
    {
        $actor = $this->actor($request);
        $rows = [];
        foreach ($this->types->list(new ContentTypeQuery(limit: 100), $actor)->items as $type) {
            $version = $this->types->version($type->typeKey, $type->currentVersion, $actor);
            $rows[] = [
                'key' => $type->typeKey->value,
                'label' => (string) ($version->safeMetadata['label'] ?? $type->typeKey->value),
                'version' => $type->currentVersion,
                'fields' => count($version->fieldDefinitions),
            ];
        }

        return $this->views->make('larena-content::admin.types.index', [
            'types' => $rows,
            'canCreate' => $this->allowed($request, 'content.type.create'),
            'ui' => $this->ui,
        ]);
    }

    public function createType(): mixed
    {
        return $this->views->make('larena-content::admin.types.create', [
            'fieldTypes' => self::FIELD_TYPES,
            'defaults' => [
                ['key' => 'title', 'type' => 'string', 'required' => true],
                ['key' => 'body', 'type' => 'text', 'required' => false],
                ['key' => 'weight', 'type' => 'number', 'required' => false],
                ['key' => 'featured', 'type' => 'boolean', 'required' => false],
                ['key' => 'publish_date', 'type' => 'date', 'required' => false],
                ['key' => 'hero_file', 'type' => 'file', 'required' => false],
                ['key' => 'related_material', 'type' => 'relation', 'required' => false],
            ],
            'ui' => $this->ui,
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type_key' => ['required', 'regex:/\A[a-z][a-z0-9_.]{0,63}\z/D'],
            'label' => ['required', 'string', 'max:191'],
            'plural_label' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'fields' => ['required', 'array', 'size:7'],
            'fields.*.key' => ['required', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/D', 'distinct'],
            'fields.*.type' => ['required', 'in:' . implode(',', self::FIELD_TYPES), 'distinct'],
            'fields.*.required' => ['sometimes', 'boolean'],
        ]);

        $fields = [];
        foreach ($validated['fields'] as $row) {
            $fields[] = new ContentFieldDefinition(
                key: (string) $row['key'],
                propertyType: (string) $row['type'],
                visibility: ContentFieldVisibility::Public,
                required: (bool) ($row['required'] ?? false),
            );
        }
        $title = $this->fieldKey($fields, 'string');
        $snippet = $this->optionalFieldKey($fields, 'text');
        $searchable = array_values(array_map(
            static fn (ContentFieldDefinition $field): string => $field->key,
            array_filter($fields, static fn (ContentFieldDefinition $field): bool => $field->isPublicSearchScalar()),
        ));

        try {
            $this->types->create(
                new ContentTypeKey((string) $validated['type_key']),
                $fields,
                new ContentProjectionContract(1, $title, $snippet, $searchable, $fields),
                array_filter([
                    'label' => trim((string) $validated['label']),
                    'plural_label' => self::nullable($validated['plural_label'] ?? null),
                    'description' => self::nullable($validated['description'] ?? null),
                ], static fn (mixed $value): bool => $value !== null),
                $this->actor($request),
            );
        } catch (ContentRejected|\InvalidArgumentException $exception) {
            return $this->rejected($exception, 'type')->withInput();
        }

        return $this->redirector->route('larena.content.admin.types.index')
            ->with('status', $this->text('messages.type_created'));
    }

    public function materials(Request $request): mixed
    {
        $actor = $this->actor($request);
        $typeKey = $this->optionalTypeKey($request->query('type_key'));
        $typeOptions = $this->typeOptions($actor);
        $typeLabels = array_column($typeOptions, 'label', 'key');
        $rows = [];
        foreach ($this->items->list(new ContentItemQuery(typeKey: $typeKey, limit: 100), $actor)->items as $item) {
            $data = $this->reads->item($item, $actor);
            $rows[] = [
                'item' => $item,
                'title' => $this->title($data),
                'type_label' => $typeLabels[$item->typeKey->value] ?? Str::headline($item->typeKey->value),
                'public_url' => $item->publishedSlug === null ? null : '/pages/'
                    . rawurlencode($item->typeKey->value) . '/'
                    . rawurlencode($item->publishedSlug->value)
                    . ($item->locale->value === 'en' ? '' : '?locale=' . rawurlencode($item->locale->value)),
            ];
        }

        return $this->views->make('larena-content::admin.materials.index', [
            'materials' => $rows,
            'types' => $typeOptions,
            'selectedType' => $typeKey?->value,
            'canCreate' => $this->allowed($request, 'content.item.create'),
            'ui' => $this->ui,
        ]);
    }

    public function createMaterial(Request $request): mixed
    {
        $actor = $this->actor($request);
        $typeKey = $this->optionalTypeKey($request->query('type_key'));

        return $this->views->make('larena-content::admin.materials.form', [
            ...$this->formData($actor, $typeKey, null),
            'material' => null,
            'values' => [],
            'revisions' => [],
            'ui' => $this->ui,
        ]);
    }

    public function storeMaterial(Request $request): RedirectResponse
    {
        $validated = $this->validateMaterial($request, false);
        $actor = $this->actor($request);
        $typeKey = new ContentTypeKey((string) $validated['type_key']);
        $type = $this->currentType($typeKey, $actor);
        try {
            $item = $this->items->create(
                $typeKey,
                new ContentLocale((string) $validated['locale']),
                new ContentSlug((string) $validated['slug']),
                ContentVisibility::from((string) $validated['visibility']),
                $this->values($request, $type),
                $actor,
            );
        } catch (ContentRejected|\InvalidArgumentException $exception) {
            return $this->rejected($exception, 'material')->withInput();
        }

        return $this->redirector->route('larena.content.admin.materials.edit', $item->itemRef->uuid())
            ->with('status', $this->text('messages.material_created'));
    }

    public function editMaterial(Request $request, string $itemRef): mixed
    {
        $actor = $this->actor($request);
        $item = $this->items->read(ContentItemRef::fromUuid($itemRef), $actor);
        $data = $this->reads->item($item, $actor);

        return $this->views->make('larena-content::admin.materials.form', [
            ...$this->formData($actor, $item->typeKey, $item),
            'material' => $item,
            'values' => $this->valueMap($data),
            'revisions' => $this->items->revisions(new ContentRevisionQuery($item->itemRef, limit: 100), $actor)->items,
            'canUpdate' => $this->allowed($request, 'content.item.update'),
            'canSubmit' => $this->allowed($request, 'content.item.submit_review'),
            'canPublish' => $this->allowed($request, 'content.item.publish'),
            'canUnpublish' => $this->allowed($request, 'content.item.unpublish'),
            'canRestore' => $this->allowed($request, 'content.item.restore'),
            'ui' => $this->ui,
        ]);
    }

    public function previewMaterial(Request $request, string $itemRef): mixed
    {
        $actor = $this->actor($request);
        $item = $this->items->read(ContentItemRef::fromUuid($itemRef), $actor);
        $data = $this->reads->item($item, $actor);
        $type = $this->currentType($item->typeKey, $actor);
        $labels = [];
        foreach ($type->fieldDefinitions as $field) {
            $labels[$field->key] = $this->ui->fieldLabel($field->key);
        }

        return $this->views->make('larena-content::admin.materials.preview', [
            'material' => $item,
            'title' => $this->title($data),
            'values' => $this->valueMap($data),
            'labels' => $labels,
            'ui' => $this->ui,
        ]);
    }

    public function updateMaterial(Request $request, string $itemRef): RedirectResponse
    {
        $validated = $this->validateMaterial($request, true);
        $actor = $this->actor($request);
        $item = $this->items->read(ContentItemRef::fromUuid($itemRef), $actor);
        $type = $this->currentType($item->typeKey, $actor);
        try {
            $this->items->update(
                $item->itemRef,
                (int) $validated['expected_revision'],
                new ContentSlug((string) $validated['slug']),
                ContentVisibility::from((string) $validated['visibility']),
                $this->values($request, $type),
                $actor,
            );
        } catch (ContentConflict) {
            return $this->redirector->back()->withErrors(['expected_revision' => $this->text('messages.stale')])->withInput();
        } catch (ContentRejected|\InvalidArgumentException $exception) {
            return $this->rejected($exception, 'material')->withInput();
        }

        return $this->redirector->route('larena.content.admin.materials.edit', $item->itemRef->uuid())
            ->with('status', $this->text('messages.material_saved'));
    }

    public function submit(Request $request, string $itemRef): RedirectResponse
    {
        return $this->transition($request, $itemRef, 'submitted', fn (ContentItem $item, int $expected, ActorContext $actor): ContentItem => $this->items->submitForReview($item->itemRef, $expected, $actor));
    }

    public function publish(Request $request, string $itemRef): RedirectResponse
    {
        return $this->transition($request, $itemRef, 'published', fn (ContentItem $item, int $expected, ActorContext $actor): ContentItem => $this->items->publish($item->itemRef, $expected, $actor));
    }

    public function unpublish(Request $request, string $itemRef): RedirectResponse
    {
        return $this->transition($request, $itemRef, 'unpublished', fn (ContentItem $item, int $expected, ActorContext $actor): ContentItem => $this->items->unpublish($item->itemRef, $expected, $actor));
    }

    public function restore(Request $request, string $itemRef, int $revision): RedirectResponse
    {
        return $this->transition($request, $itemRef, 'restored', fn (ContentItem $item, int $expected, ActorContext $actor): ContentItem => $this->items->restore($item->itemRef, $revision, $expected, $actor));
    }

    /** @return array<string, mixed> */
    private function formData(ActorContext $actor, ?ContentTypeKey $typeKey, ?ContentItem $item): array
    {
        $type = $typeKey === null ? null : $this->currentType($typeKey, $actor);
        $fileOptions = $type === null
            ? ['options' => [], 'failed' => false]
            : $this->fileOptions();

        return [
            'types' => $this->typeOptions($actor),
            'type' => $type,
            'files' => $fileOptions['options'],
            'fileIntegrationFailed' => $fileOptions['failed'],
            'relations' => $type === null ? [] : $this->relationOptions($actor, $item?->itemRef),
        ];
    }

    /** @return array<string, mixed> */
    private function validateMaterial(Request $request, bool $updating): array
    {
        return $request->validate([
            'type_key' => [$updating ? 'sometimes' : 'required', 'regex:/\A[a-z][a-z0-9_.]{0,63}\z/D'],
            'locale' => [$updating ? 'sometimes' : 'required', 'regex:/\A[a-z]{2}(?:-[A-Z]{2})?\z/D'],
            'slug' => ['required', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D'],
            'visibility' => ['required', 'in:public,private'],
            'expected_revision' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'values' => ['sometimes', 'array', 'max:100'],
            'values.*' => ['nullable'],
        ]);
    }

    /** @return array<string, scalar|null> */
    private function values(Request $request, ContentTypeVersion $type): array
    {
        $input = $request->input('values', []);
        $input = is_array($input) ? $input : [];
        $values = [];
        foreach ($type->fieldDefinitions as $field) {
            $raw = $input[$field->key] ?? null;
            if ($field->propertyType === 'boolean') {
                $values[$field->key] = filter_var($raw, FILTER_VALIDATE_BOOL);
                continue;
            }
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $values[$field->key] = $value === '' ? null : $value;
        }

        return $values;
    }

    private function transition(Request $request, string $itemRef, string $message, callable $operation): RedirectResponse
    {
        /** @var array{expected_revision:int|string} $validated */
        $validated = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $item = $this->items->read(ContentItemRef::fromUuid($itemRef), $actor);
        try {
            $operation($item, (int) $validated['expected_revision'], $actor);
        } catch (ContentConflict) {
            return $this->redirector->back()->withErrors(['expected_revision' => $this->text('messages.stale')]);
        } catch (ContentRejected $exception) {
            return $this->rejected($exception, 'material');
        }

        return $this->redirector->route('larena.content.admin.materials.edit', $item->itemRef->uuid())
            ->with('status', $this->text('messages.' . $message));
    }

    /** @return list<array{key:string,label:string}> */
    private function typeOptions(ActorContext $actor): array
    {
        $options = [];
        foreach ($this->types->list(new ContentTypeQuery(limit: 100), $actor)->items as $type) {
            $version = $this->types->version($type->typeKey, $type->currentVersion, $actor);
            $options[] = ['key' => $type->typeKey->value, 'label' => (string) ($version->safeMetadata['label'] ?? $type->typeKey->value)];
        }

        return $options;
    }

    /** @return list<array{ref:string,label:string}> */
    private function relationOptions(ActorContext $actor, ?ContentItemRef $exclude): array
    {
        $options = [];
        foreach ($this->items->list(new ContentItemQuery(limit: 100), $actor)->items as $item) {
            if ($exclude?->value === $item->itemRef->value) {
                continue;
            }
            $options[] = ['ref' => $item->itemRef->uuid(), 'label' => $this->title($this->reads->item($item, $actor))];
        }

        return $options;
    }

    /** @return array{options:list<array{ref:string,label:string}>,failed:bool} */
    private function fileOptions(): array
    {
        $options = [];
        try {
            foreach ($this->files->snapshots() as $file) {
                $inspection = $this->fileInspector->inspect($file->logicalRef);
                $name = $inspection->safeMetadata['display_name'] ?? $this->text('materials.unnamed_file');
                $options[] = [
                    'ref' => $file->logicalRef,
                    'label' => $name . ' · ' . $file->mimeType,
                ];
            }
        } catch (Throwable) {
            return ['options' => [], 'failed' => true];
        }

        return ['options' => $options, 'failed' => false];
    }

    private function currentType(ContentTypeKey $key, ActorContext $actor): ContentTypeVersion
    {
        $type = $this->types->read($key, $actor);

        return $this->types->version($key, $type->currentVersion, $actor);
    }

    /** @param array<string, mixed> $data */
    private function title(array $data): string
    {
        $values = $this->valueMap($data);
        $title = $values['title'] ?? reset($values);

        return is_scalar($title) && (string) $title !== '' ? (string) $title : (string) $data['item_ref'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string|int|bool|null>
     */
    private function valueMap(array $data): array
    {
        $values = [];
        foreach (($data['revision']['values'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null)) {
                $values[$entry['key']] = $entry['value'] ?? null;
            }
        }

        return $values;
    }

    /** @param list<ContentFieldDefinition> $fields */
    private function fieldKey(array $fields, string $type): string
    {
        return $this->optionalFieldKey($fields, $type)
            ?? throw new \InvalidArgumentException('content_admin_required_field_type_missing');
    }

    /** @param list<ContentFieldDefinition> $fields */
    private function optionalFieldKey(array $fields, string $type): ?string
    {
        foreach ($fields as $field) {
            if ($field->propertyType === $type) {
                return $field->key;
            }
        }

        return null;
    }

    private function optionalTypeKey(mixed $value): ?ContentTypeKey
    {
        return is_string($value) && $value !== '' ? new ContentTypeKey($value) : null;
    }

    private function actor(Request $request): ActorContext
    {
        $correlation = $request->headers->get('X-Correlation-ID');

        return new ActorContext(
            'user',
            (string) $request->attributes->get('larena_access_actor'),
            is_string($correlation) && $correlation !== '' ? $correlation : 'http:' . Str::uuid(),
        );
    }

    private function allowed(Request $request, string $operation): bool
    {
        return $this->access->authorize($request, $operation)->isAllowed();
    }

    private function rejected(Throwable $exception, string $key): RedirectResponse
    {
        $reason = $exception instanceof ContentRejected ? $exception->reasonCode() : 'input_invalid';
        $reasonKey = 'larena-content::admin.messages.reasons.' . $reason;
        $translatedReason = (string) $this->translator->get($reasonKey);
        $reason = $translatedReason === $reasonKey ? $reason : $translatedReason;

        return $this->redirector->back()->withErrors([$key => $this->text('messages.rejected', ['reason' => $reason])]);
    }

    private static function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** @param array<string, scalar|null> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-content::admin.' . $key, $replace);
    }
}

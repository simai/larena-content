<?php

declare(strict_types=1);

namespace Larena\Content\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Content\Admin\SiteStructureAdminPresenter;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;
use Larena\Content\ValueObjects\SiteStructureRevision;

final readonly class SiteStructureAdminController
{
    public function __construct(
        private SiteStructureService $structures,
        private ContentItemService $items,
        private \Larena\Content\Rest\ContentAdminReadModel $reads,
        private Factory $views,
        private Redirector $redirector,
        private AccessOperationAuthorizer $access,
        private SiteStructureAdminPresenter $ui,
        private Translator $translator,
    ) {
    }

    public function index(Request $request): mixed
    {
        $actor = $this->actor($request);
        try {
            $revision = $this->structures->read($actor);
        } catch (ContentRejected $exception) {
            if ($exception->reasonCode() !== 'site_structure_not_found') {
                throw $exception;
            }
            $revision = null;
        }

        return $this->render($request, $actor, $revision, false);
    }

    public function revision(Request $request, int $revision): mixed
    {
        $actor = $this->actor($request);

        return $this->render($request, $actor, $this->structures->revision($revision, $actor), true);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'nodes' => ['sometimes', 'array', 'max:200'],
            'nodes.*.node_ref' => ['required', 'uuid'],
            'nodes.*.parent_ref' => ['nullable', 'uuid'],
            'nodes.*.position' => ['required', 'integer', 'min:0', 'max:199'],
            'nodes.*.label' => ['required', 'string', 'max:200'],
            'nodes.*.visible' => ['required', 'boolean'],
            'nodes.*.target_type' => ['required', 'in:content,external'],
            'nodes.*.content_item_ref' => ['nullable', 'required_if:nodes.*.target_type,content', 'string', 'max:64'],
            'nodes.*.external_url' => ['nullable', 'required_if:nodes.*.target_type,external', 'url:https', 'max:2048'],
            'nodes.*.remove' => ['sometimes', 'boolean'],
            'seo' => ['sometimes', 'array', 'max:500'],
            'seo.*.item_ref' => ['required', 'string', 'max:64'],
            'seo.*.canonical_path' => ['nullable', 'string', 'max:2048'],
            'seo.*.seo_title' => ['nullable', 'string', 'max:255'],
            'seo.*.description' => ['nullable', 'string', 'max:500'],
            'seo.*.robots' => ['required', 'string', static function (string $attribute, mixed $value, callable $fail): void {
                if (!is_string($value) || !in_array($value, ['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'], true)) {
                    $fail('The robots policy is invalid.');
                }
            }],
            'seo.*.remove' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->structures->replace(
                (int) $validated['expected_revision'],
                $this->nodes($this->rows($validated['nodes'] ?? [])),
                $this->seo($this->rows($validated['seo'] ?? [])),
                $this->actor($request),
            );
        } catch (ContentConflict) {
            return $this->redirector->back()->withErrors(['expected_revision' => $this->text('messages.stale')])->withInput();
        } catch (ContentRejected|\InvalidArgumentException $exception) {
            $reason = $exception instanceof ContentRejected ? $exception->reasonCode() : 'site_structure_input_invalid';

            return $this->redirector->back()->withErrors(['structure' => $this->text('messages.rejected', ['reason' => $reason])])->withInput();
        }

        return $this->redirector->route('larena.content.admin.structure.index')
            ->with('status', $this->text('messages.saved'));
    }

    public function submitForReview(Request $request): RedirectResponse
    {
        return $this->transition($request, fn (int $revision, ActorContext $actor): SiteStructureRevision => $this->structures->submitForReview($revision, $actor), 'submitted');
    }

    public function publish(Request $request): RedirectResponse
    {
        return $this->transition($request, fn (int $revision, ActorContext $actor): SiteStructureRevision => $this->structures->publish($revision, $actor), 'published');
    }

    public function restore(Request $request, int $revision): RedirectResponse
    {
        return $this->transition($request, fn (int $expected, ActorContext $actor): SiteStructureRevision => $this->structures->restore($revision, $expected, $actor), 'restored');
    }

    private function render(Request $request, ActorContext $actor, ?SiteStructureRevision $revision, bool $historical): mixed
    {
        $contentOptions = $this->contentOptions($actor);
        $nodes = array_map(static fn (SiteStructureNode $node): array => $node->toArray(), $revision instanceof SiteStructureRevision ? $revision->nodes : []);
        $seo = array_map(static fn (SiteSeoMetadata $entry): array => $entry->toArray(), $revision instanceof SiteStructureRevision ? $revision->seo : []);
        if (!$historical && $request->boolean('add_node')) {
            $nodes[] = [
                'node_ref' => (string) Str::uuid(), 'parent_ref' => null,
                'position' => count(array_filter($nodes, static fn (array $node): bool => $node['parent_ref'] === null)),
                'label' => '', 'visible' => true, 'target_type' => $contentOptions === [] ? 'external' : 'content',
                'content_item_ref' => $contentOptions[0]['ref'] ?? null,
                'external_url' => $contentOptions === [] ? 'https://example.com' : null,
            ];
        }
        if (!$historical && $request->boolean('add_seo')) {
            $used = array_column($seo, 'item_ref');
            $candidate = current(array_filter($contentOptions, static fn (array $option): bool => !in_array($option['ref'], $used, true)));
            $seo[] = ['item_ref' => is_array($candidate) ? $candidate['ref'] : '', 'canonical_path' => null, 'seo_title' => null, 'description' => null, 'robots' => 'index,follow'];
        }
        $canRedirects = $this->allowed($request, 'content.redirect.list');

        return $this->views->make('larena-content::admin.site-structure', [
            'revision' => $revision,
            'nodes' => $nodes,
            'seo' => $seo,
            'contentOptions' => $contentOptions,
            'contentLabels' => array_column($contentOptions, 'label', 'ref'),
            'revisions' => $this->structures->revisions($actor),
            'redirects' => $canRedirects ? $this->structures->redirects($actor) : [],
            'canRedirects' => $canRedirects,
            'canUpdate' => !$historical && $this->allowed($request, 'content.structure.update'),
            'canSubmit' => !$historical && $this->allowed($request, 'content.structure.submit_review'),
            'canPublish' => !$historical && $this->allowed($request, 'content.structure.publish'),
            'canRestore' => $historical && $this->allowed($request, 'content.structure.restore'),
            'historical' => $historical,
            'ui' => $this->ui,
        ]);
    }

    /** @return list<array{ref:string,label:string,published:bool}> */
    private function contentOptions(ActorContext $actor): array
    {
        $options = [];
        foreach ($this->items->list(new ContentItemQuery(limit: 100), $actor)->items as $item) {
            $data = $this->reads->item($item, $actor);
            $values = [];
            foreach (($data['revision']['values'] ?? []) as $entry) {
                if (is_array($entry) && is_string($entry['key'] ?? null)) {
                    $values[$entry['key']] = $entry['value'] ?? null;
                }
            }
            $title = $values['title'] ?? reset($values);
            $label = is_scalar($title) && trim((string) $title) !== ''
                ? trim((string) $title)
                : $item->currentSlug->value;
            $options[] = [
                'ref' => $item->itemRef->value,
                'label' => $label . ' · /' . $item->currentSlug->value,
                'published' => $item->publishedRevision !== null,
            ];
        }

        return $options;
    }

    private function transition(Request $request, callable $operation, string $message): RedirectResponse
    {
        /** @var array{expected_revision:int|string} $validated */
        $validated = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        try {
            $operation((int) $validated['expected_revision'], $this->actor($request));
        } catch (ContentConflict) {
            return $this->redirector->back()->withErrors(['expected_revision' => $this->text('messages.stale')]);
        } catch (ContentRejected $exception) {
            return $this->redirector->back()->withErrors(['structure' => $this->text('messages.rejected', ['reason' => $exception->reasonCode()])]);
        }

        return $this->redirector->route('larena.content.admin.structure.index')
            ->with('status', $this->text('messages.' . $message));
    }

    /**
     * @param list<array<string, mixed>> $input
     * @return list<SiteStructureNode>
     */
    private function nodes(array $input): array
    {
        $rows = array_values(array_filter($input, static fn (array $row): bool => !(bool) ($row['remove'] ?? false)));
        usort($rows, static fn (array $a, array $b): int => [(string) ($a['parent_ref'] ?? ''), (int) $a['position']] <=> [(string) ($b['parent_ref'] ?? ''), (int) $b['position']]);
        $positions = [];

        return array_map(static function (array $row) use (&$positions): SiteStructureNode {
            $parent = self::nullable($row['parent_ref'] ?? null);
            $position = $positions[$parent ?? ''] ?? 0;
            $positions[$parent ?? ''] = $position + 1;
            $type = (string) $row['target_type'];

            return new SiteStructureNode(
                (string) $row['node_ref'], $parent, $position, trim((string) $row['label']),
                (bool) $row['visible'], $type,
                $type === 'content' ? new ContentItemRef((string) $row['content_item_ref']) : null,
                $type === 'external' ? self::nullable($row['external_url'] ?? null) : null,
            );
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $input
     * @return list<SiteSeoMetadata>
     */
    private function seo(array $input): array
    {
        return array_values(array_map(static fn (array $row): SiteSeoMetadata => new SiteSeoMetadata(
            new ContentItemRef((string) $row['item_ref']),
            self::nullable($row['canonical_path'] ?? null), self::nullable($row['seo_title'] ?? null),
            self::nullable($row['description'] ?? null), (string) $row['robots'],
        ), array_filter($input, static fn (array $row): bool => !(bool) ($row['remove'] ?? false))));
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

    private static function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
    }

    /** @param array<string, scalar|null> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-content::admin.' . $key, $replace);
    }
}

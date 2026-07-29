<?php

declare(strict_types=1);

namespace Larena\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\Factory;
use Larena\Content\Contracts\ManagedContentRedirectReader;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PublishedContentController
{
    public function __construct(
        private PublishedContentReader $reader,
        private ?ManagedContentRedirectReader $redirects = null,
        private ?SiteStructureService $structures = null,
        private ?Factory $views = null,
    ) {
    }

    public function page(Request $request, string $typeKey, string $slug): mixed
    {
        $locale = $request->query('locale', 'en');
        if (!is_string($locale)) {
            throw new NotFoundHttpException();
        }
        try {
            $projection = $this->reader->read(
                new ContentTypeKey($typeKey),
                new ContentSlug($slug),
                new ContentLocale($locale),
            );
        } catch (ContentNotPublic|\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        if (!$this->views instanceof Factory) {
            throw new \LogicException('The public Content page view factory is unavailable.');
        }
        $page = $projection->toArray();
        $contract = $projection->projectionContract();
        $publishedStructure = null;
        try {
            $publishedStructure = $this->structures?->published();
        } catch (ContentNotPublic|ContentRejected) {
            // A published Content item remains readable before site structure exists.
        }
        $metadata = is_array($publishedStructure)
            && is_array($publishedStructure['seo'][$projection->itemRef->value] ?? null)
            ? $publishedStructure['seo'][$projection->itemRef->value]
            : [];
        $contentTitle = (string) ($page['public_fields'][$contract->titleField] ?? $page['slug']);
        $contentDescription = $contract->snippetField === null
            ? null
            : ($page['public_fields'][$contract->snippetField] ?? null);
        $canonicalPath = $metadata['canonical_path'] ?? null;

        return $this->views->make('larena-content::public.page', [
            'page' => $page,
            'titleField' => $contract->titleField,
            'title' => is_string($metadata['seo_title'] ?? null) && $metadata['seo_title'] !== ''
                ? $metadata['seo_title']
                : $contentTitle,
            'heading' => $contentTitle,
            'description' => is_string($metadata['description'] ?? null) && $metadata['description'] !== ''
                ? $metadata['description']
                : $contentDescription,
            'canonicalUrl' => is_string($canonicalPath) && str_starts_with($canonicalPath, '/')
                ? $request->getSchemeAndHttpHost().$canonicalPath
                : null,
            'robots' => is_string($metadata['robots'] ?? null) ? $metadata['robots'] : null,
            'navigation' => $this->pageNavigation(is_array($publishedStructure) ? $publishedStructure['nodes'] ?? [] : []),
        ]);
    }

    /**
     * @param mixed $nodes
     * @return list<array{label:string,url:string}>
     */
    private function pageNavigation(mixed $nodes): array
    {
        if (!is_array($nodes)) {
            return [];
        }
        $navigation = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['label'] ?? null) || !is_array($node['target'] ?? null)) {
                continue;
            }
            $url = $node['target']['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (($node['target']['type'] ?? null) === 'content' && str_starts_with($url, '/content/')) {
                $url = '/pages/'.substr($url, strlen('/content/'));
            }
            $navigation[] = ['label' => $node['label'], 'url' => $url];
        }

        return $navigation;
    }

    public function show(
        Request $request,
        string $typeKey,
        string $slug,
    ): JsonResponse|RedirectResponse {
        $locale = $request->query('locale', 'en');
        if (!is_string($locale)) {
            throw new NotFoundHttpException();
        }

        $type = null;
        $contentSlug = null;
        $contentLocale = null;
        try {
            $type = new ContentTypeKey($typeKey);
            $contentSlug = new ContentSlug($slug);
            $contentLocale = new ContentLocale($locale);
            $projection = $this->reader->read(
                $type,
                $contentSlug,
                $contentLocale,
            );
        } catch (ContentNotPublic) {
            if (
                !$type instanceof ContentTypeKey
                || !$contentSlug instanceof ContentSlug
                || !$contentLocale instanceof ContentLocale
            ) {
                throw new NotFoundHttpException();
            }
            $redirect = $this->redirects?->resolve($type, $contentSlug, $contentLocale);
            if ($redirect === null) {
                throw new NotFoundHttpException();
            }
            $target = '/content/'.$redirect['type_key'].'/'.$redirect['slug'];
            if ($redirect['locale'] !== 'en') {
                $target .= '?locale='.rawurlencode($redirect['locale']);
            }

            return new RedirectResponse($target, $redirect['status'], ['Cache-Control' => 'public, max-age=300']);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }

        $headers = [
            'Cache-Control' => 'public, max-age=60',
            'Content-Type' => 'application/json; charset=UTF-8',
        ];
        try {
            $published = $this->structures?->published();
            $metadata = is_array($published) && is_array($published['seo'][$projection->itemRef->value] ?? null)
                ? $published['seo'][$projection->itemRef->value]
                : null;
            if (is_array($metadata)) {
                $canonical = $metadata['canonical_path'] ?? null;
                if (is_string($canonical) && str_starts_with($canonical, '/')) {
                    $headers['Link'] = '<' . $request->getSchemeAndHttpHost() . $canonical . '>; rel="canonical"';
                }
                if (is_string($metadata['robots'] ?? null)) {
                    $headers['X-Robots-Tag'] = $metadata['robots'];
                }
            }
        } catch (ContentNotPublic|ContentRejected) {
            // Published Content remains readable when no site-structure revision exists yet.
        }

        return new JsonResponse(
            data: $projection->toArray(),
            status: 200,
            headers: $headers,
            json: false,
        );
    }
}

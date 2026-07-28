<?php

declare(strict_types=1);

namespace Larena\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    ) {
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

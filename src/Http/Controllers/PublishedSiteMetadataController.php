<?php

declare(strict_types=1);

namespace Larena\Content\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Exceptions\ContentRejected;

final readonly class PublishedSiteMetadataController
{
    public function __construct(private SiteStructureService $structures)
    {
    }

    public function robots(Request $request): Response
    {
        $body = "User-agent: *\nAllow: /\nSitemap: " . $request->getSchemeAndHttpHost() . "/sitemap.xml\n";

        return new Response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function sitemap(Request $request): Response
    {
        try {
            $published = $this->structures->published();
        } catch (ContentNotPublic|ContentRejected) {
            $published = ['nodes' => [], 'seo' => []];
        }
        $paths = [];
        foreach ((array) $published['nodes'] as $node) {
            $target = is_array($node) && is_array($node['target'] ?? null) ? $node['target'] : [];
            if (($target['type'] ?? null) !== 'content' || !is_string($target['url'] ?? null)) {
                continue;
            }
            $itemRef = is_string($target['item_ref'] ?? null) ? $target['item_ref'] : '';
            $metadata = is_array($published['seo'][$itemRef] ?? null) ? $published['seo'][$itemRef] : [];
            if (str_starts_with((string) ($metadata['robots'] ?? 'index,follow'), 'noindex')) {
                continue;
            }
            $paths[$itemRef !== '' ? $itemRef : (string) $target['url']] = (string) ($metadata['canonical_path'] ?? $target['url']);
        }
        foreach ((array) $published['seo'] as $itemRef => $metadata) {
            if (!is_array($metadata) || !is_string($metadata['canonical_path'] ?? null) || str_starts_with((string) ($metadata['robots'] ?? 'index,follow'), 'noindex')) {
                continue;
            }
            $paths[(string) $itemRef] = $metadata['canonical_path'];
        }
        sort($paths, SORT_STRING);
        $origin = $request->getSchemeAndHttpHost();
        $urls = array_map(static fn (string $path): string => $origin . $path, array_values(array_unique($paths)));
        $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $body .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
        }
        $body .= "</urlset>\n";

        return new Response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Larena\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PublishedContentController
{
    public function __construct(private PublishedContentReader $reader)
    {
    }

    public function show(
        Request $request,
        string $typeKey,
        string $slug,
    ): JsonResponse {
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

        return new JsonResponse(
            data: $projection->toArray(),
            status: 200,
            headers: [
                'Cache-Control' => 'public, max-age=60',
                'Content-Type' => 'application/json; charset=UTF-8',
            ],
            json: false,
        );
    }
}

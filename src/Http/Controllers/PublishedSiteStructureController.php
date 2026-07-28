<?php

declare(strict_types=1);

namespace Larena\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Exceptions\ContentRejected;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PublishedSiteStructureController
{
    public function __construct(private SiteStructureService $structures)
    {
    }

    public function show(): JsonResponse
    {
        try {
            $projection = $this->structures->published();
        } catch (ContentNotPublic|ContentRejected) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse(
            data: $projection,
            status: 200,
            headers: [
                'Cache-Control' => 'public, max-age=60',
                'Content-Type' => 'application/json; charset=UTF-8',
            ],
            json: false,
        );
    }
}

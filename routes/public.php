<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Content\Http\Controllers\PublishedContentController;
use Larena\Content\Http\Controllers\PublishedSiteStructureController;
use Larena\Content\Http\Controllers\PublishedSiteMetadataController;

Route::get('/robots.txt', [PublishedSiteMetadataController::class, 'robots'])
    ->name('larena.content.robots.public');

Route::get('/sitemap.xml', [PublishedSiteMetadataController::class, 'sitemap'])
    ->name('larena.content.sitemap.public');

Route::get('/content/site-structure', [PublishedSiteStructureController::class, 'show'])
    ->name('larena.content.structure.public');

Route::get('/content/navigation', [PublishedSiteStructureController::class, 'show'])
    ->name('larena.content.navigation.public');

Route::get('/content/{typeKey}/{slug}', [PublishedContentController::class, 'show'])
    ->where([
        'typeKey' => '[a-z][a-z0-9_.]{0,63}',
        'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
    ])
    ->name('larena.content.public.show');

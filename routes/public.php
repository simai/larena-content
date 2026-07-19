<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Content\Http\Controllers\PublishedContentController;

Route::get('/content/{typeKey}/{slug}', [PublishedContentController::class, 'show'])
    ->where([
        'typeKey' => '[a-z][a-z0-9_.]{0,63}',
        'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
    ])
    ->name('larena.content.public.show');

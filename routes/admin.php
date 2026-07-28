<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Content\Http\Controllers\SiteStructureAdminController;

Route::prefix((string) config('larena-content.admin.prefix', 'admin/content/site-structure'))
    ->middleware((array) config('larena-content.admin.middleware', []))
    ->name('larena.content.admin.structure.')
    ->group(static function (): void {
        Route::middleware((array) config('larena-content.admin.read_middleware', []))->group(static function (): void {
            Route::get('/', [SiteStructureAdminController::class, 'index'])->name('index');
            Route::get('/revisions/{revision}', [SiteStructureAdminController::class, 'revision'])
                ->whereNumber('revision')->name('revision');
        });
        Route::put('/', [SiteStructureAdminController::class, 'update'])
            ->middleware((array) config('larena-content.admin.write_middleware', []))->name('update');
        Route::post('/submit-for-review', [SiteStructureAdminController::class, 'submitForReview'])
            ->middleware((array) config('larena-content.admin.submit_middleware', []))->name('submit_review');
        Route::post('/publish', [SiteStructureAdminController::class, 'publish'])
            ->middleware((array) config('larena-content.admin.publish_middleware', []))->name('publish');
        Route::post('/revisions/{revision}/restore', [SiteStructureAdminController::class, 'restore'])
            ->whereNumber('revision')
            ->middleware((array) config('larena-content.admin.restore_middleware', []))->name('restore');
    });

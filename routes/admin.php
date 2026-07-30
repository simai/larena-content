<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Content\Http\Controllers\ContentAdminController;
use Larena\Content\Http\Controllers\SiteStructureAdminController;

Route::prefix((string) config('larena-content.admin.cms_prefix', 'admin/content'))
    ->middleware((array) config('larena-content.admin.middleware', []))
    ->name('larena.content.admin.')
    ->group(static function (): void {
        Route::get('/', [ContentAdminController::class, 'workspace'])
            ->middleware((array) config('larena-content.admin.item_list_middleware', []))->name('workspace');
        Route::prefix('types')->name('types.')->group(static function (): void {
            Route::get('/', [ContentAdminController::class, 'types'])
                ->middleware((array) config('larena-content.admin.type_list_middleware', []))->name('index');
            Route::get('/create', [ContentAdminController::class, 'createType'])
                ->middleware((array) config('larena-content.admin.type_create_middleware', []))->name('create');
            Route::post('/', [ContentAdminController::class, 'storeType'])
                ->middleware((array) config('larena-content.admin.type_create_middleware', []))->name('store');
        });
        Route::prefix('materials')->name('materials.')->group(static function (): void {
            Route::get('/', [ContentAdminController::class, 'materials'])
                ->middleware((array) config('larena-content.admin.item_list_middleware', []))->name('index');
            Route::get('/create', [ContentAdminController::class, 'createMaterial'])
                ->middleware((array) config('larena-content.admin.item_create_middleware', []))->name('create');
            Route::post('/', [ContentAdminController::class, 'storeMaterial'])
                ->middleware((array) config('larena-content.admin.item_create_middleware', []))->name('store');
            Route::get('/{itemRef}', [ContentAdminController::class, 'editMaterial'])
                ->whereUuid('itemRef')->middleware((array) config('larena-content.admin.item_read_middleware', []))->name('edit');
            Route::get('/{itemRef}/preview', [ContentAdminController::class, 'previewMaterial'])
                ->whereUuid('itemRef')->middleware((array) config('larena-content.admin.item_read_middleware', []))->name('preview');
            Route::put('/{itemRef}', [ContentAdminController::class, 'updateMaterial'])
                ->whereUuid('itemRef')->middleware((array) config('larena-content.admin.item_update_middleware', []))->name('update');
            Route::post('/{itemRef}/submit-for-review', [ContentAdminController::class, 'submit'])
                ->whereUuid('itemRef')->middleware((array) config('larena-content.admin.item_submit_middleware', []))->name('submit');
            Route::post('/{itemRef}/publish', [ContentAdminController::class, 'publish'])
                ->whereUuid('itemRef')->middleware((array) config('larena-content.admin.item_publish_middleware', []))->name('publish');
            Route::post('/{itemRef}/unpublish', [ContentAdminController::class, 'unpublish'])
                ->whereUuid('itemRef')->middleware((array) config('larena-content.admin.item_unpublish_middleware', []))->name('unpublish');
            Route::post('/{itemRef}/revisions/{revision}/restore', [ContentAdminController::class, 'restore'])
                ->whereUuid('itemRef')->whereNumber('revision')
                ->middleware((array) config('larena-content.admin.item_restore_middleware', []))->name('restore');
        });
    });

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

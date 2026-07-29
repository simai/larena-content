<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Tests\TestCase;

final class ContentAdminRouteTest extends TestCase
{
    public function testPackageOwnsContentTypeMaterialAndEditorialBrowserRoutes(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/admin.php');
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config/admin.php');

        foreach (['types', 'createType', 'storeType', 'materials', 'createMaterial', 'storeMaterial',
            'editMaterial', 'previewMaterial', 'updateMaterial', 'submit', 'publish', 'unpublish', 'restore'] as $surface) {
            self::assertStringContainsString($surface, $routes);
        }
        foreach (['content.type.list', 'content.type.create', 'content.item.list', 'content.item.read',
            'content.item.create', 'content.item.update', 'content.item.submit_review', 'content.item.publish',
            'content.item.unpublish', 'content.item.restore'] as $operation) {
            self::assertStringContainsString('access:' . $operation, $config);
        }
    }

    public function testMutationFormsAreCsrfProtectedAndPresentationPure(): void
    {
        $root = dirname(__DIR__, 2) . '/resources/views/admin';
        $blades = [
            (string) file_get_contents($root . '/types/create.blade.php'),
            (string) file_get_contents($root . '/materials/form.blade.php'),
        ];

        foreach ($blades as $blade) {
            self::assertStringContainsString('@csrf', $blade);
            self::assertStringNotContainsString('<script', $blade);
            self::assertStringNotContainsString('<style', $blade);
            self::assertStringNotContainsString('@php', $blade);
        }
        self::assertStringContainsString('name="expected_revision"', $blades[1]);
        foreach (['string', 'text', 'number', 'boolean', 'date', 'file', 'relation'] as $type) {
            self::assertStringContainsString("'{$type}'", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/ContentAdminController.php'));
        }
        self::assertStringContainsString(
            "itemRef->uuid()",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/ContentAdminController.php'),
        );
    }

    public function testManagedFileFailureRendersSafeSystemErrorInsteadOfSilentEmptyState(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controllers/ContentAdminController.php');
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/admin/materials/form.blade.php');
        $english = require dirname(__DIR__, 2) . '/resources/lang/en/admin.php';
        $russian = require dirname(__DIR__, 2) . '/resources/lang/ru/admin.php';

        self::assertStringContainsString("['options' => [], 'failed' => true]", $controller);
        self::assertStringContainsString("'fileIntegrationFailed' => \$fileOptions['failed']", $controller);
        self::assertStringContainsString('data-larena-state="system-error"', $view);
        self::assertStringContainsString('materials.filesystem_unavailable', $view);
        self::assertArrayHasKey('filesystem_unavailable', $english['materials']);
        self::assertArrayHasKey('filesystem_unavailable', $russian['materials']);
        self::assertStringNotContainsString('exception', strtolower($english['materials']['filesystem_unavailable']));
        self::assertStringNotContainsString('exception', strtolower($russian['materials']['filesystem_unavailable']));
    }

    public function testPublicPageRendersProjectionDataWithoutPersistedHtml(): void
    {
        $route = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/public.php');
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/public/page.blade.php');

        self::assertStringContainsString("->name('larena.content.public.page')", $route);
        self::assertStringContainsString("\$page['public_fields']", $view);
        self::assertStringContainsString('rel="canonical"', $view);
        self::assertStringContainsString('name="robots"', $view);
        self::assertStringContainsString('aria-label="Site navigation"', $view);
        self::assertStringNotContainsString('{!!', $view);
        self::assertStringNotContainsString('<script', $view);
    }
}

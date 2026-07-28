<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Tests\TestCase;

final class SiteStructureAdminRouteTest extends TestCase
{
    public function testPackageOwnedAdminRoutesSeparateReadEditorAndAdministratorOperations(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/admin.php');
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config/admin.php');

        self::assertStringContainsString("->name('index')", $routes);
        self::assertStringContainsString("->name('update')", $routes);
        self::assertStringContainsString("->name('submit_review')", $routes);
        self::assertStringContainsString("->name('publish')", $routes);
        self::assertStringContainsString("->name('restore')", $routes);
        foreach (['web', 'larena-auth.entry', 'larena-auth.admin-required', 'larena-admin.locale',
            'access:content.structure.read', 'access:content.structure.update',
            'access:content.structure.submit_review', 'access:content.structure.publish',
            'access:content.structure.restore'] as $middleware) {
            self::assertStringContainsString($middleware, $config);
        }
    }

    public function testBladeMutationFormsCarryCsrfAndExpectedRevisionWithoutInlineRuntime(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/admin/site-structure.blade.php');

        self::assertStringContainsString('@csrf', $blade);
        self::assertStringContainsString('name="expected_revision"', $blade);
        self::assertStringNotContainsString('<script', $blade);
        self::assertStringNotContainsString('@php', $blade);
        self::assertStringContainsString('SfActionLink::render', $blade);
    }
}

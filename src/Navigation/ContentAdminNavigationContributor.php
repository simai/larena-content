<?php

declare(strict_types=1);

namespace Larena\Content\Navigation;

use Larena\Admin\Contracts\AdminNavigationContributor;
use Larena\Admin\Navigation\AdminNavigationDescriptor;

final class ContentAdminNavigationContributor implements AdminNavigationContributor
{
    public function ownerPackage(): string
    {
        return 'larena/content';
    }

    public function navigationDescriptors(): array
    {
        return [new AdminNavigationDescriptor(
            id: 'content.site_structure',
            ownerPackage: $this->ownerPackage(),
            label: 'Site structure',
            routeName: 'larena.content.admin.structure.index',
            routeUri: '/admin/content/site-structure',
            category: 'content',
            state: 'developer_slice',
            accessScope: 'content.structure.read',
            auditEvent: 'content.structure.index_viewed',
            statusCap: 'site_structure_http_v1',
            order: 30,
            group: 'content',
            badge: null,
            knownLimitations: ['local_testing_only', 'not_production_ready', 'frontend_not_complete'],
            surface: 'product',
            labelKey: 'larena-content::admin.navigation.site_structure',
            activeRoutePattern: 'larena.content.admin.structure.*',
        )];
    }
}

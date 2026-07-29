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
            id: 'content.materials',
            ownerPackage: $this->ownerPackage(),
            label: 'Materials',
            routeName: 'larena.content.admin.materials.index',
            routeUri: '/admin/content/materials',
            category: 'content',
            state: 'operator_slice',
            accessScope: 'content.item.list',
            auditEvent: 'content.item.list_viewed',
            statusCap: 'content_material_admin',
            order: 10,
            group: 'content',
            knownLimitations: ['not_production_ready', 'frontend_not_complete'],
            surface: 'product',
            labelKey: 'larena-content::admin.navigation.materials',
            activeRoutePattern: 'larena.content.admin.materials.*',
        ), new AdminNavigationDescriptor(
            id: 'content.types',
            ownerPackage: $this->ownerPackage(),
            label: 'Content types',
            routeName: 'larena.content.admin.types.index',
            routeUri: '/admin/content/types',
            category: 'content',
            state: 'operator_slice',
            accessScope: 'content.type.list',
            auditEvent: 'content.type.list_viewed',
            statusCap: 'content_type_admin',
            order: 20,
            group: 'content',
            knownLimitations: ['not_production_ready', 'frontend_not_complete'],
            surface: 'product',
            labelKey: 'larena-content::admin.navigation.types',
            activeRoutePattern: 'larena.content.admin.types.*',
        ), new AdminNavigationDescriptor(
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

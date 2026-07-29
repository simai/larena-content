<?php

declare(strict_types=1);

return [
    'enabled' => filter_var(getenv('LARENA_CONTENT_ADMIN_ROUTES') ?: false, FILTER_VALIDATE_BOOL),
    'allowed_environments' => ['local', 'testing'],
    'prefix' => 'admin/content/site-structure',
    'cms_prefix' => 'admin/content',
    'middleware' => [
        'web',
        'larena-auth.entry',
        'larena-auth.admin-required',
        'larena-admin.locale',
    ],
    'read_middleware' => ['access:content.structure.read'],
    'write_middleware' => ['access:content.structure.update'],
    'submit_middleware' => ['access:content.structure.submit_review'],
    'publish_middleware' => ['access:content.structure.publish'],
    'restore_middleware' => ['access:content.structure.restore'],
    'type_list_middleware' => ['access:content.type.list'],
    'type_create_middleware' => ['access:content.type.create'],
    'item_list_middleware' => ['access:content.item.list'],
    'item_read_middleware' => ['access:content.item.read'],
    'item_create_middleware' => ['access:content.item.create'],
    'item_update_middleware' => ['access:content.item.update'],
    'item_submit_middleware' => ['access:content.item.submit_review'],
    'item_publish_middleware' => ['access:content.item.publish'],
    'item_unpublish_middleware' => ['access:content.item.unpublish'],
    'item_restore_middleware' => ['access:content.item.restore'],
];

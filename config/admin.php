<?php

declare(strict_types=1);

return [
    'enabled' => filter_var(getenv('LARENA_CONTENT_ADMIN_ROUTES') ?: false, FILTER_VALIDATE_BOOL),
    'allowed_environments' => ['local', 'testing'],
    'prefix' => 'admin/content/site-structure',
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
];

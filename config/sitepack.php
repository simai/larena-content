<?php

declare(strict_types=1);

return [
    'root' => function_exists('storage_path')
        ? storage_path('app/private/larena/sitepacks')
        : sys_get_temp_dir().'/larena-content-sitepacks',
    'maximum_bytes' => 256 * 1024 * 1024,
    'maximum_entries' => 5000,
];

<?php

declare(strict_types=1);

return [
    'database' => storage_path('app/geolookup/locations.sqlite'),
    'http' => [
        'enabled' => false,
        'prefix' => 'api/geolookup/v1',
        'middleware' => ['api'],
    ],
];

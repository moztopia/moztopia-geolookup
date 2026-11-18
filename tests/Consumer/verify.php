<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Moztopia\GeoLookup\GeoLookupServiceProvider;

$consumer = $argv[1] ?? dirname(__DIR__, 2).'/build/consumer';
require $consumer.'/vendor/autoload.php';
$app = require $consumer.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->getProvider(GeoLookupServiceProvider::class) === null) {
    throw new RuntimeException('Composer package discovery did not register GeoLookup.');
}

if ($app['config']->get('geolookup.http.enabled') !== false) {
    throw new RuntimeException('Package discovery did not merge safe configuration defaults.');
}

if ($app['config']->get('geolookup.database') !== $app->storagePath('app/geolookup/locations.sqlite')) {
    throw new RuntimeException('Dataset storage path is not relative to the consuming application.');
}

fwrite(STDOUT, 'GeoLookup discovered successfully in Laravel '.$app->version().PHP_EOL);

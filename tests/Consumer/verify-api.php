<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$consumer = $argv[1] ?? dirname(__DIR__, 2).'/build/consumer';
require $consumer.'/vendor/autoload.php';
$app = require $consumer.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$paths = [
    '/search?q=Thaiburi%2C%20Thailand&types=subdistrict',
    '/search?q=Bethel%20Acres%2C%20OK',
    '/places/00000000-0000-4000-8000-000000000004/ancestors',
    '/reverse?latitude=8.5&longitude=99.5&stop_at=village',
    '/openapi.yaml',
];
foreach ($paths as $path) {
    $request = Request::create('/api/geolookup/v1'.$path);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Consumer HTTP lookup failed for '.$path.': '.$response->getContent());
    }
    if ($path !== '/openapi.yaml') {
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        if (!isset($payload['data'][0])) {
            throw new RuntimeException('Expected the explicit test fixture result for '.$path);
        }
    }
    $kernel->terminate($request, $response);
}

fwrite(STDOUT, 'GeoLookup HTTP lookups passed in Laravel '.$app->version().PHP_EOL);

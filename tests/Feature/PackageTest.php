<?php

declare(strict_types=1);

use Moztopia\GeoLookup\GeoLookupServiceProvider;

it('boots in a consuming Laravel application without exposing routes or touching data', function (): void {
    expect($this->app->getProvider(GeoLookupServiceProvider::class))->not->toBeNull()
        ->and(config('geolookup.http.enabled'))->toBeFalse()
        ->and(config('geolookup.database'))->toBe(storage_path('app/geolookup/locations.sqlite'))
        ->and(file_exists(config('geolookup.database')))->toBeFalse();
});

it('publishes configuration through its own publication tag', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'geolookup-config', '--force' => true])->assertSuccessful();

    $path = config_path('geolookup.php');
    expect(is_file($path))->toBeTrue();
    unlink($path);
});

it('preserves consumer settings across application boots', function (): void {
    $this->app['config']->set('geolookup.http.prefix', 'internal/locations');
    $this->app->register(GeoLookupServiceProvider::class, true);

    expect(config('geolookup.http.prefix'))->toBe('internal/locations')
        ->and(config('geolookup.http.enabled'))->toBeFalse();
});

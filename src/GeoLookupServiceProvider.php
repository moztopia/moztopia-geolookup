<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup;

use Illuminate\Support\ServiceProvider;
use Moztopia\GeoLookup\Data\SqliteDataset;
use Moztopia\GeoLookup\Services\BoundaryMatcher;

/**
 * Register GeoLookup resources in the consuming Laravel application.
 */

final class GeoLookupServiceProvider extends ServiceProvider
{
    /**
     * Merge package defaults without overriding host configuration.
     */

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/geolookup.php', 'geolookup');
        $this->app->singleton(SqliteDataset::class, fn ($app): SqliteDataset => new SqliteDataset((string) $app['config']->get('geolookup.database')));
        $this->app->singleton(GeoLookup::class, fn ($app): GeoLookup => new GeoLookup($app->make(SqliteDataset::class), new BoundaryMatcher()));
    }

    /**
     * Expose configuration publication to the host's console.
     */

    public function boot(): void
    {
        if (config('geolookup.http.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/geolookup.php' => config_path('geolookup.php'),
            ], 'geolookup-config');
            $this->publishes([__DIR__.'/../openapi.yaml' => base_path('openapi/geolookup.yaml')], 'geolookup-openapi');
        }
    }
}

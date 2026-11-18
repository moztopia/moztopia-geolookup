<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup;

use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Expose configuration publication to the host's console.
     */

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/geolookup.php' => config_path('geolookup.php'),
            ], 'geolookup-config');
        }
    }
}

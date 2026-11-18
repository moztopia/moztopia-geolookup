<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup\Tests;

use Moztopia\GeoLookup\GeoLookupServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Boot an isolated consuming application for package integration tests.
 */

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Register the provider as Laravel package discovery would.
     *
     * @return list<class-string>
     */

    protected function getPackageProviders($app): array
    {
        return [GeoLookupServiceProvider::class];
    }
}

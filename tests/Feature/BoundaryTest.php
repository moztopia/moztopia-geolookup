<?php

declare(strict_types=1);

use Moztopia\GeoLookup\Services\BoundaryMatcher;
use Moztopia\GeoLookup\Tests\Fixtures\Locations;

it('includes exterior edges and excludes holes', function (): void {
    $geometry = Locations::box(0, 0, 10, 10);
    $geometry['coordinates'][] = Locations::box(2, 2, 4, 4)['coordinates'][0];
    $matcher = new BoundaryMatcher();
    expect($matcher->contains($geometry, 1, 1))->toBeTrue()
        ->and($matcher->contains($geometry, 0, 1))->toBeTrue()
        ->and($matcher->contains($geometry, 3, 3))->toBeFalse()
        ->and($matcher->contains($geometry, 2, 3))->toBeFalse()
        ->and($matcher->contains($geometry, 11, 1))->toBeFalse();
});

it('handles disjoint multipolygons and antimeridian crossings', function (): void {
    $geometry = ['type' => 'MultiPolygon', 'coordinates' => [
        Locations::box(0, 0, 1, 1)['coordinates'],
        Locations::box(170, -10, -170, 10)['coordinates'],
    ]];
    $matcher = new BoundaryMatcher();
    expect($matcher->contains($geometry, 0.5, 0.5))->toBeTrue()
        ->and($matcher->contains($geometry, 0, 179))->toBeTrue()
        ->and($matcher->contains($geometry, 0, -179))->toBeTrue()
        ->and($matcher->contains($geometry, 0, 180))->toBeTrue()
        ->and($matcher->contains($geometry, 0, 90))->toBeFalse();
});

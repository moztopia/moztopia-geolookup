<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Moztopia\GeoLookup\Http\GeoLookupController;

Route::prefix(config('geolookup.http.prefix', 'api/geolookup/v1'))
    ->middleware(config('geolookup.http.middleware', ['api']))
    ->name('geolookup.')
    ->group(function (): void {
        Route::get('search', [GeoLookupController::class, 'search'])->name('search');
        Route::get('reverse', [GeoLookupController::class, 'reverse'])->name('reverse');
        Route::get('places/{id}', [GeoLookupController::class, 'place'])->name('place');
        Route::get('places/{id}/children', [GeoLookupController::class, 'children'])->name('children');
        Route::get('places/{id}/ancestors', [GeoLookupController::class, 'ancestors'])->name('ancestors');
        Route::get('openapi.yaml', [GeoLookupController::class, 'openapi'])->name('openapi');
    });

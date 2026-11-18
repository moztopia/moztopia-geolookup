<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prove that the consuming application retains access-control ownership.
 */

final class HostMiddleware
{
    /**
     * Reject anonymous test requests before the package controller runs.
     */

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Host-Token') !== 'fixture-token') {
            return new Response('Host access policy', 403);
        }

        return $next($request);
    }
}

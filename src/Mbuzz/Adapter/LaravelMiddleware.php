<?php

declare(strict_types=1);

namespace Mbuzz\Adapter;

use Closure;
use Mbuzz\Mbuzz;

/**
 * Laravel HTTP middleware for Mbuzz tracking.
 *
 * Register in app/Http/Kernel.php:
 *
 *   protected $middleware = [
 *       // ...
 *       \Mbuzz\Adapter\LaravelMiddleware::class,
 *   ];
 *
 * Initialise the SDK once at boot (typically a service provider):
 *
 *   Mbuzz::init(['api_key' => config('mbuzz.api_key')]);
 *
 * The middleware is duck-typed against Laravel's contract — it never
 * imports an Illuminate class so the SDK stays framework-agnostic.
 */
final class LaravelMiddleware
{
    /**
     * @param mixed $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            if (Mbuzz::getClient() !== null) {
                Mbuzz::initFromRequest();
            }
        } catch (\Throwable $e) {
            // Never let tracking interfere with the request pipeline.
            error_log('[Mbuzz] LaravelMiddleware error: ' . $e->getMessage());
        }

        return $next($request);
    }
}

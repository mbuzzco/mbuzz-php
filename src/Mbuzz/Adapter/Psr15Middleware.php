<?php

declare(strict_types=1);

namespace Mbuzz\Adapter;

use Mbuzz\Mbuzz;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware for Mbuzz tracking.
 *
 * Works with Slim, Mezzio, and any other PSR-15 compliant framework.
 *
 *   use Mbuzz\Adapter\Psr15Middleware;
 *
 *   Mbuzz::init(['api_key' => $_ENV['MBUZZ_API_KEY']]);
 *   $app->add(new Psr15Middleware());
 *
 * Requires psr/http-server-middleware in your composer.json. The Mbuzz
 * package itself declares it as a `suggest` so non-PSR-15 users don't
 * pull it in.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            if (Mbuzz::getClient() !== null) {
                Mbuzz::initFromRequest();
            }
        } catch (\Throwable $e) {
            error_log('[Mbuzz] Psr15Middleware error: ' . $e->getMessage());
        }

        return $handler->handle($request);
    }
}

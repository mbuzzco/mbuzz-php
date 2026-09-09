<?php

declare(strict_types=1);

namespace Mbuzz;

/**
 * The session endpoint's response, in whichever shape the host framework wants.
 *
 * Always the same thing: no body, status 204, and never cacheable. The
 * Set-Cookie itself is written by CookieManager during Client::initFromRequest()
 * — via setcookie(), which every one of these frameworks emits alongside its own
 * headers — so this class only carries the status and the cache directive.
 *
 * Each factory is duck-typed and guarded by class_exists(), so the SDK keeps no
 * hard dependency on Laravel, PSR-7 or Symfony.
 */
final class SessionResponse
{
    /**
     * Laravel / generic: an Illuminate response where available, otherwise a
     * plain object the framework can still send.
     *
     * @return mixed
     */
    public static function make()
    {
        if (class_exists('\\Illuminate\\Http\\Response')) {
            /** @psalm-suppress UndefinedClass */
            return new \Illuminate\Http\Response('', SessionEndpoint::NO_CONTENT_STATUS, self::headers());
        }

        if (class_exists('\\Symfony\\Component\\HttpFoundation\\Response')) {
            return self::symfony();
        }

        // No framework response class available: the headers are already on
        // the wire from Client, so there is nothing further to build.
        return null;
    }

    /**
     * Symfony: an HttpFoundation response.
     *
     * @return mixed
     */
    public static function symfony()
    {
        /** @psalm-suppress UndefinedClass */
        return new \Symfony\Component\HttpFoundation\Response(
            '',
            SessionEndpoint::NO_CONTENT_STATUS,
            self::headers()
        );
    }

    /**
     * PSR-7: built from the incoming request's own factory where one is
     * discoverable, so we do not depend on a particular PSR-17 implementation.
     *
     * @param mixed $request
     * @return mixed
     */
    public static function psr7($request = null)
    {
        $response = self::psr7Response();

        foreach (self::headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withStatus(SessionEndpoint::NO_CONTENT_STATUS);
    }

    /**
     * @return mixed
     */
    private static function psr7Response()
    {
        if (class_exists('\\Nyholm\\Psr7\\Response')) {
            /** @psalm-suppress UndefinedClass */
            return new \Nyholm\Psr7\Response();
        }

        if (class_exists('\\GuzzleHttp\\Psr7\\Response')) {
            /** @psalm-suppress UndefinedClass */
            return new \GuzzleHttp\Psr7\Response();
        }

        if (class_exists('\\Laminas\\Diactoros\\Response')) {
            /** @psalm-suppress UndefinedClass */
            return new \Laminas\Diactoros\Response();
        }

        throw new \RuntimeException(
            'Mbuzz: no PSR-7 response implementation found. Install nyholm/psr7, '
            . 'guzzlehttp/psr7 or laminas/laminas-diactoros.'
        );
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return ['Cache-Control' => SessionEndpoint::NO_STORE];
    }
}

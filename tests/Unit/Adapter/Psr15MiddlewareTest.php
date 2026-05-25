<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit\Adapter;

use Mbuzz\Adapter\Psr15Middleware;
use Mbuzz\Mbuzz;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Psr15MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Mbuzz::reset();

        $_SERVER['REQUEST_URI'] = '/test-page';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
    }

    protected function tearDown(): void
    {
        Mbuzz::reset();
        unset(
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['HTTP_SEC_FETCH_MODE'],
            $_SERVER['HTTP_SEC_FETCH_DEST'],
            $_COOKIE['_mbuzz_vid'],
        );
    }

    public function testProcessForwardsToHandlerAndReturnsItsResponse(): void
    {
        $this->initMbuzz();

        $middleware = new Psr15Middleware();
        $request = new ServerRequest('GET', 'https://example.com/test-page');
        $expectedResponse = (new Psr17Factory())->createResponse(200);

        $handler = new class($expectedResponse) implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;
            public function __construct(private ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;
                return $this->response;
            }
        };

        $actual = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $actual);
        $this->assertSame($request, $handler->seen);
    }

    public function testProcessInitializesContextFromVisitorCookie(): void
    {
        $existing = str_repeat('b', 64);
        $_COOKIE['_mbuzz_vid'] = $existing;

        $this->initMbuzz();

        $middleware = new Psr15Middleware();
        $request = new ServerRequest('GET', 'https://example.com/test-page');

        $middleware->process($request, $this->passthroughHandler());

        $this->assertSame($existing, Mbuzz::visitorId());
    }

    public function testProcessSurvivesWhenSdkUninitialized(): void
    {
        // Do not call Mbuzz::init() — simulate a project that wired the
        // middleware but forgot to boot the SDK.
        $middleware = new Psr15Middleware();
        $request = new ServerRequest('GET', 'https://example.com/test-page');

        $response = $middleware->process($request, $this->passthroughHandler());

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testProcessSkipsTrackingWhenDisabled(): void
    {
        Mbuzz::init([
            'api_key' => 'sk_test_psr15',
            'enabled' => false,
        ]);

        $middleware = new Psr15Middleware();
        $request = new ServerRequest('GET', 'https://example.com/test-page');

        $middleware->process($request, $this->passthroughHandler());

        $this->assertNull(Mbuzz::visitorId());
    }

    private function initMbuzz(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_psr15']);
        Mbuzz::getClient()->setTransport(fn () => ['status' => 202, 'body' => null]);
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        $response = (new Psr17Factory())->createResponse(200);
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}

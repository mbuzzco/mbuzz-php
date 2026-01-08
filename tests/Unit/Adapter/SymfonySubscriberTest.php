<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit\Adapter;

use Mbuzz\Adapter\SymfonySubscriber;
use Mbuzz\Config;
use Mbuzz\Context;
use Mbuzz\Mbuzz;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SymfonySubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        Mbuzz::reset();

        // Set up a mock transport to avoid real HTTP calls
        Mbuzz::init([
            'api_key' => 'sk_test_symfony_test',
            'api_url' => 'http://localhost:3000/api/v1',
        ]);

        // Mock the transport
        $client = Mbuzz::getClient();
        $client->setTransport(function () {
            return ['status' => 200, 'body' => ['success' => true]];
        });
    }

    protected function tearDown(): void
    {
        Mbuzz::reset();
    }

    public function testGetSubscribedEventsReturnsKernelRequest(): void
    {
        $events = SymfonySubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertEquals(['onKernelRequest', 256], $events[KernelEvents::REQUEST]);
    }

    public function testOnKernelRequestInitializesTracking(): void
    {
        $subscriber = new SymfonySubscriber();
        $event = $this->createRequestEvent('/test-page');

        $subscriber->onKernelRequest($event);

        // Context should be initialized (visitor only - server handles sessions)
        $this->assertNotNull(Mbuzz::visitorId());
    }

    public function testOnKernelRequestIgnoresSubRequests(): void
    {
        $subscriber = new SymfonySubscriber();
        $event = $this->createRequestEvent('/test-page', HttpKernelInterface::SUB_REQUEST);

        // Reset context to verify it's not initialized
        Context::reset();

        $subscriber->onKernelRequest($event);

        // Context should NOT be initialized for sub-requests
        $this->assertNull(Mbuzz::visitorId());
    }

    public function testOnKernelRequestSkipsHealthEndpoints(): void
    {
        $subscriber = new SymfonySubscriber();

        // Reset and test with health endpoint
        Context::reset();
        $event = $this->createRequestEvent('/health');
        $subscriber->onKernelRequest($event);

        // Health endpoint should be skipped
        $this->assertNull(Mbuzz::visitorId());
    }

    public function testOnKernelRequestSkipsStaticAssets(): void
    {
        $subscriber = new SymfonySubscriber();

        // Test various static assets
        $staticPaths = ['/assets/app.js', '/css/style.css', '/images/logo.png'];

        foreach ($staticPaths as $path) {
            Context::reset();
            $event = $this->createRequestEvent($path);
            $subscriber->onKernelRequest($event);

            $this->assertNull(
                Mbuzz::visitorId(),
                "Static asset {$path} should be skipped"
            );
        }
    }

    public function testOnKernelRequestReadsVisitorCookie(): void
    {
        $existingVisitorId = str_repeat('a', 64);

        // Must set $_COOKIE BEFORE Mbuzz::init() since CookieManager reads at construction
        $_COOKIE['_mbuzz_vid'] = $existingVisitorId;

        // Re-initialize Mbuzz so it picks up the cookies
        Mbuzz::reset();
        Mbuzz::init([
            'api_key' => 'sk_test_symfony_test',
            'api_url' => 'http://localhost:3000/api/v1',
        ]);
        $client = Mbuzz::getClient();
        $client->setTransport(function () {
            return ['status' => 200, 'body' => ['success' => true]];
        });

        $subscriber = new SymfonySubscriber();
        $request = Request::create('/test-page');
        $event = $this->createRequestEventWithRequest($request);
        $subscriber->onKernelRequest($event);

        $this->assertEquals($existingVisitorId, Mbuzz::visitorId());
    }

    public function testSubscriberCanBeDisabled(): void
    {
        // Reset and init with disabled
        Mbuzz::reset();
        Mbuzz::init([
            'api_key' => 'sk_test_disabled',
            'enabled' => false,
        ]);

        $subscriber = new SymfonySubscriber();
        $event = $this->createRequestEvent('/test-page');

        $subscriber->onKernelRequest($event);

        // Should not initialize when disabled
        $this->assertNull(Mbuzz::visitorId());
    }

    private function createRequestEvent(
        string $path,
        int $requestType = HttpKernelInterface::MAIN_REQUEST
    ): RequestEvent {
        $request = Request::create($path);
        return $this->createRequestEventWithRequest($request, $requestType);
    }

    private function createRequestEventWithRequest(
        Request $request,
        int $requestType = HttpKernelInterface::MAIN_REQUEST
    ): RequestEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, $requestType);
    }
}

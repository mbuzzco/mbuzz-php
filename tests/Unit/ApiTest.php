<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Api;
use Mbuzz\Config;
use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testPostReturnsFalseWhenNotEnabled(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $api = new Api($config);
        $result = $api->post('/events', ['test' => true]);

        $this->assertFalse($result);
    }

    public function testGetReturnsFalseWhenNotEnabled(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $api = new Api($config);
        $result = $api->get('/validate');

        $this->assertNull($result);
    }

    public function testPostWithResponseReturnsNullWhenNotEnabled(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $api = new Api($config);
        $result = $api->postWithResponse('/events', ['test' => true]);

        $this->assertNull($result);
    }

    public function testBuildUrlCombinesApiUrlAndPath(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
        ]);

        $api = new Api($config);

        // We can test this through the mock transport
        $requests = [];
        $api->setTransport(function($method, $url, $payload, $headers) use (&$requests) {
            $requests[] = ['method' => $method, 'url' => $url];
            return ['status' => 200, 'body' => []];
        });

        $api->post('/events', []);

        $this->assertEquals('https://api.mbuzz.co/api/v1/events', $requests[0]['url']);
    }

    public function testPathWithLeadingSlashIsHandled(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
        ]);

        $api = new Api($config);

        $requests = [];
        $api->setTransport(function($method, $url, $payload, $headers) use (&$requests) {
            $requests[] = $url;
            return ['status' => 200, 'body' => []];
        });

        $api->post('/events', []);

        $this->assertStringNotContainsString('//events', $requests[0]);
    }

    public function testAuthorizationHeaderIsSet(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_secret123']);

        $api = new Api($config);

        $capturedHeaders = [];
        $api->setTransport(function($method, $url, $payload, $headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return ['status' => 200, 'body' => []];
        });

        $api->post('/events', []);

        $this->assertContains('Authorization: Bearer sk_test_secret123', $capturedHeaders);
    }

    public function testContentTypeHeaderIsSet(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);

        $capturedHeaders = [];
        $api->setTransport(function($method, $url, $payload, $headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return ['status' => 200, 'body' => []];
        });

        $api->post('/events', []);

        $this->assertContains('Content-Type: application/json', $capturedHeaders);
    }

    public function testUserAgentHeaderIsSet(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);

        $capturedHeaders = [];
        $api->setTransport(function($method, $url, $payload, $headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return ['status' => 200, 'body' => []];
        });

        $api->post('/events', []);

        $hasUserAgent = false;
        foreach ($capturedHeaders as $header) {
            if (str_starts_with($header, 'User-Agent: mbuzz-php/')) {
                $hasUserAgent = true;
                break;
            }
        }
        $this->assertTrue($hasUserAgent, 'User-Agent header should be present');
    }

    public function testPostReturnsTrueOnSuccess(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(function() {
            return ['status' => 202, 'body' => ['accepted' => 1]];
        });

        $result = $api->post('/events', ['events' => []]);

        $this->assertTrue($result);
    }

    public function testPostReturnsFalseOnError(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(function() {
            return ['status' => 401, 'body' => ['error' => 'Unauthorized']];
        });

        $result = $api->post('/events', ['events' => []]);

        $this->assertFalse($result);
    }

    public function testPostWithResponseReturnsBodyOnSuccess(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(function() {
            return ['status' => 200, 'body' => ['success' => true, 'id' => 'evt_123']];
        });

        $result = $api->postWithResponse('/events', []);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('evt_123', $result['id']);
    }

    public function testPostWithResponseReturnsNullOnError(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(function() {
            return ['status' => 500, 'body' => null];
        });

        $result = $api->postWithResponse('/events', []);

        $this->assertNull($result);
    }

    public function testGetReturnsBodyOnSuccess(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(function() {
            return ['status' => 200, 'body' => ['valid' => true]];
        });

        $result = $api->get('/validate');

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function testHandlesTransportException(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(function() {
            throw new \RuntimeException('Connection failed');
        });

        $result = $api->post('/events', []);

        $this->assertFalse($result);
    }

    public function testPayloadIsSentAsJson(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;
            return ['status' => 200, 'body' => []];
        });

        $api->post('/events', ['events' => [['type' => 'page_view']]]);

        $this->assertIsString($capturedPayload);
        $decoded = json_decode($capturedPayload, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('events', $decoded);
    }

    public function testPostPassesPerCallTimeoutToTransport(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123', 'timeout' => 10]);

        $api = new Api($config);

        $capturedTimeout = null;
        $api->setTransport(function($method, $url, $payload, $headers, $timeout) use (&$capturedTimeout) {
            $capturedTimeout = $timeout;
            return ['status' => 202, 'body' => null];
        });

        $api->post('/sessions', [], 2);

        $this->assertSame(2, $capturedTimeout);
    }

    public function testPostFallsBackToConfigTimeoutWhenNotSpecified(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123', 'timeout' => 7]);

        $api = new Api($config);

        $capturedTimeout = null;
        $api->setTransport(function($method, $url, $payload, $headers, $timeout) use (&$capturedTimeout) {
            $capturedTimeout = $timeout;
            return ['status' => 202, 'body' => null];
        });

        $api->post('/events', []);

        // No explicit per-call timeout → transport sees null → curl falls back to config default
        $this->assertNull($capturedTimeout);
    }

    public function testPostDefersWhenNoTransportIsSet(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);

        // Queue with no transport — nothing should be sent yet
        $result = $api->post('/sessions', ['visitor_id' => 'abc']);

        $this->assertTrue($result, 'queued post returns true optimistically');

        $calls = [];
        $api->setTransport(function($method, $url, $payload) use (&$calls) {
            $calls[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
            return ['status' => 202, 'body' => null];
        });

        $this->assertCount(0, $calls, 'transport must not run before flushDeferred');

        $api->flushDeferred();

        $this->assertCount(1, $calls);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://api.mbuzz.co/api/v1/sessions', $calls[0]['url']);
    }

    public function testFlushDeferredIsIdempotent(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->post('/sessions', ['x' => 1]);

        $count = 0;
        $api->setTransport(function() use (&$count) {
            $count++;
            return ['status' => 202, 'body' => null];
        });

        $api->flushDeferred();
        $api->flushDeferred();

        $this->assertSame(1, $count);
    }

    public function testFlushDeferredPreservesPerCallTimeouts(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123', 'timeout' => 10]);

        $api = new Api($config);
        $api->post('/sessions', ['a' => 1], 2);
        $api->post('/sessions', ['b' => 2], 3);

        $timeouts = [];
        $api->setTransport(function($method, $url, $payload, $headers, $timeout) use (&$timeouts) {
            $timeouts[] = $timeout;
            return ['status' => 202, 'body' => null];
        });

        $api->flushDeferred();

        $this->assertSame([2, 3], $timeouts);
    }

    public function testTransportPathDoesNotDefer(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);

        $callCount = 0;
        $api->setTransport(function() use (&$callCount) {
            $callCount++;
            return ['status' => 202, 'body' => null];
        });

        $api->post('/sessions', ['x' => 1]);

        // Synchronous because transport is set — no flushDeferred required.
        $this->assertSame(1, $callCount);
    }
}

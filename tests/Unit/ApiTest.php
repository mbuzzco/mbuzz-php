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

    public function testOnSuccessFiresOn2xx(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 202, 'body' => ['accepted' => 1]]);

        $calls = [];
        $api->onSuccess(function ($method, $url, $status, $body) use (&$calls) {
            $calls[] = compact('method', 'url', 'status', 'body');
        });

        $api->post('/events', ['x' => 1]);

        $this->assertCount(1, $calls);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame(202, $calls[0]['status']);
        $this->assertSame(['accepted' => 1], $calls[0]['body']);
        $this->assertStringEndsWith('/events', $calls[0]['url']);
    }

    public function testOnErrorFiresOnNon2xx(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 401, 'body' => ['error' => 'unauthorized']]);

        $calls = [];
        $api->onError(function ($method, $url, $status, $body, $exception) use (&$calls) {
            $calls[] = compact('method', 'url', 'status', 'body', 'exception');
        });

        $api->post('/events', []);

        $this->assertCount(1, $calls);
        $this->assertSame(401, $calls[0]['status']);
        $this->assertSame(['error' => 'unauthorized'], $calls[0]['body']);
        $this->assertNull($calls[0]['exception']);
    }

    public function testOnErrorFiresWithExceptionOnTransportFailure(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(function () {
            throw new \RuntimeException('connection refused');
        });

        $captured = null;
        $api->onError(function ($method, $url, $status, $body, $exception) use (&$captured) {
            $captured = ['status' => $status, 'body' => $body, 'exception' => $exception];
        });

        $api->post('/events', []);

        $this->assertNotNull($captured);
        $this->assertSame(0, $captured['status']);
        $this->assertNull($captured['body']);
        $this->assertInstanceOf(\RuntimeException::class, $captured['exception']);
        $this->assertSame('connection refused', $captured['exception']->getMessage());
    }

    public function testOnSuccessDoesNotFireOnError(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 500, 'body' => null]);

        $successCalls = 0;
        $api->onSuccess(function () use (&$successCalls) {
            $successCalls++;
        });

        $api->post('/events', []);

        $this->assertSame(0, $successCalls);
    }

    public function testMultipleListenersFireInRegistrationOrder(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 200, 'body' => []]);

        $order = [];
        $api->onSuccess(function () use (&$order) { $order[] = 'first'; });
        $api->onSuccess(function () use (&$order) { $order[] = 'second'; });
        $api->onSuccess(function () use (&$order) { $order[] = 'third'; });

        $api->post('/events', []);

        $this->assertSame(['first', 'second', 'third'], $order);
    }

    public function testThrowingListenerDoesNotPreventOthers(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 200, 'body' => []]);

        $secondFired = false;
        $api->onSuccess(function () {
            throw new \RuntimeException('listener boom');
        });
        $api->onSuccess(function () use (&$secondFired) {
            $secondFired = true;
        });

        // Should not throw out of post().
        $result = $api->post('/events', []);

        $this->assertTrue($result);
        $this->assertTrue($secondFired);
    }

    public function testListenersFireForDeferredPostsDuringFlush(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $api = new Api($config);

        // Queue first (no transport, so it's deferred).
        $api->post('/sessions', ['a' => 1]);

        // Now wire the transport AND a listener — neither should fire yet.
        $api->setTransport(fn() => ['status' => 202, 'body' => ['ok' => true]]);
        $successCalls = [];
        $api->onSuccess(function ($method, $url, $status, $body) use (&$successCalls) {
            $successCalls[] = ['status' => $status, 'body' => $body];
        });

        $this->assertCount(0, $successCalls, 'listener must not fire before flushDeferred');

        $api->flushDeferred();

        $this->assertCount(1, $successCalls);
        $this->assertSame(202, $successCalls[0]['status']);
    }

    public function testProbeValidateReturnsBodyOn2xx(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_placeholder']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 200, 'body' => ['valid' => true, 'workspace_id' => 'ws_42']]);

        $result = $api->probeValidate('sk_live_candidate');

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('ws_42', $result['workspace_id']);
    }

    public function testProbeValidateUsesProvidedKeyNotConfigured(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_configured']);

        $api = new Api($config);

        $capturedHeaders = [];
        $api->setTransport(function ($method, $url, $payload, $headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return ['status' => 200, 'body' => []];
        });

        $api->probeValidate('sk_live_candidate');

        $this->assertContains('Authorization: Bearer sk_live_candidate', $capturedHeaders);
        $this->assertNotContains('Authorization: Bearer sk_test_configured', $capturedHeaders);
    }

    public function testProbeValidateHitsValidateEndpoint(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_configured']);

        $api = new Api($config);

        $capturedUrl = null;
        $capturedMethod = null;
        $api->setTransport(function ($method, $url) use (&$capturedUrl, &$capturedMethod) {
            $capturedMethod = $method;
            $capturedUrl = $url;
            return ['status' => 200, 'body' => []];
        });

        $api->probeValidate('sk_test_abc');

        $this->assertSame('GET', $capturedMethod);
        $this->assertSame('https://api.mbuzz.co/api/v1/validate', $capturedUrl);
    }

    public function testProbeValidateReturnsFalseOnNon2xx(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_configured']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 401, 'body' => ['error' => 'invalid_key']]);

        $result = $api->probeValidate('sk_live_bad');

        $this->assertFalse($result);
    }

    public function testProbeValidateReturnsFalseOnTransportException(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_configured']);

        $api = new Api($config);
        $api->setTransport(function () {
            throw new \RuntimeException('dns failure');
        });

        $result = $api->probeValidate('sk_live_x');

        $this->assertFalse($result);
    }

    public function testProbeValidateBypassesEnabledFlag(): void
    {
        // The plugin's settings page validates keys even when tracking is disabled.
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_configured', 'enabled' => false]);

        $api = new Api($config);

        $calls = 0;
        $api->setTransport(function () use (&$calls) {
            $calls++;
            return ['status' => 200, 'body' => ['valid' => true]];
        });

        $result = $api->probeValidate('sk_test_candidate');

        $this->assertSame(1, $calls, 'transport should fire even when isEnabled() is false');
        $this->assertSame(['valid' => true], $result);
    }

    public function testProbeValidateFiresOnSuccessListener(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_configured']);

        $api = new Api($config);
        $api->setTransport(fn() => ['status' => 200, 'body' => ['valid' => true]]);

        $captured = null;
        $api->onSuccess(function ($method, $url, $status, $body) use (&$captured) {
            $captured = ['method' => $method, 'status' => $status, 'body' => $body];
        });

        $api->probeValidate('sk_live_abc');

        $this->assertNotNull($captured);
        $this->assertSame('GET', $captured['method']);
        $this->assertSame(200, $captured['status']);
    }
}

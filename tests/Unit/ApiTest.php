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
            'api_url' => 'https://test.api.com/v1',
        ]);

        $api = new Api($config);

        // We can test this through the mock transport
        $requests = [];
        $api->setTransport(function($method, $url, $payload, $headers) use (&$requests) {
            $requests[] = ['method' => $method, 'url' => $url];
            return ['status' => 200, 'body' => []];
        });

        $api->post('/events', []);

        $this->assertEquals('https://test.api.com/v1/events', $requests[0]['url']);
    }

    public function testPathWithLeadingSlashIsHandled(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'api_url' => 'https://test.api.com/v1',
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
}

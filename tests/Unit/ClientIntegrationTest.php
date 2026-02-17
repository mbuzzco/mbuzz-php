<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Client;
use Mbuzz\Config;
use Mbuzz\Context;
use Mbuzz\CookieManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests verifying Client uses Context for ip/userAgent
 *
 * These tests mirror the integration tests in Ruby, Node, and Python SDKs.
 */
class ClientIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // Set up server vars for context
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
        $_SERVER['REQUEST_URI'] = '/test-page';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        Config::reset();
        Context::reset();
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_USER_AGENT']);
        unset($_SERVER['REQUEST_URI']);
        unset($_SERVER['HTTP_HOST']);
        unset($_SERVER['HTTPS']);
    }

    private function createClient(): Client
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        // v0.8.0+: Must provide visitor cookie since we no longer auto-generate
        $cookies = new CookieManager([
            CookieManager::VISITOR_COOKIE => str_repeat('a', 64),
        ], function() { return true; });

        return new Client($config, $cookies);
    }

    // Track context tests

    public function testTrackUsesIpFromContext(): void
    {
        $client = $this->createClient();

        $capturedPayload = null;
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $client->track('page_view');

        $event = $capturedPayload['events'][0];
        $this->assertEquals('203.0.113.50', $event['ip']);
    }

    public function testTrackUsesUserAgentFromContext(): void
    {
        $client = $this->createClient();

        $capturedPayload = null;
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $client->track('page_view');

        $event = $capturedPayload['events'][0];
        $this->assertEquals('Mozilla/5.0 Test', $event['user_agent']);
    }

    public function testTrackUsesBothIpAndUserAgentFromContext(): void
    {
        $client = $this->createClient();

        $capturedPayload = null;
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $client->track('page_view', ['product_id' => 'SKU-123']);

        $event = $capturedPayload['events'][0];
        $this->assertEquals('203.0.113.50', $event['ip']);
        $this->assertEquals('Mozilla/5.0 Test', $event['user_agent']);
    }

    // Conversion context tests

    public function testConversionUsesIpFromContext(): void
    {
        $client = $this->createClient();

        $capturedPayload = null;
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $client->conversion('purchase');

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('203.0.113.50', $conversion['ip']);
    }

    public function testConversionUsesUserAgentFromContext(): void
    {
        $client = $this->createClient();

        $capturedPayload = null;
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $client->conversion('purchase');

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('Mozilla/5.0 Test', $conversion['user_agent']);
    }

    public function testConversionExplicitIpOverridesContext(): void
    {
        $client = $this->createClient();

        $capturedPayload = null;
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $client->conversion('purchase', [
            'ip' => 'explicit_ip',
            'user_agent' => 'explicit_ua',
        ]);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('explicit_ip', $conversion['ip']);
        $this->assertEquals('explicit_ua', $conversion['user_agent']);
    }

    // Identify → Convert flow (Phase 3D reference verification)

    public function testConversionPicksUpUserIdAfterIdentify(): void
    {
        $client = $this->createClient();

        $capturedPayloads = [];
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayloads) {
            $decoded = json_decode($payload, true);
            $capturedPayloads[] = $decoded;

            if (str_contains($url, '/identify')) {
                return ['status' => 200, 'body' => true];
            }
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $client->identify('user_123', ['email' => 'jane@example.com']);
        $client->conversion('purchase', ['revenue' => 99.99]);

        $this->assertCount(2, $capturedPayloads);
        $conversionPayload = $capturedPayloads[1]['conversion'];
        $this->assertEquals('user_123', $conversionPayload['user_id']);
    }

    public function testConversionPassesAllContextFields(): void
    {
        $client = $this->createClient();

        $capturedPayload = null;
        $client->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $client->conversion('signup', [
            'identifier' => ['email' => 'new@example.com'],
        ]);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('203.0.113.50', $conversion['ip']);
        $this->assertEquals('Mozilla/5.0 Test', $conversion['user_agent']);
        $this->assertEquals(['email' => 'new@example.com'], $conversion['identifier']);
    }
}

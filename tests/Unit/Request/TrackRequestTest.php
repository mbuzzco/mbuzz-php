<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit\Request;

use Mbuzz\Api;
use Mbuzz\Config;
use Mbuzz\Request\TrackRequest;
use PHPUnit\Framework\TestCase;

class TrackRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    private function createMockApi(): Api
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);
        return new Api($config);
    }

    public function testSendReturnsFalseWithEmptyEventType(): void
    {
        $request = new TrackRequest(
            eventType: '',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();
        $result = $request->send($api);

        $this->assertFalse($result);
    }

    public function testSendReturnsFalseWithNoIdentifiers(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: null,
            userId: null,
        );

        $api = $this->createMockApi();
        $result = $request->send($api);

        $this->assertFalse($result);
    }

    public function testSendBuildsCorrectPayload(): void
    {
        $visitorId = str_repeat('a', 64);
        $sessionId = str_repeat('b', 64);

        $request = new TrackRequest(
            eventType: 'add_to_cart',
            visitorId: $visitorId,
            sessionId: $sessionId,
            userId: 'user_123',
            properties: ['product_id' => 'SKU-123', 'price' => 49.99],
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $request->send($api);

        $this->assertArrayHasKey('events', $capturedPayload);
        $this->assertCount(1, $capturedPayload['events']);

        $event = $capturedPayload['events'][0];
        $this->assertEquals('add_to_cart', $event['event_type']);
        $this->assertEquals($visitorId, $event['visitor_id']);
        $this->assertEquals($sessionId, $event['session_id']);
        $this->assertEquals('user_123', $event['user_id']);
        $this->assertEquals('SKU-123', $event['properties']['product_id']);
        $this->assertEquals(49.99, $event['properties']['price']);
        $this->assertArrayHasKey('timestamp', $event);
    }

    public function testSendReturnsSuccessResult(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: str_repeat('a', 64),
            sessionId: str_repeat('b', 64),
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 202, 'body' => [
                'events' => [['id' => 'evt_abc123', 'status' => 'accepted']],
            ]];
        });

        $result = $request->send($api);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('evt_abc123', $result['event_id']);
        $this->assertEquals('page_view', $result['event_type']);
    }

    public function testSendReturnsFalseOnApiError(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 401, 'body' => null];
        });

        $result = $request->send($api);

        $this->assertFalse($result);
    }

    public function testTimestampIsIso8601Format(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $request->send($api);

        $timestamp = $capturedPayload['events'][0]['timestamp'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $timestamp);
    }

    public function testWorksWithOnlyUserId(): void
    {
        $request = new TrackRequest(
            eventType: 'backend_event',
            visitorId: null,
            userId: 'user_123',
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $result = $request->send($api);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    // Server-side session resolution tests (v0.2.0+)

    public function testSendIncludesIpInPayload(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: str_repeat('a', 64),
            ip: '192.168.1.100',
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $request->send($api);

        $event = $capturedPayload['events'][0];
        $this->assertEquals('192.168.1.100', $event['ip']);
    }

    public function testSendIncludesUserAgentInPayload(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: str_repeat('a', 64),
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $request->send($api);

        $event = $capturedPayload['events'][0];
        $this->assertEquals('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', $event['user_agent']);
    }

    public function testSendIncludesBothIpAndUserAgent(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: str_repeat('a', 64),
            ip: '10.0.0.1',
            userAgent: 'Chrome/120',
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $request->send($api);

        $event = $capturedPayload['events'][0];
        $this->assertEquals('10.0.0.1', $event['ip']);
        $this->assertEquals('Chrome/120', $event['user_agent']);
    }

    public function testSendOmitsIpAndUserAgentWhenNotProvided(): void
    {
        $request = new TrackRequest(
            eventType: 'page_view',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 202, 'body' => ['events' => [['id' => 'evt_123']]]];
        });

        $request->send($api);

        $event = $capturedPayload['events'][0];
        $this->assertArrayNotHasKey('ip', $event);
        $this->assertArrayNotHasKey('user_agent', $event);
    }
}

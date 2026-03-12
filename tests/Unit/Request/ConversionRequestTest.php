<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit\Request;

use Mbuzz\Api;
use Mbuzz\Config;
use Mbuzz\Request\ConversionRequest;
use PHPUnit\Framework\TestCase;

class ConversionRequestTest extends TestCase
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

    public function testSendReturnsFalseWithEmptyConversionType(): void
    {
        $request = new ConversionRequest(
            conversionType: '',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();
        $result = $request->send($api);

        $this->assertFalse($result);
    }

    public function testSendReturnsFalseWithNoIdentifiers(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: null,
            userId: null,
            eventId: null,
        );

        $api = $this->createMockApi();
        $result = $request->send($api);

        $this->assertFalse($result);
    }

    public function testSendBuildsCorrectPayload(): void
    {
        $visitorId = str_repeat('a', 64);

        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: $visitorId,
            userId: 'user_123',
            revenue: 99.99,
            currency: 'EUR',
            isAcquisition: true,
            properties: ['order_id' => 'ORD-123'],
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123'], 'attribution' => ['status' => 'pending']]];
        });

        $request->send($api);

        $this->assertArrayHasKey('conversion', $capturedPayload);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('purchase', $conversion['conversion_type']);
        $this->assertEquals($visitorId, $conversion['visitor_id']);
        $this->assertEquals('user_123', $conversion['user_id']);
        $this->assertEquals(99.99, $conversion['revenue']);
        $this->assertEquals('EUR', $conversion['currency']);
        $this->assertTrue($conversion['is_acquisition']);
        $this->assertEquals('ORD-123', $conversion['properties']['order_id']);
        $this->assertArrayHasKey('timestamp', $conversion);
    }

    public function testSendReturnsSuccessResult(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 201, 'body' => [
                'conversion' => ['id' => 'conv_abc123'],
                'attribution' => ['status' => 'pending'],
            ]];
        });

        $result = $request->send($api);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('conv_abc123', $result['conversion_id']);
    }

    public function testSendReturnsFalseOnApiError(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 401, 'body' => null];
        });

        $result = $request->send($api);

        $this->assertFalse($result);
    }

    public function testWorksWithOnlyUserId(): void
    {
        $request = new ConversionRequest(
            conversionType: 'signup',
            visitorId: null,
            userId: 'user_123',
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $result = $request->send($api);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function testWorksWithOnlyEventId(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: null,
            userId: null,
            eventId: 'evt_abc123',
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $result = $request->send($api);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    // Server-side session resolution tests (v0.7.0+)

    public function testSendIncludesIpInPayload(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
            ip: '192.168.1.100',
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $request->send($api);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('192.168.1.100', $conversion['ip']);
    }

    public function testSendIncludesUserAgentInPayload(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $request->send($api);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', $conversion['user_agent']);
    }

    public function testSendIncludesBothIpAndUserAgent(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
            ip: '10.0.0.1',
            userAgent: 'Chrome/120',
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $request->send($api);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('10.0.0.1', $conversion['ip']);
        $this->assertEquals('Chrome/120', $conversion['user_agent']);
    }

    public function testSendIncludesIdentifierInPayload(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
            identifier: ['email' => 'user@example.com'],
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $request->send($api);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals(['email' => 'user@example.com'], $conversion['identifier']);
    }

    public function testSendIncludesAllFingerprintFields(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
            ip: '203.0.113.50',
            userAgent: 'Safari/17',
            identifier: ['email' => 'buyer@example.com'],
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $request->send($api);

        $conversion = $capturedPayload['conversion'];
        $this->assertEquals('203.0.113.50', $conversion['ip']);
        $this->assertEquals('Safari/17', $conversion['user_agent']);
        $this->assertEquals(['email' => 'buyer@example.com'], $conversion['identifier']);
    }

    public function testSendOmitsIpAndUserAgentWhenNotProvided(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();

        $capturedPayload = null;
        $api->setTransport(function($method, $url, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['conversion' => ['id' => 'conv_123']]];
        });

        $request->send($api);

        $conversion = $capturedPayload['conversion'];
        $this->assertArrayNotHasKey('ip', $conversion);
        $this->assertArrayNotHasKey('user_agent', $conversion);
        $this->assertArrayNotHasKey('identifier', $conversion);
    }

    public function testReturnsAttributionData(): void
    {
        $request = new ConversionRequest(
            conversionType: 'purchase',
            visitorId: str_repeat('a', 64),
        );

        $api = $this->createMockApi();
        $api->setTransport(function() {
            return ['status' => 201, 'body' => [
                'conversion' => ['id' => 'conv_123'],
                'attribution' => ['status' => 'pending', 'models' => []],
            ]];
        });

        $result = $request->send($api);

        $this->assertIsArray($result);
        $this->assertEquals(['status' => 'pending', 'models' => []], $result['attribution']);
    }
}

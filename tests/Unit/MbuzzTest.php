<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Mbuzz;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MbuzzTest extends TestCase
{
    protected function tearDown(): void
    {
        Mbuzz::reset();
    }

    public function testThrowsWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mbuzz::init() must be called before using the SDK');

        Mbuzz::event('page_view');
    }

    public function testInitSucceeds(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        $this->assertNotNull(Mbuzz::getClient());
    }

    public function testEventReturnsFalseWhenDisabled(): void
    {
        Mbuzz::init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $result = Mbuzz::event('page_view');

        $this->assertFalse($result);
    }

    public function testConversionReturnsFalseWhenDisabled(): void
    {
        Mbuzz::init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $result = Mbuzz::conversion('purchase');

        $this->assertFalse($result);
    }

    public function testIdentifyReturnsFalseWhenDisabled(): void
    {
        Mbuzz::init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $result = Mbuzz::identify('user_123');

        $this->assertFalse($result);
    }

    public function testVisitorIdReturnsNullBeforeInitFromRequest(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        $this->assertNull(Mbuzz::visitorId());
    }

    public function testUserIdReturnsNullBeforeIdentify(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        $this->assertNull(Mbuzz::userId());
    }

    public function testResetClearsState(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        Mbuzz::reset();

        $this->expectException(RuntimeException::class);
        Mbuzz::event('page_view');
    }

    public function testFlushThrowsWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        Mbuzz::flush();
    }

    public function testValidateThrowsWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        Mbuzz::validate('sk_live_x');
    }

    public function testOnSuccessThrowsWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        Mbuzz::onSuccess(fn() => null);
    }

    public function testValidateWithCandidateKey(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_configured']);

        $capturedHeaders = [];
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload, $headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return ['status' => 200, 'body' => ['valid' => true]];
        });

        $result = Mbuzz::validate('sk_live_candidate');

        $this->assertSame(['valid' => true], $result);
        $this->assertContains('Authorization: Bearer sk_live_candidate', $capturedHeaders);
    }

    public function testValidateWithNullUsesConfiguredKey(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_configured']);

        $capturedHeaders = [];
        Mbuzz::getClient()->setTransport(function ($method, $url, $payload, $headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return ['status' => 200, 'body' => ['valid' => true]];
        });

        Mbuzz::validate();

        $this->assertContains('Authorization: Bearer sk_test_configured', $capturedHeaders);
    }

    public function testOnSuccessReceivesEventDispatches(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        Mbuzz::getClient()->setTransport(fn() => ['status' => 200, 'body' => ['event_id' => 'evt_1']]);

        $successCount = 0;
        Mbuzz::onSuccess(function () use (&$successCount) {
            $successCount++;
        });

        // event() needs a visitor id — use the explicit-visitor-id form.
        Mbuzz::event('page_view', [], str_repeat('a', 64));

        $this->assertSame(1, $successCount);
    }

    public function testFlushDrainsDeferredQueue(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        $apiProp = (new \ReflectionClass(Mbuzz::getClient()))->getProperty('api');
        /** @var \Mbuzz\Api $apiInstance */
        $apiInstance = $apiProp->getValue(Mbuzz::getClient());

        // Queue a post (no transport set — deferred).
        $apiInstance->post('/sessions', ['x' => 1]);

        $calls = 0;
        $apiInstance->setTransport(function () use (&$calls) {
            $calls++;
            return ['status' => 202, 'body' => null];
        });

        $this->assertSame(0, $calls);

        Mbuzz::flush();

        $this->assertSame(1, $calls);
    }
}

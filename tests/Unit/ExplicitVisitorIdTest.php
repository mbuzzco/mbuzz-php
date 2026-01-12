<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Context;
use Mbuzz\Config;
use Mbuzz\Client;
use Mbuzz\CookieManager;
use Mbuzz\Mbuzz;
use PHPUnit\Framework\TestCase;

/**
 * Tests for explicit visitor_id requirement (v0.8.0+)
 *
 * Events/conversions should FAIL when:
 * - Called outside request context (no cookie)
 * - No explicit visitor_id provided
 *
 * This prevents orphan visitors from background jobs.
 */
class ExplicitVisitorIdTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any existing state
        Mbuzz::reset();
    }

    protected function tearDown(): void
    {
        Mbuzz::reset();
    }

    // -------------------------------------------------------------------
    // Mbuzz::event() tests
    // -------------------------------------------------------------------

    public function testEventWithoutContextAndWithoutExplicitVisitorIdFails(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        // No initFromRequest() called, no visitor cookie, no explicit visitor_id
        // Simulates background job calling Mbuzz::event() without visitor_id
        $result = Mbuzz::event('background_event', ['order_id' => '123']);

        $this->assertFalse(
            $result,
            'Event without context and without explicit visitor_id should return false'
        );
    }

    public function testEventWithExplicitVisitorIdSucceeds(): void
    {
        $apiCalled = false;

        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        // Mock the API transport - must return status + body format
        $client = Mbuzz::getClient();
        $client->setTransport(function ($method, $path, $data, $headers) use (&$apiCalled) {
            $apiCalled = true;
            return [
                'status' => 200,
                'body' => [
                    'accepted' => 1,
                    'rejected' => [],
                    'events' => [
                        [
                            'id' => 'evt_test123',
                            'event_type' => 'background_event',
                            'visitor_id' => 'explicit_vid_123',
                            'status' => 'accepted',
                        ],
                    ],
                ],
            ];
        });

        // Call with explicit visitor_id - the correct pattern for background jobs
        $result = Mbuzz::event('background_event', ['order_id' => '123'], 'explicit_vid_123');

        $this->assertTrue($apiCalled, 'API should have been called');
        $this->assertNotFalse($result, 'Event with explicit visitor_id should succeed');
    }

    public function testEventWithContextWorksNormally(): void
    {
        $apiCalled = false;
        $capturedVisitorId = null;

        // Simulate request context with visitor cookie BEFORE init
        $existingVisitorId = str_repeat('a', 64);
        $cookies = new CookieManager([
            CookieManager::VISITOR_COOKIE => $existingVisitorId,
        ]);
        $context = Context::getInstance();
        $context->initialize($cookies);

        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        // Mock the API transport to capture the visitor_id
        $client = Mbuzz::getClient();
        $client->setTransport(function ($method, $path, $data, $headers) use (&$apiCalled, &$capturedVisitorId) {
            $apiCalled = true;
            $decoded = json_decode($data, true);
            $capturedVisitorId = $decoded['events'][0]['visitor_id'] ?? null;
            return [
                'status' => 200,
                'body' => [
                    'accepted' => 1,
                    'rejected' => [],
                    'events' => [
                        [
                            'id' => 'evt_test123',
                            'event_type' => 'page_view',
                            'visitor_id' => $capturedVisitorId,
                            'status' => 'accepted',
                        ],
                    ],
                ],
            ];
        });

        $result = Mbuzz::event('page_view', ['url' => '/products']);

        $this->assertTrue($apiCalled, 'API should have been called');
        $this->assertEquals($existingVisitorId, $capturedVisitorId);
        $this->assertNotFalse($result, 'Event within request context should succeed');
    }

    // -------------------------------------------------------------------
    // Mbuzz::conversion() tests
    // -------------------------------------------------------------------

    public function testConversionWithoutContextAndWithoutExplicitVisitorIdFails(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        // No context, no visitor_id provided
        $result = Mbuzz::conversion('purchase', ['revenue' => 99.99]);

        $this->assertFalse(
            $result,
            'Conversion without context and without explicit visitor_id should return false'
        );
    }

    public function testConversionWithExplicitVisitorIdSucceeds(): void
    {
        $apiCalled = false;

        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        // Mock the API transport - must return status + body format
        $client = Mbuzz::getClient();
        $client->setTransport(function ($method, $path, $data, $headers) use (&$apiCalled) {
            $apiCalled = true;
            return [
                'status' => 200,
                'body' => [
                    'conversion' => [
                        'id' => 'conv_test123',
                        'visitor_id' => 'explicit_vid_123',
                        'conversion_type' => 'purchase',
                        'revenue' => '99.99',
                    ],
                    'attribution' => ['status' => 'pending'],
                ],
            ];
        });

        // Call with explicit visitor_id
        $result = Mbuzz::conversion('purchase', [
            'visitor_id' => 'explicit_vid_123',
            'revenue' => 99.99,
        ]);

        $this->assertTrue($apiCalled, 'API should have been called');
        $this->assertNotFalse($result, 'Conversion with explicit visitor_id should succeed');
    }

    public function testConversionWithUserIdOnlySucceeds(): void
    {
        $apiCalled = false;

        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        // Mock the API transport - must return status + body format
        $client = Mbuzz::getClient();
        $client->setTransport(function ($method, $path, $data, $headers) use (&$apiCalled) {
            $apiCalled = true;
            return [
                'status' => 200,
                'body' => [
                    'conversion' => [
                        'id' => 'conv_test123',
                        'user_id' => 'user_123',
                        'conversion_type' => 'payment',
                        'revenue' => '49.99',
                    ],
                    'attribution' => ['status' => 'pending'],
                ],
            ];
        });

        // user_id is also a valid identifier
        $result = Mbuzz::conversion('payment', [
            'user_id' => 'user_123',
            'revenue' => 49.99,
        ]);

        $this->assertTrue($apiCalled, 'API should have been called');
        $this->assertNotFalse($result, 'Conversion with user_id should succeed even without visitor_id');
    }

    // -------------------------------------------------------------------
    // Mbuzz::visitorId() behavior tests
    // -------------------------------------------------------------------

    public function testVisitorIdReturnsNullWithoutContext(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        // No initFromRequest() called - simulates background job
        $visitorId = Mbuzz::visitorId();

        $this->assertNull(
            $visitorId,
            'visitorId should return null when no request context'
        );
    }
}

<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Client;
use Mbuzz\Config;
use Mbuzz\Context;
use Mbuzz\CookieManager;
use Mbuzz\NavigationDetector;
use PHPUnit\Framework\TestCase;

/**
 * Test NavigationDetector — Sec-Fetch-* whitelist + framework blacklist fallback.
 *
 * Mirrors the e2e test at sdk_integration_tests/scenarios/navigation_detection_test.rb.
 */
class NavigationDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_HOST'] = 'example.com';
    }

    protected function tearDown(): void
    {
        Config::reset();
        Context::reset();
        unset(
            $_SERVER['HTTP_SEC_FETCH_MODE'],
            $_SERVER['HTTP_SEC_FETCH_DEST'],
            $_SERVER['HTTP_SEC_FETCH_SITE'],
            $_SERVER['HTTP_SEC_FETCH_USER'],
            $_SERVER['HTTP_SEC_PURPOSE'],
            $_SERVER['HTTP_TURBO_FRAME'],
            $_SERVER['HTTP_HX_REQUEST'],
            $_SERVER['HTTP_X_UP_VERSION'],
            $_SERVER['HTTP_X_REQUESTED_WITH'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_REFERER'],
            $_SERVER['REMOTE_ADDR'],
        );
    }

    // -----------------------------------------------------------------
    // Whitelist path: modern browsers with Sec-Fetch-* headers
    // -----------------------------------------------------------------

    public function testRealNavigationReturnsTrue(): void
    {
        $server = [
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
        ];

        $this->assertTrue(NavigationDetector::shouldCreateSession($server));
    }

    public function testTurboFrameReturnsFalse(): void
    {
        $server = [
            'HTTP_SEC_FETCH_MODE' => 'same-origin',
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_TURBO_FRAME' => 'content_frame',
        ];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    public function testHtmxReturnsFalse(): void
    {
        $server = [
            'HTTP_SEC_FETCH_MODE' => 'same-origin',
            'HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_HX_REQUEST' => 'true',
        ];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    public function testFetchXhrReturnsFalse(): void
    {
        $server = [
            'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_DEST' => 'empty',
        ];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    public function testPrefetchReturnsFalse(): void
    {
        $server = [
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_PURPOSE' => 'prefetch',
        ];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    public function testIframeReturnsFalse(): void
    {
        $server = [
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'iframe',
        ];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    // -----------------------------------------------------------------
    // Blacklist fallback: old browsers without Sec-Fetch-* headers
    // -----------------------------------------------------------------

    public function testOldBrowserNoFrameworkHeadersReturnsTrue(): void
    {
        $this->assertTrue(NavigationDetector::shouldCreateSession([]));
    }

    public function testOldBrowserTurboFrameReturnsFalse(): void
    {
        $server = ['HTTP_TURBO_FRAME' => 'lazy_banner'];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    public function testOldBrowserHxRequestReturnsFalse(): void
    {
        $server = ['HTTP_HX_REQUEST' => 'true'];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    public function testOldBrowserXhrReturnsFalse(): void
    {
        $server = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    public function testOldBrowserUnpolyReturnsFalse(): void
    {
        $server = ['HTTP_X_UP_VERSION' => '3.0.0'];

        $this->assertFalse(NavigationDetector::shouldCreateSession($server));
    }

    // -----------------------------------------------------------------
    // Client integration: session creation + cookie behavior
    // -----------------------------------------------------------------

    public function testNavigationCallsPostSessions(): void
    {
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $capturedPath = null;
        $capturedPayload = null;
        $client = $this->createClientWithVisitor($capturedPath, $capturedPayload);

        $client->initFromRequest();

        $this->assertEquals('/sessions', $capturedPath);
        $this->assertArrayHasKey('session', $capturedPayload);
        $this->assertArrayHasKey('visitor_id', $capturedPayload['session']);
        $this->assertArrayHasKey('session_id', $capturedPayload['session']);
        $this->assertArrayHasKey('device_fingerprint', $capturedPayload['session']);
        $this->assertEquals(32, strlen($capturedPayload['session']['device_fingerprint']));
    }

    public function testTurboFrameDoesNotCallPost(): void
    {
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'same-origin';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'empty';
        $_SERVER['HTTP_TURBO_FRAME'] = 'banner';

        $capturedPath = null;
        $capturedPayload = null;
        $client = $this->createClientWithVisitor($capturedPath, $capturedPayload);

        $client->initFromRequest();

        $this->assertNull($capturedPath, 'POST /sessions must not be called for Turbo frame');
    }

    public function testSessionNotCreatedWithoutVisitorCookie(): void
    {
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';

        $capturedPath = null;
        $capturedPayload = null;
        $client = $this->createClientWithoutVisitor($capturedPath, $capturedPayload);

        $client->initFromRequest();

        $this->assertNull($capturedPath, 'POST /sessions must not be called without visitor cookie');
    }

    public function testResponseAlwaysSucceeds(): void
    {
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $capturedPath = null;
        $capturedPayload = null;
        $client = $this->createClientWithVisitor($capturedPath, $capturedPayload);

        // Should not throw
        $client->initFromRequest();
        $this->assertNotNull($capturedPath);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createClientWithVisitor(?string &$capturedPath, ?array &$capturedPayload): Client
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $cookies = new CookieManager([
            CookieManager::VISITOR_COOKIE => str_repeat('a', 64),
        ], function () { return true; });

        $client = new Client($config, $cookies);
        $client->setTransport(function ($method, $url, $payload) use (&$capturedPath, &$capturedPayload) {
            // Extract path from URL
            $parsed = parse_url($url);
            $capturedPath = str_replace('/api/v1', '', $parsed['path'] ?? '');
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['session' => ['id' => 'sess_123']]];
        });

        return $client;
    }

    private function createClientWithoutVisitor(?string &$capturedPath, ?array &$capturedPayload): Client
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        // No visitor cookie
        $cookies = new CookieManager([], function () { return true; });

        $client = new Client($config, $cookies);
        $client->setTransport(function ($method, $url, $payload) use (&$capturedPath, &$capturedPayload) {
            $parsed = parse_url($url);
            $capturedPath = str_replace('/api/v1', '', $parsed['path'] ?? '');
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['session' => ['id' => 'sess_123']]];
        });

        return $client;
    }
}

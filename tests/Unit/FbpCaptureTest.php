<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Client;
use Mbuzz\Config;
use Mbuzz\Context;
use Mbuzz\CookieManager;
use Mbuzz\SessionEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * B7 — the session call forwards the `_fbp` cookie Meta's Pixel already set.
 *
 * Meta can only merge a server `Lead` with the browser Pixel's `Lead` for the
 * same enquiry on `event_name` + `fbp`, and `fbp` is a first-party cookie the
 * Pixel writes client-side. The SDK reads it off the request and forwards it
 * verbatim — never sets, changes or invents one.
 *
 * Both session paths: the page path (what the WordPress plugin drives, cached
 * or not) and the uncached endpoint.
 *
 * End to end: sdk_integration_tests/scenarios/fbp_capture_test.rb.
 * Spec: multibuzz lib/specs/fbp_capture_wordpress_spec.md
 */
class FbpCaptureTest extends TestCase
{
    private const FBP = 'fb.1.1758585600000.1234567890';

    private string $requestBody = '';

    protected function setUp(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.55';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
    }

    protected function tearDown(): void
    {
        Config::reset();
        Context::reset();
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_SEC_FETCH_MODE'], $_SERVER['HTTP_SEC_FETCH_DEST']);
    }

    // --- the cookie ---

    public function testReadsAWellFormedFbp(): void
    {
        $this->assertSame(self::FBP, (new CookieManager([CookieManager::FBP_COOKIE => self::FBP]))->getFbp());
    }

    public function testIgnoresAMalformedFbp(): void
    {
        $this->assertNull((new CookieManager([CookieManager::FBP_COOKIE => 'not-a-meta-cookie']))->getFbp());
    }

    public function testNoFbpCookieIsNull(): void
    {
        $this->assertNull((new CookieManager([]))->getFbp());
    }

    // --- the page path (the WordPress plugin's) ---

    public function testThePagePathForwardsFbp(): void
    {
        $payload = $this->pageSession([CookieManager::VISITOR_COOKIE => 'vis_1', CookieManager::FBP_COOKIE => self::FBP]);

        $this->assertSame(self::FBP, $payload['session']['fbp']);
    }

    public function testThePagePathOmitsFbpWhenAbsent(): void
    {
        $payload = $this->pageSession([CookieManager::VISITOR_COOKIE => 'vis_1']);

        $this->assertArrayNotHasKey('fbp', $payload['session']);
    }

    // --- the uncached endpoint ---

    public function testTheEndpointForwardsFbp(): void
    {
        $payload = $this->endpointSession([CookieManager::FBP_COOKIE => self::FBP]);

        $this->assertSame(self::FBP, $payload['session']['fbp']);
    }

    public function testTheEndpointOmitsAMalformedFbp(): void
    {
        $payload = $this->endpointSession([CookieManager::FBP_COOKIE => 'not-a-meta-cookie']);

        $this->assertArrayNotHasKey('fbp', $payload['session']);
    }

    // --- read-only on the Pixel's cookie ---

    public function testNeverWritesTheFbpCookie(): void
    {
        $cookiesSet = [];
        $this->endpointSession([CookieManager::FBP_COOKIE => self::FBP], $cookiesSet);

        $this->assertArrayNotHasKey(CookieManager::FBP_COOKIE, $cookiesSet);
    }

    /**
     * @param array<string, string> $cookies
     * @return array<string, mixed>
     */
    private function pageSession(array $cookies): array
    {
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';

        $cookiesSet = [];
        return $this->capture($cookies, $cookiesSet);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $cookiesSet
     * @return array<string, mixed>
     */
    private function endpointSession(array $cookies, array &$cookiesSet = []): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = SessionEndpoint::PATH;
        $this->requestBody = (string) json_encode(['url' => 'https://example.com/', 'referrer' => '']);

        return $this->capture($cookies, $cookiesSet);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $cookiesSet
     * @return array<string, mixed>
     */
    private function capture(array $cookies, array &$cookiesSet): array
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $recorder = function (string $name, string $value, array $options) use (&$cookiesSet) {
            $cookiesSet[$name] = $value;
            return true;
        };

        $captured = [];
        $client = new Client($config, new CookieManager($cookies, $recorder));
        $client->setBodyReader(fn () => $this->requestBody);
        $client->setTransport(function ($method, $url, $payload) use (&$captured) {
            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/sessions')) {
                $captured = json_decode($payload, true);
            }
            return ['status' => 201, 'body' => ['session' => ['id' => 'sess_123']]];
        });

        $client->initFromRequest();

        $this->assertNotEmpty($captured, 'a session call should have been made');

        return $captured;
    }
}

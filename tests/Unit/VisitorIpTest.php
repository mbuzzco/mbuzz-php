<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Client;
use Mbuzz\Config;
use Mbuzz\Context;
use Mbuzz\CookieManager;
use PHPUnit\Framework\TestCase;

/**
 * H2.3a.8 — the session call carries the visitor's address.
 *
 * The SDK already computes it for `device_fingerprint = SHA256(ip|user_agent)`
 * and used to drop it from the payload, so mbuzz stored no visitor IP and Meta
 * flagged every server event as a user agent with no IP.
 *
 * 1.x has only the page path (no /_mbuzz/session endpoint; that arrived in 2.x).
 *
 * End to end: sdk_integration_tests/scenarios/capi_client_ip_test.rb.
 * Spec: multibuzz lib/specs/bsa_visitor_ip_and_live_review_spec.md
 */
class VisitorIpTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.55';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
    }

    protected function tearDown(): void
    {
        Config::reset();
        Context::reset();
        unset(
            $_SERVER['HTTP_SEC_FETCH_MODE'],
            $_SERVER['HTTP_SEC_FETCH_DEST'],
            $_SERVER['HTTP_X_FORWARDED_FOR']
        );
        $_SERVER['REMOTE_ADDR'] = '203.0.113.55';
    }

    public function testThePagePathCarriesTheVisitorsIp(): void
    {
        $payload = $this->pageSession();

        $this->assertSame('203.0.113.55', $payload['session']['ip']);
    }

    public function testTheForwardedClientIsTheFirstHop(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';

        $payload = $this->pageSession();

        $this->assertSame('198.51.100.7', $payload['session']['ip']);
    }

    /**
     * 'unknown' is the fingerprint's stand-in, never an address.
     */
    public function testNoKnownAddressOmitsTheKey(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);

        $payload = $this->pageSession();

        $this->assertArrayNotHasKey('ip', $payload['session']);
    }

    /** @return array<string, mixed> */
    private function pageSession(): array
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $captured = [];
        $cookies = new CookieManager([CookieManager::VISITOR_COOKIE => 'vis_1'], fn () => true);
        $client = new Client($config, $cookies);
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

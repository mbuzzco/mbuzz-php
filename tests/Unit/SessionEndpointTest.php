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
 * The uncached session endpoint — the one request that still reaches the app
 * on a page the cache served.
 *
 * Mirrors the e2e test at sdk_integration_tests/scenarios/page_cache_test.rb,
 * which is the only place these behaviours can be proven end to end: a real
 * cache is what separates "reuse the cookie the browser presents" (correct
 * everywhere else) from the visitor collapse it causes behind one.
 *
 * Spec: lib/specs/page_cache_attribution_rollout_spec.md
 */
class SessionEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.55';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
        $this->requestBody = '';
    }

    protected function tearDown(): void
    {
        Config::reset();
        Context::reset();

        // REQUEST_URI and REQUEST_METHOD must be restored, not merely unset:
        // a leaked '/_mbuzz/session' makes the NEXT test's initFromRequest()
        // take the endpoint branch and return before initialising context,
        // which fails it for a reason that has nothing to do with it. Found by
        // a random-order run — these tests are the first to point REQUEST_URI
        // at anything but '/'.
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        unset(
            $_SERVER['HTTP_SEC_FETCH_MODE'],
            $_SERVER['HTTP_SEC_FETCH_DEST'],
            $_SERVER['HTTP_X_FORWARDED_FOR']
        );
    }

    // -----------------------------------------------------------------
    // Request matching
    // -----------------------------------------------------------------

    public function testPostToTheEndpointIsASessionRequest(): void
    {
        $this->assertTrue(SessionEndpoint::isSessionRequest('POST', '/_mbuzz/session'));
    }

    /**
     * A GET is cacheable by an intermediary, which would reintroduce the very
     * bug this endpoint exists to fix.
     */
    public function testGetToTheEndpointIsNotASessionRequest(): void
    {
        $this->assertFalse(SessionEndpoint::isSessionRequest('GET', '/_mbuzz/session'));
    }

    public function testAnOrdinaryPageIsNotASessionRequest(): void
    {
        $this->assertFalse(SessionEndpoint::isSessionRequest('POST', '/checkout'));
    }

    // -----------------------------------------------------------------
    // The corruption mode: a page must never mint
    // -----------------------------------------------------------------

    /**
     * The defect that hit Ruby, Node and Python. A page response can be stored
     * by a full-page cache and replayed to every visitor, so a Set-Cookie in it
     * hands everyone the first visitor's id — corruption rather than loss.
     */
    public function testAPageResponseNeverSetsTheVisitorCookie(): void
    {
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';

        $cookiesSet = [];
        $client = $this->clientWithoutVisitor($cookiesSet);

        $client->initFromRequest();

        $this->assertSame(
            [],
            $cookiesSet,
            'a page response must never mint: behind a cache that Set-Cookie is '
            . 'replayed to every visitor, merging unrelated people into one journey'
        );
    }

    public function testMintOnPageResponseIsOff(): void
    {
        $this->assertFalse(
            SessionEndpoint::MINT_ON_PAGE_RESPONSE,
            'only the uncached endpoint may mint'
        );
    }

    // -----------------------------------------------------------------
    // The endpoint mints
    // -----------------------------------------------------------------

    public function testTheEndpointMintsAVisitorCookie(): void
    {
        $this->asSessionRequest();

        $cookiesSet = [];
        $client = $this->clientWithoutVisitor($cookiesSet);

        $handled = $client->initFromRequest();

        $this->assertTrue($handled, 'the endpoint must report that it answered the request');
        $this->assertArrayHasKey(CookieManager::VISITOR_COOKIE, $cookiesSet);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $cookiesSet[CookieManager::VISITOR_COOKIE],
            'visitor id should be 64 hex chars'
        );
    }

    /**
     * Two people on the same cached page must get different ids. This is the
     * test the spec called "the one that matters most".
     */
    public function testTwoVisitorsOnACachedPageGetDistinctIds(): void
    {
        $this->asSessionRequest();

        $first = [];
        $this->clientWithoutVisitor($first)->initFromRequest();

        Context::reset();

        $second = [];
        $this->clientWithoutVisitor($second)->initFromRequest();

        $this->assertNotEquals(
            $first[CookieManager::VISITOR_COOKIE],
            $second[CookieManager::VISITOR_COOKIE],
            'two visitors were given the same id — every later event would merge into one journey'
        );
    }

    /**
     * A returning visitor keeps their id: the endpoint honours the cookie the
     * browser already holds rather than minting over it.
     */
    public function testTheEndpointHonoursAnExistingVisitorCookie(): void
    {
        $this->asSessionRequest();

        $existing = str_repeat('b', 64);
        $capturedPath = null;
        $capturedPayload = null;
        $cookiesSet = [];

        $client = $this->client(
            [CookieManager::VISITOR_COOKIE => $existing],
            $cookiesSet,
            $capturedPath,
            $capturedPayload
        );

        $client->initFromRequest();

        $this->assertEquals($existing, $capturedPayload['session']['visitor_id']);
    }

    // -----------------------------------------------------------------
    // The session belongs to the page, not to the endpoint
    // -----------------------------------------------------------------

    public function testTheSessionRecordsThePageNotTheEndpoint(): void
    {
        $this->asSessionRequest();
        $this->withBody(['url' => 'https://example.com/pricing', 'referrer' => 'https://google.com/']);

        $capturedPath = null;
        $capturedPayload = null;
        $cookiesSet = [];
        $client = $this->client([], $cookiesSet, $capturedPath, $capturedPayload);

        $client->initFromRequest();

        $this->assertEquals('/sessions', $capturedPath);
        $this->assertEquals('https://example.com/pricing', $capturedPayload['session']['url']);
        $this->assertEquals('https://google.com/', $capturedPayload['session']['referrer']);
        $this->assertStringNotContainsString(
            SessionEndpoint::PATH,
            (string) $capturedPayload['session']['url'],
            'the session must record the page URL, not the endpoint path'
        );
    }

    /**
     * The customer's body-parser config is not something the fix can depend on.
     */
    public function testAMissingBodyStillEstablishesTheVisitor(): void
    {
        $this->asSessionRequest();
        $this->withBody(null);

        $capturedPath = null;
        $capturedPayload = null;
        $cookiesSet = [];
        $client = $this->client([], $cookiesSet, $capturedPath, $capturedPayload);

        $handled = $client->initFromRequest();

        $this->assertTrue($handled);
        $this->assertEquals('/sessions', $capturedPath);
        $this->assertNull($capturedPayload['session']['url']);
        $this->assertArrayHasKey(CookieManager::VISITOR_COOKIE, $cookiesSet);
    }

    // -----------------------------------------------------------------
    // Gate ordering — settled across Node and Python
    // -----------------------------------------------------------------

    /**
     * A customer's own skip_paths must not swallow the one request that still
     * reaches the app on a cached page.
     */
    public function testSkipPathsCannotSwallowTheEndpoint(): void
    {
        $this->asSessionRequest();

        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123', 'skip_paths' => ['/_mbuzz']]);

        $cookiesSet = [];
        $cookies = new CookieManager([], $this->recorder($cookiesSet));
        $client = new Client($config, $cookies);
        $client->setBodyReader(fn () => $this->requestBody);
        $client->setTransport(fn () => ['status' => 201, 'body' => []]);

        $handled = $client->initFromRequest();

        $this->assertTrue($handled, 'skip_paths must not be able to disable the session endpoint');
        $this->assertArrayHasKey(CookieManager::VISITOR_COOKIE, $cookiesSet);
    }

    /**
     * A fetch() can never satisfy sec-fetch-mode: navigate. Leaving the
     * navigation gate in front of the endpoint would mint the cookie and then
     * silently skip the session.
     */
    public function testTheNavigationGateDoesNotBlockTheEndpoint(): void
    {
        $this->asSessionRequest();
        // What a real fetch() sends — never 'navigate'.
        $_SERVER['HTTP_SEC_FETCH_MODE'] = 'cors';
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'empty';

        $capturedPath = null;
        $capturedPayload = null;
        $cookiesSet = [];
        $client = $this->client([], $cookiesSet, $capturedPath, $capturedPayload);

        $client->initFromRequest();

        $this->assertEquals(
            '/sessions',
            $capturedPath,
            'the session must be created even though a fetch() is not a navigation'
        );
    }

    // -----------------------------------------------------------------
    // Behind a proxy
    // -----------------------------------------------------------------

    public function testTheForwardedClientIpIsUsedForTheFingerprint(): void
    {
        $this->asSessionRequest();
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';

        $capturedPath = null;
        $capturedPayload = null;
        $cookiesSet = [];
        $client = $this->client([], $cookiesSet, $capturedPath, $capturedPayload);

        $client->initFromRequest();

        $expected = \Mbuzz\Fingerprint::compute('198.51.100.7', 'TestBrowser/1.0');
        $this->assertEquals($expected, $capturedPayload['session']['device_fingerprint']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function asSessionRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = SessionEndpoint::PATH;
    }

    /** @var string|false */
    private $requestBody = '';

    /** @param array<string, mixed>|null $body */
    private function withBody(?array $body): void
    {
        $this->requestBody = $body === null ? '' : (string) json_encode($body);
    }

    /**
     * @param array<string, string> $existing
     * @param array<string, string> $cookiesSet
     */
    private function client(
        array $existing,
        array &$cookiesSet,
        ?string &$capturedPath = null,
        ?array &$capturedPayload = null
    ): Client {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $cookies = new CookieManager($existing, $this->recorder($cookiesSet));

        $client = new Client($config, $cookies);
        $client->setBodyReader(fn () => $this->requestBody);
        $client->setTransport(function ($method, $url, $payload) use (&$capturedPath, &$capturedPayload) {
            $parsed = parse_url($url);
            $capturedPath = str_replace('/api/v1', '', $parsed['path'] ?? '');
            $capturedPayload = json_decode($payload, true);
            return ['status' => 201, 'body' => ['session' => ['id' => 'sess_123']]];
        });

        return $client;
    }

    /** @param array<string, string> $cookiesSet */
    private function clientWithoutVisitor(array &$cookiesSet): Client
    {
        $path = null;
        $payload = null;
        return $this->client([], $cookiesSet, $path, $payload);
    }

    /** @param array<string, string> $cookiesSet */
    private function recorder(array &$cookiesSet): callable
    {
        return function (string $name, string $value, array $options) use (&$cookiesSet) {
            $cookiesSet[$name] = $value;
            return true;
        };
    }
}

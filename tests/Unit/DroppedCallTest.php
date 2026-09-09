<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Client;
use Mbuzz\Config;
use Mbuzz\Context;
use Mbuzz\CookieManager;
use PHPUnit\Framework\TestCase;

/**
 * A dropped call must say why, instead of nothing at all.
 *
 * PHP's guards were already the outermost ones — Mbuzz::event() and
 * Mbuzz::conversion() only delegate to Client — unlike Node, where the public
 * entry point had no guard at all and the documented one fired a layer in.
 * Verified here rather than assumed: these tests fail if the warning moves off
 * the path a real caller takes.
 *
 * Spec: lib/specs/old/page_cache_attribution_rollout_spec.md Phase 6
 */
class DroppedCallTest extends TestCase
{
    /** @var string */
    private $logFile = '';

    /** @var string|false */
    private $previousLogSetting = false;

    protected function setUp(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'example.com';

        // Capture error_log() output rather than letting it reach stderr.
        $this->logFile = tempnam(sys_get_temp_dir(), 'mbuzz-dropped-');
        $this->previousLogSetting = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLogSetting === false ? '' : $this->previousLogSetting);
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        Config::reset();
        Context::reset();
    }

    public function testADroppedEventSaysWhy(): void
    {
        $client = $this->clientWithoutIdentity();

        $result = $client->track('add_to_cart');

        $this->assertFalse($result, 'the call is still dropped — it has nobody to attribute to');
        $this->assertStringContainsString('dropped event "add_to_cart"', $this->logged());
        $this->assertStringContainsString('no visitor_id and no user_id', $this->logged());
    }

    public function testADroppedConversionSaysWhy(): void
    {
        $client = $this->clientWithoutIdentity();

        $result = $client->conversion('purchase');

        $this->assertFalse($result);
        $this->assertStringContainsString('dropped conversion "purchase"', $this->logged());
    }

    /**
     * The warning has to be actionable: the customers who hit this are the ones
     * whose pages sit behind a cache, so it must name that.
     */
    public function testTheWarningNamesTheCacheFix(): void
    {
        $this->clientWithoutIdentity()->track('page_view');

        $logged = $this->logged();
        $this->assertStringContainsString('/_mbuzz/session', $logged);
        $this->assertStringContainsString('full-page cache', $logged);
    }

    /**
     * Not behind the debug flag — the customers who hit this are precisely the
     * ones not running in debug.
     */
    public function testTheWarningIsNotGatedOnDebug(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123', 'debug' => false]);

        $client = new Client($config, new CookieManager([], fn () => true));
        $client->track('page_view');

        $this->assertStringContainsString('dropped event', $this->logged());
    }

    /**
     * A delivered call must stay silent, or the warning becomes noise nobody
     * reads.
     */
    public function testADeliveredEventWarnsNothing(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $cookies = new CookieManager(
            [CookieManager::VISITOR_COOKIE => str_repeat('a', 64)],
            fn () => true
        );

        $client = new Client($config, $cookies);
        $client->setTransport(fn () => ['status' => 202, 'body' => ['success' => true]]);

        $client->track('page_view');

        $this->assertStringNotContainsString('dropped', $this->logged());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function clientWithoutIdentity(): Client
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        // No visitor cookie and no identify() call: nothing to attribute to.
        $client = new Client($config, new CookieManager([], fn () => true));
        $client->setTransport(fn () => ['status' => 202, 'body' => []]);

        return $client;
    }

    private function logged(): string
    {
        return file_exists($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }
}

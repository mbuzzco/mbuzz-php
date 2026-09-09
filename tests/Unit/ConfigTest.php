<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Config;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $config1 = Config::getInstance();
        $config2 = Config::getInstance();

        $this->assertSame($config1, $config2);
    }

    public function testResetClearsSingleton(): void
    {
        $config1 = Config::getInstance();
        Config::reset();
        $config2 = Config::getInstance();

        $this->assertNotSame($config1, $config2);
    }

    public function testInitRequiresApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key is required');

        Config::getInstance()->init([]);
    }

    public function testInitWithEmptyApiKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::getInstance()->init(['api_key' => '']);
    }

    public function testInitSetsApiKey(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertEquals('sk_test_abc123', $config->getApiKey());
    }

    public function testInitSetsDefaultApiUrl(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertEquals('https://api.mbuzz.co/api/v1', $config->getApiUrl());
    }

    public function testApiUrlIgnoredInOptions(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'api_url' => 'https://custom.api.com/v1',
        ]);

        // api_url in options is silently ignored — always uses default
        $this->assertEquals('https://api.mbuzz.co/api/v1', $config->getApiUrl());
    }

    public function testDefaultEnabledIsTrue(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertTrue($config->isEnabled());
    }

    public function testCanDisableTracking(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $this->assertFalse($config->isEnabled());
    }

    public function testDefaultDebugIsFalse(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertFalse($config->isDebug());
    }

    public function testCanEnableDebug(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'debug' => true,
        ]);

        $this->assertTrue($config->isDebug());
    }

    public function testDefaultTimeoutIsFiveSeconds(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertEquals(5, $config->getTimeout());
    }

    public function testCanSetCustomTimeout(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'timeout' => 10,
        ]);

        $this->assertEquals(10, $config->getTimeout());
    }

    public function testIsInitializedReturnsFalseBeforeInit(): void
    {
        $config = Config::getInstance();

        $this->assertFalse($config->isInitialized());
    }

    public function testIsInitializedReturnsTrueAfterInit(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertTrue($config->isInitialized());
    }

    public function testIsEnabledRequiresInitialization(): void
    {
        $config = Config::getInstance();

        // Not initialized, so should return false even if enabled by default
        $this->assertFalse($config->isEnabled());
    }

    public function testIsTestKeyReturnsTrueForTestKey(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertTrue($config->isTestKey());
    }

    public function testIsTestKeyReturnsFalseForLiveKey(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_live_abc123']);

        $this->assertFalse($config->isTestKey());
    }

    public function testShouldSkipPathForDefaultSkipPaths(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertTrue($config->shouldSkipPath('/health'));
        $this->assertTrue($config->shouldSkipPath('/healthz'));
        $this->assertTrue($config->shouldSkipPath('/ping'));
        $this->assertTrue($config->shouldSkipPath('/up'));
        $this->assertTrue($config->shouldSkipPath('/favicon.ico'));
        $this->assertTrue($config->shouldSkipPath('/robots.txt'));
    }

    public function testShouldSkipPathForDefaultExtensions(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertTrue($config->shouldSkipPath('/assets/main.js'));
        $this->assertTrue($config->shouldSkipPath('/styles/app.css'));
        $this->assertTrue($config->shouldSkipPath('/images/logo.png'));
        $this->assertTrue($config->shouldSkipPath('/images/hero.jpg'));
        $this->assertTrue($config->shouldSkipPath('/fonts/roboto.woff2'));
    }

    public function testShouldNotSkipNormalPaths(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $this->assertFalse($config->shouldSkipPath('/'));
        $this->assertFalse($config->shouldSkipPath('/checkout'));
        $this->assertFalse($config->shouldSkipPath('/products/123'));
        $this->assertFalse($config->shouldSkipPath('/api/orders'));
    }

    public function testCustomSkipPaths(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'skip_paths' => ['/admin', '/internal'],
        ]);

        $this->assertTrue($config->shouldSkipPath('/admin'));
        $this->assertTrue($config->shouldSkipPath('/admin/users'));
        $this->assertTrue($config->shouldSkipPath('/internal'));
        // Default paths still work
        $this->assertTrue($config->shouldSkipPath('/health'));
    }

    public function testCustomSkipExtensions(): void
    {
        $config = Config::getInstance();
        $config->init([
            'api_key' => 'sk_test_abc123',
            'skip_extensions' => ['.pdf', '.doc'],
        ]);

        $this->assertTrue($config->shouldSkipPath('/files/report.pdf'));
        $this->assertTrue($config->shouldSkipPath('/docs/manual.doc'));
        // Default extensions still work
        $this->assertTrue($config->shouldSkipPath('/assets/main.js'));
    }

    // -----------------------------------------------------------------
    // setApiUrlForTesting — the integration harness's only way in
    // -----------------------------------------------------------------

    public function testSetApiUrlForTestingRedirectsATestKey(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $config->setApiUrlForTesting('http://localhost:3987/api/v1');

        $this->assertEquals('http://localhost:3987/api/v1', $config->getApiUrl());
    }

    public function testSetApiUrlForTestingStripsATrailingSlash(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123']);

        $config->setApiUrlForTesting('http://localhost:3987/api/v1/');

        $this->assertEquals('http://localhost:3987/api/v1', $config->getApiUrl());
    }

    /**
     * The whole point of removing api_url from init() in 0.8.2: a live install
     * must never be redirected away from production by configuration.
     */
    public function testSetApiUrlForTestingRefusesALiveKey(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_live_abc123']);

        $this->expectException(\LogicException::class);
        $config->setApiUrlForTesting('http://evil.example.com/api/v1');
    }

    public function testALiveKeyKeepsTheProductionUrlAfterARefusedRedirect(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_live_abc123']);

        try {
            $config->setApiUrlForTesting('http://evil.example.com/api/v1');
        } catch (\LogicException $e) {
            // expected
        }

        $this->assertEquals('https://api.mbuzz.co/api/v1', $config->getApiUrl());
    }

    public function testInitStillIgnoresAnApiUrlOption(): void
    {
        $config = Config::getInstance();
        $config->init(['api_key' => 'sk_test_abc123', 'api_url' => 'http://ignored.example.com']);

        $this->assertEquals('https://api.mbuzz.co/api/v1', $config->getApiUrl());
    }
}

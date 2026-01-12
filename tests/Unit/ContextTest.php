<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Context;
use Mbuzz\CookieManager;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::reset();
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $context1 = Context::getInstance();
        $context2 = Context::getInstance();

        $this->assertSame($context1, $context2);
    }

    public function testResetClearsSingleton(): void
    {
        $context1 = Context::getInstance();
        Context::reset();
        $context2 = Context::getInstance();

        $this->assertNotSame($context1, $context2);
    }

    public function testNotInitializedByDefault(): void
    {
        $context = Context::getInstance();

        $this->assertFalse($context->isInitialized());
    }

    public function testInitializeReturnsNullVisitorIdForNewVisitor(): void
    {
        // v0.8.0+: Context no longer generates visitor IDs
        // Prevents orphan visitors from background jobs
        $setCookies = [];
        $cookies = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = $value;
            return true;
        });

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertTrue($context->isInitialized());
        // visitor_id should be null when no cookie exists
        $this->assertNull($context->getVisitorId());
        // No cookie should be set since there's no visitor_id to set
        $this->assertArrayNotHasKey(CookieManager::VISITOR_COOKIE, $setCookies);
    }

    public function testInitializeUsesExistingVisitorId(): void
    {
        $existingVisitorId = str_repeat('a', 64);
        $cookies = new CookieManager([
            CookieManager::VISITOR_COOKIE => $existingVisitorId,
        ]);

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertEquals($existingVisitorId, $context->getVisitorId());
    }

    public function testDoesNotSetCookiesWithoutExistingVisitor(): void
    {
        // v0.8.0+: No cookies set when visitor_id is null
        $setCookies = [];
        $cookies = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = $value;
            return true;
        });

        $context = Context::getInstance();
        $context->initialize($cookies);

        // Should not set any cookies (no visitor_id to set)
        $this->assertCount(0, $setCookies);
    }

    public function testCapturesClientIp(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 198.51.100.1';
        $cookies = new CookieManager([], function() { return true; });

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertEquals('203.0.113.50', $context->getClientIp());
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testCapturesUserAgent(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
        $cookies = new CookieManager([], function() { return true; });

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertEquals('Mozilla/5.0 Test', $context->getUserAgent());
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testUserIdIsNullByDefault(): void
    {
        $cookies = new CookieManager([], function() { return true; });

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertNull($context->getUserId());
    }

    public function testCanSetUserId(): void
    {
        $cookies = new CookieManager([], function() { return true; });

        $context = Context::getInstance();
        $context->initialize($cookies);
        $context->setUserId('user_123');

        $this->assertEquals('user_123', $context->getUserId());
    }

    public function testEnrichPropertiesAddsUrlWhenNotPresent(): void
    {
        $context = Context::getInstance();
        $context->setUrl('https://example.com/page');

        $enriched = $context->enrichProperties([]);

        $this->assertEquals('https://example.com/page', $enriched['url']);
    }

    public function testEnrichPropertiesDoesNotOverrideExistingUrl(): void
    {
        $context = Context::getInstance();
        $context->setUrl('https://example.com/context-url');

        $enriched = $context->enrichProperties(['url' => 'https://custom.com/page']);

        $this->assertEquals('https://custom.com/page', $enriched['url']);
    }

    public function testEnrichPropertiesAddsReferrerWhenNotPresent(): void
    {
        $context = Context::getInstance();
        $context->setReferrer('https://google.com');

        $enriched = $context->enrichProperties([]);

        $this->assertEquals('https://google.com', $enriched['referrer']);
    }

    public function testEnrichPropertiesDoesNotOverrideExistingReferrer(): void
    {
        $context = Context::getInstance();
        $context->setReferrer('https://google.com');

        $enriched = $context->enrichProperties(['referrer' => 'https://custom.com']);

        $this->assertEquals('https://custom.com', $enriched['referrer']);
    }

    public function testEnrichPropertiesPreservesExistingProperties(): void
    {
        $context = Context::getInstance();

        $enriched = $context->enrichProperties([
            'product_id' => 'SKU-123',
            'price' => 49.99,
        ]);

        $this->assertEquals('SKU-123', $enriched['product_id']);
        $this->assertEquals(49.99, $enriched['price']);
    }

    public function testInitializeOnlyRunsOnce(): void
    {
        $initCount = 0;

        // Use existing visitor cookie so we can verify initialize runs once
        $existingVisitorId = str_repeat('b', 64);
        $cookies = new CookieManager([
            CookieManager::VISITOR_COOKIE => $existingVisitorId,
        ], function() use (&$initCount) {
            $initCount++;
            return true;
        });

        $context = Context::getInstance();
        $context->initialize($cookies);
        $firstVisitorId = $context->getVisitorId();

        // Second initialize should be a no-op
        $context->initialize($cookies);

        $this->assertEquals($firstVisitorId, $context->getVisitorId());
        $this->assertEquals($existingVisitorId, $context->getVisitorId());
        // Cookie setter should not be called since visitor already exists
        $this->assertEquals(0, $initCount);
    }
}

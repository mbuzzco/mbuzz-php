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

    public function testInitializeCreatesVisitorIdForNewVisitor(): void
    {
        $setCookies = [];
        $cookies = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = $value;
            return true;
        });

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertTrue($context->isInitialized());
        $this->assertNotNull($context->getVisitorId());
        $this->assertEquals(64, strlen($context->getVisitorId()));
        $this->assertArrayHasKey(CookieManager::VISITOR_COOKIE, $setCookies);
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

    public function testInitializeCreatesSessionIdForNewSession(): void
    {
        $setCookies = [];
        $cookies = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = $value;
            return true;
        });

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertNotNull($context->getSessionId());
        $this->assertEquals(64, strlen($context->getSessionId()));
        $this->assertArrayHasKey(CookieManager::SESSION_COOKIE, $setCookies);
    }

    public function testInitializeUsesExistingSessionId(): void
    {
        $existingSessionId = str_repeat('b', 64);
        $cookies = new CookieManager([
            CookieManager::VISITOR_COOKIE => str_repeat('a', 64),
            CookieManager::SESSION_COOKIE => $existingSessionId,
        ]);

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertEquals($existingSessionId, $context->getSessionId());
    }

    public function testIsNewSessionReturnsTrueForNewSession(): void
    {
        $cookies = new CookieManager([], function() { return true; });

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertTrue($context->isNewSession());
    }

    public function testIsNewSessionReturnsFalseForExistingSession(): void
    {
        $cookies = new CookieManager([
            CookieManager::VISITOR_COOKIE => str_repeat('a', 64),
            CookieManager::SESSION_COOKIE => str_repeat('b', 64),
        ]);

        $context = Context::getInstance();
        $context->initialize($cookies);

        $this->assertFalse($context->isNewSession());
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
        $callCount = 0;
        $cookies = new CookieManager([], function() use (&$callCount) {
            $callCount++;
            return true;
        });

        $context = Context::getInstance();
        $context->initialize($cookies);
        $firstVisitorId = $context->getVisitorId();

        // Second initialize should be a no-op
        $context->initialize($cookies);

        $this->assertEquals($firstVisitorId, $context->getVisitorId());
        // Should have only set cookies twice (visitor + session) on first init
        $this->assertEquals(2, $callCount);
    }
}

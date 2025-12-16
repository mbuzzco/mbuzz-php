<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\CookieManager;
use PHPUnit\Framework\TestCase;

class CookieManagerTest extends TestCase
{
    public function testCookieConstants(): void
    {
        $this->assertEquals('_mbuzz_vid', CookieManager::VISITOR_COOKIE);
        $this->assertEquals('_mbuzz_sid', CookieManager::SESSION_COOKIE);
        $this->assertEquals(63072000, CookieManager::VISITOR_MAX_AGE); // 2 years
        $this->assertEquals(1800, CookieManager::SESSION_MAX_AGE); // 30 minutes
    }

    public function testGetReturnsNullWhenCookieNotSet(): void
    {
        $manager = new CookieManager([]);

        $this->assertNull($manager->get('nonexistent'));
    }

    public function testGetReturnsCookieValue(): void
    {
        $manager = new CookieManager(['test_cookie' => 'test_value']);

        $this->assertEquals('test_value', $manager->get('test_cookie'));
    }

    public function testGetVisitorIdReturnsNullWhenNotSet(): void
    {
        $manager = new CookieManager([]);

        $this->assertNull($manager->getVisitorId());
    }

    public function testGetVisitorIdReturnsCookieValue(): void
    {
        $visitorId = str_repeat('a', 64);
        $manager = new CookieManager([CookieManager::VISITOR_COOKIE => $visitorId]);

        $this->assertEquals($visitorId, $manager->getVisitorId());
    }

    public function testGetSessionIdReturnsNullWhenNotSet(): void
    {
        $manager = new CookieManager([]);

        $this->assertNull($manager->getSessionId());
    }

    public function testGetSessionIdReturnsCookieValue(): void
    {
        $sessionId = str_repeat('b', 64);
        $manager = new CookieManager([CookieManager::SESSION_COOKIE => $sessionId]);

        $this->assertEquals($sessionId, $manager->getSessionId());
    }

    public function testIsNewVisitorReturnsTrueWhenNoCookie(): void
    {
        $manager = new CookieManager([]);

        $this->assertTrue($manager->isNewVisitor());
    }

    public function testIsNewVisitorReturnsFalseWhenCookieExists(): void
    {
        $manager = new CookieManager([CookieManager::VISITOR_COOKIE => 'abc123']);

        $this->assertFalse($manager->isNewVisitor());
    }

    public function testIsNewSessionReturnsTrueWhenNoCookie(): void
    {
        $manager = new CookieManager([]);

        $this->assertTrue($manager->isNewSession());
    }

    public function testIsNewSessionReturnsFalseWhenCookieExists(): void
    {
        $manager = new CookieManager([CookieManager::SESSION_COOKIE => 'xyz789']);

        $this->assertFalse($manager->isNewSession());
    }

    public function testSetVisitorIdStoresCookie(): void
    {
        $setCookies = [];
        $manager = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = ['value' => $value, 'options' => $options];
            return true;
        });

        $visitorId = str_repeat('c', 64);
        $result = $manager->setVisitorId($visitorId);

        $this->assertTrue($result);
        $this->assertArrayHasKey(CookieManager::VISITOR_COOKIE, $setCookies);
        $this->assertEquals($visitorId, $setCookies[CookieManager::VISITOR_COOKIE]['value']);

        $options = $setCookies[CookieManager::VISITOR_COOKIE]['options'];
        $this->assertTrue($options['httponly']);
        $this->assertEquals('Lax', $options['samesite']);
        $this->assertEquals('/', $options['path']);
    }

    public function testSetSessionIdStoresCookie(): void
    {
        $setCookies = [];
        $manager = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = ['value' => $value, 'options' => $options];
            return true;
        });

        $sessionId = str_repeat('d', 64);
        $result = $manager->setSessionId($sessionId);

        $this->assertTrue($result);
        $this->assertArrayHasKey(CookieManager::SESSION_COOKIE, $setCookies);
        $this->assertEquals($sessionId, $setCookies[CookieManager::SESSION_COOKIE]['value']);
    }

    public function testVisitorCookieExpiresIn2Years(): void
    {
        $setCookies = [];
        $manager = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = ['value' => $value, 'options' => $options];
            return true;
        });

        $now = time();
        $manager->setVisitorId('test');

        $expires = $setCookies[CookieManager::VISITOR_COOKIE]['options']['expires'];
        $expectedExpires = $now + CookieManager::VISITOR_MAX_AGE;

        // Allow 1 second tolerance
        $this->assertEqualsWithDelta($expectedExpires, $expires, 1);
    }

    public function testSessionCookieExpiresIn30Minutes(): void
    {
        $setCookies = [];
        $manager = new CookieManager([], function($name, $value, $options) use (&$setCookies) {
            $setCookies[$name] = ['value' => $value, 'options' => $options];
            return true;
        });

        $now = time();
        $manager->setSessionId('test');

        $expires = $setCookies[CookieManager::SESSION_COOKIE]['options']['expires'];
        $expectedExpires = $now + CookieManager::SESSION_MAX_AGE;

        // Allow 1 second tolerance
        $this->assertEqualsWithDelta($expectedExpires, $expires, 1);
    }

    public function testDeleteCookie(): void
    {
        $setCookies = [];
        $manager = new CookieManager(
            [CookieManager::VISITOR_COOKIE => 'existing'],
            function($name, $value, $options) use (&$setCookies) {
                $setCookies[$name] = ['value' => $value, 'options' => $options];
                return true;
            }
        );

        $result = $manager->delete(CookieManager::VISITOR_COOKIE);

        $this->assertTrue($result);
        $this->assertArrayHasKey(CookieManager::VISITOR_COOKIE, $setCookies);
        $this->assertEquals('', $setCookies[CookieManager::VISITOR_COOKIE]['value']);
        $this->assertLessThan(time(), $setCookies[CookieManager::VISITOR_COOKIE]['options']['expires']);
    }
}

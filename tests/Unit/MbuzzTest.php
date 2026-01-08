<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Mbuzz;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MbuzzTest extends TestCase
{
    protected function tearDown(): void
    {
        Mbuzz::reset();
    }

    public function testThrowsWhenNotInitialized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mbuzz::init() must be called before using the SDK');

        Mbuzz::event('page_view');
    }

    public function testInitSucceeds(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        $this->assertNotNull(Mbuzz::getClient());
    }

    public function testEventReturnsFalseWhenDisabled(): void
    {
        Mbuzz::init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $result = Mbuzz::event('page_view');

        $this->assertFalse($result);
    }

    public function testConversionReturnsFalseWhenDisabled(): void
    {
        Mbuzz::init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $result = Mbuzz::conversion('purchase');

        $this->assertFalse($result);
    }

    public function testIdentifyReturnsFalseWhenDisabled(): void
    {
        Mbuzz::init([
            'api_key' => 'sk_test_abc123',
            'enabled' => false,
        ]);

        $result = Mbuzz::identify('user_123');

        $this->assertFalse($result);
    }

    public function testVisitorIdReturnsNullBeforeInitFromRequest(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        $this->assertNull(Mbuzz::visitorId());
    }

    public function testUserIdReturnsNullBeforeIdentify(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        $this->assertNull(Mbuzz::userId());
    }

    public function testResetClearsState(): void
    {
        Mbuzz::init(['api_key' => 'sk_test_abc123']);

        Mbuzz::reset();

        $this->expectException(RuntimeException::class);
        Mbuzz::event('page_view');
    }
}

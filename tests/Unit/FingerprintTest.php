<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\Fingerprint;
use PHPUnit\Framework\TestCase;

class FingerprintTest extends TestCase
{
    public function testRubyParity(): void
    {
        // Must match: Digest::SHA256.hexdigest('127.0.0.1|Mozilla/5.0')[0,32]
        $result = Fingerprint::compute('127.0.0.1', 'Mozilla/5.0');
        $this->assertEquals('ea687534a507e203bdef87cee3cc60c5', $result);
    }

    public function testDeterministic(): void
    {
        $a = Fingerprint::compute('10.0.0.1', 'TestAgent/1.0');
        $b = Fingerprint::compute('10.0.0.1', 'TestAgent/1.0');
        $this->assertEquals($a, $b);
    }

    public function testUniqueForDifferentInputs(): void
    {
        $a = Fingerprint::compute('10.0.0.1', 'Agent-A');
        $b = Fingerprint::compute('10.0.0.2', 'Agent-A');
        $c = Fingerprint::compute('10.0.0.1', 'Agent-B');

        $this->assertNotEquals($a, $b);
        $this->assertNotEquals($a, $c);
        $this->assertNotEquals($b, $c);
    }

    public function testReturns32CharHex(): void
    {
        $result = Fingerprint::compute('192.168.1.1', 'Chrome/120');
        $this->assertEquals(32, strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result);
    }
}

<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\SessionIdGenerator;
use PHPUnit\Framework\TestCase;

final class SessionIdGeneratorTest extends TestCase
{
    private const SAMPLE_VISITOR_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const SAMPLE_TIMESTAMP = 1735500000;
    private const SAMPLE_IP = '203.0.113.42';
    private const SAMPLE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';

    public function testGenerateDeterministicReturns64CharHexString(): void
    {
        $result = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, self::SAMPLE_TIMESTAMP);

        $this->assertSame(64, strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    public function testGenerateDeterministicIsConsistent(): void
    {
        $result1 = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, self::SAMPLE_TIMESTAMP);
        $result2 = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, self::SAMPLE_TIMESTAMP);

        $this->assertSame($result1, $result2);
    }

    public function testGenerateDeterministicSameWithinTimeBucket(): void
    {
        // bucket = timestamp / 1800
        // 1735500000 / 1800 = 964166
        // 1735500599 / 1800 = 964166 (last second of bucket)
        $timestamp1 = 1735500000;
        $timestamp2 = 1735500001;
        $timestamp3 = 1735500599;

        $result1 = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, $timestamp1);
        $result2 = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, $timestamp2);
        $result3 = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, $timestamp3);

        $this->assertSame($result1, $result2);
        $this->assertSame($result1, $result3);
    }

    public function testGenerateDeterministicDifferentAcrossTimeBuckets(): void
    {
        $timestamp1 = 1735500000;
        $timestamp2 = 1735501800; // Next bucket

        $result1 = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, $timestamp1);
        $result2 = SessionIdGenerator::generateDeterministic(self::SAMPLE_VISITOR_ID, $timestamp2);

        $this->assertNotSame($result1, $result2);
    }

    public function testGenerateDeterministicDifferentForDifferentVisitors(): void
    {
        $result1 = SessionIdGenerator::generateDeterministic('visitor_a', self::SAMPLE_TIMESTAMP);
        $result2 = SessionIdGenerator::generateDeterministic('visitor_b', self::SAMPLE_TIMESTAMP);

        $this->assertNotSame($result1, $result2);
    }

    public function testGenerateFromFingerprintReturns64CharHexString(): void
    {
        $result = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            self::SAMPLE_TIMESTAMP
        );

        $this->assertSame(64, strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    public function testGenerateFromFingerprintIsConsistent(): void
    {
        $result1 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            self::SAMPLE_TIMESTAMP
        );
        $result2 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            self::SAMPLE_TIMESTAMP
        );

        $this->assertSame($result1, $result2);
    }

    public function testGenerateFromFingerprintSameWithinTimeBucket(): void
    {
        $timestamp1 = 1735500000;
        $timestamp2 = 1735500001;

        $result1 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            $timestamp1
        );
        $result2 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            $timestamp2
        );

        $this->assertSame($result1, $result2);
    }

    public function testGenerateFromFingerprintDifferentAcrossTimeBuckets(): void
    {
        $timestamp1 = 1735500000;
        $timestamp2 = 1735501800;

        $result1 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            $timestamp1
        );
        $result2 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            $timestamp2
        );

        $this->assertNotSame($result1, $result2);
    }

    public function testGenerateFromFingerprintDifferentForDifferentIps(): void
    {
        $result1 = SessionIdGenerator::generateFromFingerprint(
            '192.168.1.1',
            self::SAMPLE_USER_AGENT,
            self::SAMPLE_TIMESTAMP
        );
        $result2 = SessionIdGenerator::generateFromFingerprint(
            '192.168.1.2',
            self::SAMPLE_USER_AGENT,
            self::SAMPLE_TIMESTAMP
        );

        $this->assertNotSame($result1, $result2);
    }

    public function testGenerateFromFingerprintDifferentForDifferentUserAgents(): void
    {
        $result1 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            'Mozilla/5.0 Chrome',
            self::SAMPLE_TIMESTAMP
        );
        $result2 = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            'Mozilla/5.0 Safari',
            self::SAMPLE_TIMESTAMP
        );

        $this->assertNotSame($result1, $result2);
    }

    public function testGenerateRandomReturns64CharHexString(): void
    {
        $result = SessionIdGenerator::generateRandom();

        $this->assertSame(64, strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    public function testGenerateRandomReturnsUniqueIds(): void
    {
        $result1 = SessionIdGenerator::generateRandom();
        $result2 = SessionIdGenerator::generateRandom();

        $this->assertNotSame($result1, $result2);
    }

    public function testDeterministicAndFingerprintProduceDifferentIds(): void
    {
        $deterministic = SessionIdGenerator::generateDeterministic(
            self::SAMPLE_VISITOR_ID,
            self::SAMPLE_TIMESTAMP
        );
        $fingerprint = SessionIdGenerator::generateFromFingerprint(
            self::SAMPLE_IP,
            self::SAMPLE_USER_AGENT,
            self::SAMPLE_TIMESTAMP
        );

        $this->assertNotSame($deterministic, $fingerprint);
    }
}

<?php

declare(strict_types=1);

namespace Mbuzz;

final class SessionIdGenerator
{
    private const SESSION_TIMEOUT_SECONDS = 1800;
    private const SESSION_ID_LENGTH = 64;
    private const FINGERPRINT_LENGTH = 32;

    /**
     * Generate session ID for returning visitors (have visitor cookie).
     */
    public static function generateDeterministic(
        string $visitorId,
        ?int $timestamp = null
    ): string {
        $timestamp = $timestamp ?? time();
        $timeBucket = intdiv($timestamp, self::SESSION_TIMEOUT_SECONDS);
        $raw = "{$visitorId}_{$timeBucket}";
        return substr(hash('sha256', $raw), 0, self::SESSION_ID_LENGTH);
    }

    /**
     * Generate session ID for new visitors using IP+UA fingerprint.
     */
    public static function generateFromFingerprint(
        string $clientIp,
        string $userAgent,
        ?int $timestamp = null
    ): string {
        $timestamp = $timestamp ?? time();
        $fingerprint = substr(
            hash('sha256', "{$clientIp}|{$userAgent}"),
            0,
            self::FINGERPRINT_LENGTH
        );
        $timeBucket = intdiv($timestamp, self::SESSION_TIMEOUT_SECONDS);
        $raw = "{$fingerprint}_{$timeBucket}";
        return substr(hash('sha256', $raw), 0, self::SESSION_ID_LENGTH);
    }

    /**
     * Generate random session ID (fallback).
     */
    public static function generateRandom(): string
    {
        return bin2hex(random_bytes(32));
    }
}

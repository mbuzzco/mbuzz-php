<?php

declare(strict_types=1);

namespace Mbuzz;

/**
 * Device fingerprint — matches server-side SHA256(ip|user_agent)[0:32]
 */
final class Fingerprint
{
    /**
     * Compute a device fingerprint from IP and User-Agent.
     *
     * Produces a 32-char hex string identical to the server-side computation
     * and the Ruby/Node/Python SDKs.
     */
    public static function compute(string $ip, string $userAgent): string
    {
        return substr(hash('sha256', "{$ip}|{$userAgent}"), 0, 32);
    }
}

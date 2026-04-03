<?php

declare(strict_types=1);

namespace Mbuzz;

/**
 * Navigation detection — only create sessions for real page navigations.
 *
 * Primary signal: Sec-Fetch-* headers (browser-enforced, unforgeable).
 * Fallback: blacklist known sub-request framework headers (old browsers/bots).
 */
final class NavigationDetector
{
    /**
     * Determine whether the current request is a real page navigation
     * that should create a server-side session.
     *
     * @param array<string, string> $server Server vars ($_SERVER)
     */
    public static function shouldCreateSession(array $server = []): bool
    {
        $server = $server ?: $_SERVER;

        $mode = $server['HTTP_SEC_FETCH_MODE'] ?? null;
        $dest = $server['HTTP_SEC_FETCH_DEST'] ?? null;

        if ($mode !== null) {
            return $mode === 'navigate'
                && $dest === 'document'
                && !isset($server['HTTP_SEC_PURPOSE']);
        }

        // Fallback for old browsers / bots: blacklist known sub-requests
        return !isset($server['HTTP_TURBO_FRAME'])
            && !isset($server['HTTP_HX_REQUEST'])
            && !isset($server['HTTP_X_UP_VERSION'])
            && ($server['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest';
    }
}

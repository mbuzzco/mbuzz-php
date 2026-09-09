<?php

declare(strict_types=1);

namespace Mbuzz;

/**
 * A dropped call must say why, instead of nothing at all.
 *
 * Every SDK dropped a hit with no request and no log: from the caller's side a
 * dropped conversion and a delivered one look identical. That silence is why
 * the page-cache bug cost a full day on a live account.
 *
 * Deliberately NOT behind the debug flag — the customers who hit this are
 * precisely the ones not running in debug.
 *
 * @see lib/specs/old/page_cache_attribution_rollout_spec.md Phase 6
 */
final class DroppedCall
{
    private const CACHE_HINT = 'If your pages are served from a full-page cache, call '
        . 'POST /_mbuzz/session from the page — see the README\'s "Full-page caching" section.';

    /**
     * Warn that a call was dropped for having nobody to attribute it to.
     */
    public static function missingIdentity(string $call, string $name): void
    {
        self::warn(sprintf(
            '[mbuzz] dropped %s "%s": no visitor_id and no user_id. %s',
            $call,
            $name,
            self::CACHE_HINT
        ));
    }

    /**
     * Emit through the host's logger where there is one, and stderr otherwise.
     * error_log() is the one sink present in every PHP deployment.
     */
    private static function warn(string $message): void
    {
        error_log($message);
    }
}

<?php

declare(strict_types=1);

namespace Mbuzz;

/**
 * Establishes the visitor from a page the cache served.
 *
 * A cached page never enters the application, so the tracking middleware never
 * runs and no visitor cookie is set — every later event is then rejected for
 * having no one to attribute it to, silently, while the page renders perfectly.
 *
 * This endpoint is the one request on such a page that always reaches the app.
 * A small script on the page POSTs here; the SERVER mints the cookie on the
 * response. The id is never created or read in JS, so it stays HttpOnly and
 * keeps its full two-year life — a cookie written by document.cookie is capped
 * at 7 days under Safari's ITP, and 24 hours after an ad click.
 *
 * Framework-agnostic on purpose: the plain-PHP path and every adapter call the
 * same functions, so there is nothing to keep in sync.
 *
 * @see lib/specs/page_cache_attribution_rollout_spec.md
 */
final class SessionEndpoint
{
    /**
     * The one request that always reaches the app on a cached page. A cache
     * never stores a POST, so this path is the only place the server can
     * still mint.
     */
    public const PATH = '/_mbuzz/session';

    /** Nothing to return: the response exists for its Set-Cookie header. */
    public const NO_CONTENT_STATUS = 204;

    public const NO_STORE = 'no-store, no-cache, must-revalidate, private';

    /**
     * A page response may be stored by a full-page cache and replayed to every
     * visitor, so it must NEVER carry a Set-Cookie for the visitor id: a cached
     * one hands everyone the same id and merges unrelated people into a single
     * journey. That is corruption rather than loss — every row exists, each is
     * simply attributed to the wrong person, and nothing looks missing.
     *
     * Only the session endpoint mints, because no cache stores a POST. This
     * mirrors CookieBootstrap::CONTEXT_PAGE in the WordPress plugin, which got
     * here first, and MINT_ON_PAGE_RESPONSE in the Python SDK.
     */
    public const MINT_ON_PAGE_RESPONSE = false;

    /**
     * Tight upper bound on the session POST, matching Client's page path.
     */
    private const SESSION_POST_TIMEOUT = 2;

    /**
     * POST only: a GET is cacheable by an intermediary, which would
     * reintroduce the very bug this endpoint exists to fix.
     */
    public static function isSessionRequest(string $method, string $path): bool
    {
        return $method === 'POST' && $path === self::PATH;
    }

    /**
     * Build the session payload for a page that called the endpoint.
     *
     * @param array<string, mixed>|null $body Decoded request body, if any
     * @return array<string, mixed>
     */
    public static function buildPayload(
        string $visitorId,
        ?array $body,
        string $ip,
        string $userAgent
    ): array {
        // The customer's body-parser config is not something the fix can
        // depend on.
        $fields = $body ?? [];

        return [
            'session' => [
                'visitor_id' => $visitorId,
                'session_id' => IdGenerator::generateUuid(),
                // The page's URL, not ours — a script on the page called us, so
                // our own path would attribute every session to this endpoint.
                'url' => $fields['url'] ?? null,
                'referrer' => $fields['referrer'] ?? null,
                'device_fingerprint' => Fingerprint::compute($ip, $userAgent),
                'user_agent' => $userAgent,
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    /**
     * Record the session against the page. Api::post() defers to the shutdown
     * phase on FPM/LiteSpeed, so this does not hold up the response.
     *
     * @param array<string, mixed>|null $body
     */
    public static function createSession(
        Api $api,
        string $visitorId,
        ?array $body,
        string $ip,
        string $userAgent
    ): void {
        $api->post(
            '/sessions',
            self::buildPayload($visitorId, $body, $ip, $userAgent),
            self::SESSION_POST_TIMEOUT
        );
    }
}

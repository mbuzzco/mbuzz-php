# Changelog

## 1.1.0 (2026-05-25)

> Versioning note: an orphan `v1.0.0` tag from December 2025 pointed at a much
> earlier "initial release" commit and was the highest stable version on
> Packagist, even though active development continued on the 0.7.x / 0.8.x
> line. Rather than rewrite remote tag history, we're moving forward as 1.x
> from this release on.

### Added

- **Laravel adapter** — `Mbuzz\Adapter\LaravelMiddleware`, duck-typed against the `handle($request, Closure $next)` contract so the SDK stays free of Illuminate imports.
- **PSR-15 adapter** — `Mbuzz\Adapter\Psr15Middleware`, works with Slim, Mezzio, Hyperf, and any PSR-15 compliant framework. Requires `psr/http-server-middleware` (declared under `suggest`).
- **Per-call timeout** on `Api::post()` / `Api::postWithResponse()` — the third positional argument overrides the config default.

### Changed

- **`Api::post()` is now non-blocking on FPM / LiteSpeed.** Calls are queued and flushed in the shutdown phase after `fastcgi_finish_request` / `litespeed_finish_request`, so a slow API server no longer stalls page renders. Falls back to running synchronously in shutdown on CLI / plain CGI. `Api::postWithResponse()` stays synchronous because callers want the response body (event_id, conversion_id, attribution).
- **Session POST capped at 2 seconds** — `Client::createSession()` passes a tight `SESSION_POST_TIMEOUT` as a backstop for environments where the new deferral path isn't available.

### Fixed

- **README** no longer claims a `LaravelMiddleware` and `Mbuzz\Middleware\TrackingMiddleware` that didn't exist; the documented integrations now match the shipped code.

## 0.8.2 (2026-03-15)

### Changed

- **Removed `api_url` from `init()` options** — the proxy URL (`https://api.mbuzz.co/api/v1`) is now hardcoded. This prevents accidental bypass of the edge ingest proxy.

### Fixed

- **`event()` and `conversion()` now handle proxy-buffered responses gracefully** — when the edge proxy accepts a request but Rails is temporarily unreachable, the SDK returns success with nil IDs instead of `false`.

## 0.8.0 (2026-03-13)

### Changed

- **Default API URL updated to `https://api.mbuzz.co/api/v1`** — traffic now routes through the edge ingest proxy for improved reliability.

## 0.7.3 (2026-02-03)

### Added

- **Navigation-aware session creation** — `Client::initFromRequest()` now only creates server-side sessions for real page navigations, filtering out Turbo frames, htmx partials, fetch/XHR, prefetch, and other sub-requests. Uses browser-enforced `Sec-Fetch-*` headers as the primary signal with a framework-specific blacklist fallback for old browsers.
- `NavigationDetector::shouldCreateSession()` — reads `$_SERVER` headers to determine if the request is a real page navigation.
- `Fingerprint::compute()` — computes `SHA256(ip|user_agent)[0:32]`, matching the server-side fingerprint for session deduplication.
- `IdGenerator::generateUuid()` — UUID v4 generation for session IDs.
- Session creation via `POST /sessions` — synchronous call on real navigations when a visitor cookie exists.

### Fixed

- **5x visit count inflation** caused by concurrent sub-requests (Turbo frames, htmx) each creating separate sessions on first page load.

## 0.7.0 (2026-01-15)

- Initial release with cookie management, event tracking, user identification, and conversion tracking.
- Session cookie removed — server handles session resolution via device fingerprint.

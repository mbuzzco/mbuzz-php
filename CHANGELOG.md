# Changelog

## 2.0.0 (2026-09-09)

**Breaking. Every install must add the inline snippet below, or tracking stops entirely.**

### The bug

A full-page cache serves HTML without invoking PHP. The SDK therefore never ran, no visitor cookie
was set, and every later event and conversion was rejected for having nobody to attribute it to —
silently, with no HTTP call and nothing logged, while the page rendered perfectly.

### The fix

An uncached first-party endpoint, `POST /_mbuzz/session`, is now the only place the visitor cookie is
minted. A cache never stores a POST, so it is the one request that always reaches the app. The server
still mints and owns the id, so it stays `HttpOnly` with its full two-year life — a `document.cookie`
fallback would be capped at 7 days by Safari's ITP, and 24 hours after an ad click.

**Add this inline in your `<head>` on every page.** Inline, not an enqueued file: asset optimisers
delay external scripts until the visitor first interacts, so a visitor who lands and converts without
clicking would never be established.

```html
<script>
  fetch('/_mbuzz/session', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ url: location.href, referrer: document.referrer || '' }),
    credentials: 'same-origin',
    keepalive: true
  }).catch(function () {});
</script>
```

### Added

- **`Mbuzz\SessionEndpoint`** — the framework-agnostic core: request matching, payload construction,
  and the session POST. Plain PHP and every adapter call the same functions, so there is nothing to
  keep in sync.
- **`Mbuzz\SessionResponse`** — the endpoint's 204, in Laravel, Symfony or PSR-7 shape as the host
  requires. Duck-typed behind `class_exists()`, so no new hard dependency.
- **`Mbuzz\DroppedCall`** — a dropped event or conversion now says why, naming the call, the reason,
  and the fix. Not gated on `debug`: the customers who hit this are precisely the ones not running in
  debug. This silence is why the bug cost a full day on a live account.
- **`Client::setBodyReader()`** — injectable raw-body source, so tests need no `php://input` stream
  wrapper.

### Changed

- **`Mbuzz::initFromRequest()` now returns `bool`** (was `void`). `true` means the request *was* the
  session endpoint and has been answered — the caller must return rather than render:

  ```php
  if (Mbuzz::initFromRequest()) { return; }
  ```

  The bundled Laravel, Symfony and PSR-15 adapters do this for you; only hand-rolled plain-PHP
  integrations need the line.
- **The session endpoint is checked ahead of `skip_paths` and ahead of the navigation gate**, both
  deliberately. A customer's own `skip_paths` must not swallow the one request that still reaches the
  app on a cached page, and a `fetch()` can never satisfy `sec-fetch-mode: navigate` — leaving that
  gate in front would mint the cookie and then silently skip the session.

### Removed

- **The unreachable minting branch in `Context::initialize()`.** It was guarded by
  `isNewVisitor() && visitorId !== null`, which cannot both hold, so a page response never minted.
  That accident is the only reason this SDK escaped the visitor-collapse defect that hit the Ruby,
  Node and Python SDKs, where a cached `Set-Cookie` handed every visitor the same id and merged
  unrelated people into a single journey. It is now explicit and tested, not accidental. **Do not
  restore it.**

## Unreleased

### Deprecated

- **`identifier` option on `Mbuzz::conversion()`.** Pass the email or external ID as `user_id` instead. The backend `/conversions` endpoint has never permitted this field — Rails strong params strip it — and the events endpoint treats `identifier.email` exactly as `user_id`. The option still serializes into the payload (backwards-compatible — existing callers keep working) but now emits `E_USER_DEPRECATED` once per process. Will be removed in a future major release. Matched by simultaneous deprecation in `mbuzz-python` and `mbuzz-node`.

## 1.2.0 (2026-05-26)

Additive release driven by the upcoming WordPress plugin (`mbuzz-attribution`). No breaking changes.

### Added

- **`Mbuzz::flush()` / `Client::flush()`** — drains the deferred POST queue immediately rather than waiting for the shutdown handler. Needed by long-running workers (WP-CLI imports, queue consumers) where `register_shutdown_function` doesn't fire between jobs.
- **`Mbuzz::validate(?string $apiKey = null)` / `Client::validate(?string $apiKey = null)`** — one-shot probe against `GET /validate`. Pass a candidate key to check it without mutating live config (settings-page validate-on-save); pass null to check the currently-configured key. Bypasses the `enabled` flag — validation is a setup-time check, distinct from tracking.
- **`Mbuzz::onSuccess(callable)` / `Mbuzz::onError(callable)`** (mirrored on `Client` and `Api`) — observer hooks fired for every API response, including async ones that resolve during shutdown. Multiple listeners supported; registration order preserved; a throwing listener is logged and skipped without affecting other listeners or the originating call. Success signature: `fn(string $method, string $url, int $status, ?array $body)`. Error signature adds `?\Throwable $exception` (set when the transport itself raised; null on a non-2xx response).

### Changed

- **`Api::USER_AGENT`** bumped to `mbuzz-php/1.2.0`.

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

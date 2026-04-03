# Changelog

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

# Mbuzz for WordPress — Plugin Specification

**Status:** Draft, rev 2 (2026-05-26)
**Owner:** SDK team
**Depends on:** `mbuzz/mbuzz-php` ≥ 1.2.0 — adds `Client::flush()` for the WP-CLI `flush` command (see §9). 1.1.0 ships async POST dispatch and the Laravel + PSR-15 adapters this plugin builds on.
**Slug:** `mbuzz-attribution`

---

## 1. Goals & Non-Goals

### Goals

1. **Zero-config tracking** for a vanilla WordPress site — install, paste API key, conversions and sessions start flowing.
2. **First-class WooCommerce attribution**: purchases (with revenue), subscription renewals (recurring revenue), refunds.
3. **Identity stitching**: link logged-in WP users to anonymous visitors via `Mbuzz::identify()`.
4. **Form-plugin conversions** out of the box for the top four: Contact Form 7, Gravity Forms, WPForms, Fluent Forms.
5. **WP.org-submittable** (GPL-2.0+, no external network calls beyond the configured API endpoint, no obfuscation, sandbox-safe).
6. **Page-render-neutral**: all API I/O happens after `fastcgi_finish_request` on FPM hosts — the SDK already does this in 1.1.0; the plugin must not reintroduce sync calls.

### Non-Goals

1. **Replacing the JS pixel.** The pixel still loads for client-side hits (scroll depth, time-on-page) where server-side hooks can't see. Plugin's job is server-side parity, not eliminating JS.
2. **A reporting dashboard inside wp-admin.** Reporting lives at app.mbuzz.co. The plugin's admin screen is configuration + diagnostics only.
3. **A visual builder for custom events.** v1 ships hard-coded hooks for the supported plugins; arbitrary event mapping is a v2 concern.
4. **Multisite network-wide config UI** in v1. Per-site config only; network admins set defaults via `wp-config.php` constants.

---

## 2. Architecture

```
wp-content/plugins/mbuzz-attribution/
├── mbuzz-attribution.php         # Plugin bootstrap (metadata, requires PHP/WP)
├── composer.json                 # Pulls mbuzz/mbuzz-php as dependency
├── vendor/                       # Bundled at build time, shipped in zip
├── readme.txt                    # WP.org-format readme
├── uninstall.php                 # Cleans up wp_options
├── src/
│   ├── Plugin.php                # Singleton entrypoint, registers hooks
│   ├── Bootstrap.php             # Reads settings, calls Mbuzz::init()
│   ├── Settings/
│   │   ├── Page.php              # Renders Settings → Mbuzz
│   │   ├── Fields.php            # Sanitization + validation
│   │   └── Diagnostics.php       # "Send test event" button + status
│   ├── Identity/
│   │   ├── LoginHook.php         # wp_login → identify
│   │   └── RegisterHook.php      # user_register → identify + signup conversion
│   ├── Integrations/
│   │   ├── WooCommerce.php
│   │   ├── EasyDigitalDownloads.php
│   │   ├── ContactForm7.php       # Hooks `wpcf7_submit` (not `wpcf7_mail_sent` — see §7)
│   │   ├── GravityForms.php
│   │   ├── WPForms.php
│   │   ├── FluentForms.php
│   │   ├── MemberPress.php
│   │   └── LearnDash.php
│   ├── Cli/
│   │   └── Commands.php          # WP-CLI: send-test, status, flush
│   ├── Privacy/
│   │   ├── Consent.php           # WP Consent API integration
│   │   └── Exporter.php          # wp_privacy_personal_data_exporters
│   └── Block/
│       ├── TrackedButton.php     # Gutenberg block PHP-side registration
│       └── tracked-button/
│           ├── block.json        # One dir per block — WP convention
│           ├── index.js          # Editor script
│           └── view.js           # Front-end pixel hook
└── tests/                        # PHPUnit + WP_UnitTestCase via wp-env
```

### Dependency strategy

- Plugin **bundles** the `mbuzz/mbuzz-php` SDK under `vendor/` via Composer in the build step.
- Namespace-scoped via `php-scoper` to avoid clashes when another plugin ships a different SDK version. Scoped prefix: `Mbuzz\Vendor\` (so `Mbuzz\Vendor\Mbuzz\Mbuzz` is the actual class at runtime, with our own thin facade `Mbuzz\WP\Sdk` keeping the call sites pretty).
- **Theme/snippet escape hatch.** Theme code copy-pasted from the SDK docs calls `\Mbuzz\Mbuzz::event(...)`, which won't exist after scoping. Bootstrap ships two unscoped procedural helpers — `mbuzz_event(string $type, array $props = [])` and `mbuzz_conversion(string $type, array $opts = [])` — that delegate to the scoped class. Documented as the supported surface for theme integrators. (`class_alias` was considered and rejected: aliasing back to the unscoped name re-introduces the very collision risk scoping was meant to prevent.)
- **Cookie is the cross-plugin integration point.** `_mbuzz_vid` is read/written by whichever scoped SDK runs first; subsequent SDK copies (from other plugins) see the same value. Do not rename the cookie in a future scoper config — it's intentionally outside the scoped surface.
- WP requires PHP 7.4+; SDK requires 8.1+. **Plugin requires PHP 8.1+** — declared via the `Requires PHP: 8.1` header in `mbuzz-attribution.php` (WP ≥ 5.1 honors this and refuses activation on older PHP). Belt-and-braces: the activation hook runs `version_compare(PHP_VERSION, '8.1', '<')` and calls `deactivate_plugins()` + sets a transient that renders an admin notice on the next request, since the header check still allows old WP versions to activate.

---

## 3. Bootstrap & Lifecycle

| WP hook | Plugin action |
|---|---|
| `plugins_loaded` (prio 5) | Load autoloader, instantiate `Plugin`, call `Mbuzz::init()` with stored settings. |
| `init` (prio 5) | Register Gutenberg block, REST endpoints, CLI commands. |
| `template_redirect` (prio 1) | Call `Mbuzz::initFromRequest()`. This is the WP-equivalent of "early in the request lifecycle, before output." Skipped on `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`, `REST_REQUEST`, `XMLRPC_REQUEST`, and `is_robots()`. |
| `admin_init` | Register settings page, sanitization callbacks. |
| `admin_enqueue_scripts` | Settings page CSS only — no public JS unless a tracked block is on the page. |

**Why `template_redirect` and not `wp`:** `wp` fires before WP has decided whether the request will 404, get cached by WP Rocket, or be served by `wp_send_json_*`. `template_redirect` is the last hook before output, fires only for front-end page loads, and matches the SDK's "real navigation" expectations.

### Activation / deactivation

- **Activation:** Set default option values; schedule no cron jobs (the SDK is event-driven). Display an admin notice if API key is missing: "Mbuzz is installed but inactive — add your API key in Settings → Mbuzz."
- **Deactivation:** Nothing. Settings persist.
- **Uninstall:** `uninstall.php` deletes the single `mbuzz_attribution_settings` option (one serialized array — all fields from §4 live under this key) plus the `mbuzz_attribution_last_call` and `mbuzz_attribution_php_notice` transients.

---

## 4. Settings UI

Single screen at **Settings → Mbuzz** (`options-general.php?page=mbuzz`).

### Fields

| Setting | Type | Default | Notes |
|---|---|---|---|
| API key | password | — | `sk_live_*` or `sk_test_*`. Validated by hitting `GET /validate` on save. |
| Enable tracking | checkbox | true | Master kill switch; sets `enabled => false` on the SDK. |
| Debug logging | checkbox | false | Pipes SDK `error_log` to `WP_DEBUG_LOG`. |
| Track logged-in admins | checkbox | false | When off, `current_user_can('manage_options')` bypasses tracking. Most sites want this off so internal QA doesn't pollute. |
| Skip paths (textarea, one per line) | text | empty | Merged with SDK defaults (`/wp-admin/`, `/wp-login.php`, `/wp-cron.php` always skipped regardless). |
| Identify users at | radio | "login" | "login" (only when they log in) or "every page" (on every request for logged-in users). |
| WooCommerce: track purchases | checkbox | true | Enables `woocommerce_thankyou`. |
| WooCommerce: include order details in properties | checkbox | true | Sends line items, coupons, payment method. |
| WooCommerce: mark first paid order as acquisition | checkbox | true | First completed order → `is_acquisition: true`. Subsequent → `inherit_acquisition: true`. |

### `wp-config.php` overrides

Any setting can be locked via constants — useful for multisite and managed-host deploys:

```php
define( 'MBUZZ_API_KEY', 'sk_live_...' );
define( 'MBUZZ_ENABLED', true );
define( 'MBUZZ_DEBUG', false );
```

Constants take precedence over DB options and the corresponding setting field is rendered disabled with a "Locked by `wp-config.php`" hint.

### Diagnostics card

Below the form: a card showing
- SDK version, plugin version, PHP version
- "Last successful API call: 2026-05-25 13:42:11 UTC" (stored as a transient, updated by a hook on successful POST)
- "Send test event" button → fires `Mbuzz::event('mbuzz_test', [...])` and shows the response.
- Consent API status: "Detected: WP Consent API v1.0.6" or "Not detected — tracking always on."

---

## 5. Identity Hooks

| WP hook | Action |
|---|---|
| `wp_login` (`$user_login`, `$user`) | `Mbuzz::identify($user->ID, ['email' => $user->user_email, 'name' => $user->display_name, 'role' => $user->roles[0] ?? null])` |
| `user_register` (`$user_id`) | Load user, `identify(...)` + `Mbuzz::conversion('signup', ['user_id' => $user_id, 'is_acquisition' => true])` |
| `profile_update` | Re-`identify` if email changed. |
| `wp_logout` | No-op. SDK has no `reset()` semantics for "log out" — we leave the visitor cookie so attribution survives across login sessions. Trade-off: on a shared machine, the next person to use the browser inherits the previous user's anonymous visitor id until they log in (at which point `identify` rebinds it). Right call for the typical solo-laptop case; explicit so a privacy reviewer doesn't have to ask. |

**Setting "Identify users at: every page":** adds a `template_redirect` callback that re-identifies the current user on every page load. Useful for sites where the WP login session can outlive the visitor cookie or where the visitor cookie can be rotated by privacy tools.

---

## 6. WooCommerce Integration

The single most-requested integration. Hooks:

| Hook | Mapped to |
|---|---|
| `woocommerce_thankyou` (`$order_id`) | `conversion('purchase', [...])` — see below |
| `woocommerce_order_status_processing` | Fires for orders that reach paid state on a gateway that stops at `processing` (Stripe + digital goods is the common case). Deduped via order meta `_mbuzz_conversion_id`. |
| `woocommerce_order_status_completed` | Fallback for orders that skip the thank-you page (renewals, admin-marked) and never went through `processing`. Same dedupe. |
| `woocommerce_order_refunded` | `conversion('refund', ['revenue' => -$refund_total, 'properties' => ['original_order_id' => $order_id]])` |
| `woocommerce_subscription_renewal_payment_complete` (WooCommerce Subscriptions) | `conversion('payment', ['user_id' => $user_id, 'revenue' => $renewal_total, 'inherit_acquisition' => true])` |
| `woocommerce_checkout_order_processed` | No conversion fired here — that hook runs before payment confirms; would double-count. |

### Purchase conversion payload

```php
Mbuzz::conversion('purchase', [
    'user_id'             => $order->get_user_id() ?: null,
    'revenue'             => (float) $order->get_total(),
    'currency'            => $order->get_currency(),
    'is_acquisition'      => $is_first_paid_order_for_user,
    'inherit_acquisition' => ! $is_first_paid_order_for_user,
    'identifier'          => [
        'email' => $order->get_billing_email(),
    ],
    'properties'          => [
        'order_id'       => $order->get_id(),
        'order_number'   => $order->get_order_number(),
        'payment_method' => $order->get_payment_method(),
        'coupons'        => $order->get_coupon_codes(),
        'item_count'     => $order->get_item_count(),
        'line_items'     => array_map(fn($item) => [
            'product_id' => $item->get_product_id(),
            'sku'        => $item->get_product()?->get_sku(),
            'quantity'   => $item->get_quantity(),
            'subtotal'   => (float) $item->get_subtotal(),
        ], $order->get_items()),
    ],
]);
```

The order is marked with the returned conversion id to enable dedupe + audit. Storage call must be HPOS-safe: `$order->update_meta_data('_mbuzz_conversion_id', $result['conversion_id']); $order->save();` rather than `update_post_meta()`, which is a no-op against the HPOS orders table.

**Counting rule.** The first of `woocommerce_thankyou`, `woocommerce_order_status_processing`, or `woocommerce_order_status_completed` to fire for a given order wins; the order meta dedupe keys the rest out. This handles three real cases: (a) standard checkout — thankyou fires first; (b) digital goods on Stripe that never leave `processing` — processing fires; (c) admin-marked or renewal orders that skip the customer-facing pages — completed fires.

### Guest checkouts

When `$order->get_user_id() === 0`, we send `identifier.email` so the backend can stitch on email if/when the user later logs in or registers.

### First-paid-order detection

```php
$is_first = wc_get_orders([
    'customer_id' => $user_id,
    'status'      => ['completed', 'processing'],
    'exclude'     => [$order_id],
    'return'      => 'ids',
    'limit'       => 1,
]) === [];
```

Cached per request to avoid repeat queries.

---

## 7. Other Integrations (v1 scope)

All optional — the integration class checks if the host plugin is active (`class_exists`, `function_exists`) before wiring hooks.

| Plugin | Hook | Conversion |
|---|---|---|
| **Easy Digital Downloads** | `edd_complete_purchase` | `purchase` with `revenue`, `payment_id`, downloads |
| **Contact Form 7** | `wpcf7_submit` (gated on `$result['status'] ∈ {'mail_sent', 'demo_mode'}`) | `lead` with form ID + form title in properties. Why not `wpcf7_mail_sent`: forms configured for webhook/CRM-only delivery skip email and never fire that hook. |
| **Gravity Forms** | `gform_after_submission` | `lead` with form ID; `identifier.email` if an email field exists |
| **WPForms** | `wpforms_process_complete` | `lead` |
| **Fluent Forms** | `fluentform/submission_inserted` | `lead` |
| **MemberPress** | `mepr-event-transaction-completed` | `purchase` with revenue + membership type |
| **LearnDash** | `learndash_course_completed` | `event('course_completed', ...)` (not a conversion — counts as engagement) |

Each integration ships its own filter for users to override the conversion type or skip:

```php
add_filter('mbuzz_woocommerce_conversion_type', fn() => 'sale'); // rename from "purchase"
add_filter('mbuzz_woocommerce_skip_order', fn($skip, $order) => $order->get_total() < 1, 10, 2);
```

---

## 8. Gutenberg Block: "Tracked Button"

A single block (`mbuzz/tracked-button`) that renders a button and fires `Mbuzz::event()` (via the JS pixel — server can't see clicks). Block attributes:

- `label` (string)
- `url` (string)
- `event_type` (string, default `cta_click`)
- `properties` (key-value editor)
- `is_conversion` (bool — switches to `conversion()` call)

The block exists primarily so non-developers can wire CTAs to the funnel without copy-pasting JS. Saved markup is a `<a data-mbuzz-event="…" data-mbuzz-properties="…">` and the bundled pixel JS reads the attributes on click.

---

## 9. WP-CLI Commands

```
wp mbuzz status        # API key set? Last successful call? SDK version?
wp mbuzz test          # Fire a test event, print full response.
wp mbuzz flush         # Force-flush the SDK deferred queue (useful in long-running wp-cli scripts).
wp mbuzz identify <user_id> [--traits='{"plan":"pro"}']
wp mbuzz conversion <type> [--user_id=…] [--revenue=…] [--properties='{}']
```

All commands respect `--dry-run` and `--debug`.

**`flush` depends on a new SDK method.** The SDK 1.1.0 deferred queue is private (`Api::flushDeferred()` is internal, registered on `register_shutdown_function`). `wp mbuzz flush` requires a public `Client::flush()` in SDK 1.2.0 that proxies to it; ship that first or drop the command from v1 scope. WP-CLI is the one place this matters — under FPM the shutdown handler runs anyway, but CLI processes don't go through shutdown until the whole script exits, so long-running migration/import scripts accumulate the queue unbounded.

---

## 10. Multisite

- Plugin can be **network-activated** or per-site.
- Settings are **per-site** (in `wp_options`, not `wp_sitemeta`). Network admin defaults live in `wp-config.php` constants.
- On `switch_blog`: call `Mbuzz::reset()` **and then immediately `Mbuzz::init()` again** with the freshly-resolved site's settings. `reset()` alone nulls the static client and the next SDK call throws `RuntimeException` from `Mbuzz::ensureInitialized()`. The `Bootstrap` class exposes a `boot(array $settings): void` method so `switch_blog` handlers can call `Bootstrap::boot(Settings::current())` without re-implementing the init pipeline. Same flow runs on `restore_current_blog`.

---

## 11. Privacy & Consent

### WP Consent API

If [`WP Consent Level API`](https://wordpress.org/plugins/wp-consent-api/) is active:

- Plugin **registers** as a consent-aware plugin with category `statistics`.
- **Gating happens at call-site, not at init.** Consent plugins resolve their state asynchronously (often after `plugins_loaded`, sometimes only after `wp` once the page knows whether it's a cached page, a REST request, etc.). Init-time `enabled => false` would either be wrong half the time or force us to defer init past `plugins_loaded`. Instead: `Mbuzz::init()` runs normally; every tracking hook this plugin registers wraps its `Mbuzz::event()` / `conversion()` / `identify()` call in `if (! Consent::allows()) return;`, where `Consent::allows()` returns `true` when the Consent API is absent and `wp_has_consent('statistics')` when it's present.
- No cookie is set without consent — `_mbuzz_vid` writes are gated through the same `Consent::allows()` check before `Mbuzz::initFromRequest()` runs.

When the Consent API is not installed: tracking runs unconditionally (matches the SDK's default and current behavior).

### Persisted personal data

What the plugin actually stores per data subject:

- **Order meta** (HPOS-safe): `_mbuzz_conversion_id` on each WooCommerce/EDD order, recording the conversion id returned by the backend. Indirect link to the user via `$order->get_user_id()` (or billing email for guest checkouts).
- **User meta:** `_mbuzz_last_identified_at` (timestamp), `_mbuzz_last_conversion_id` (most recent). Written by the identify hooks (§5) and the WC/EDD conversion hooks (§6, §7). Kept deliberately small — anything more belongs in the backend, not in `wp_usermeta`.

### Personal data exporter

Registers a `wp_privacy_personal_data_exporters` callback that exports the `_mbuzz_*` user meta listed above **plus** the `_mbuzz_conversion_id` order meta for every order owned by (or with billing email matching) the data subject.

### Personal data eraser

Registers a `wp_privacy_personal_data_erasers` callback that deletes the same `_mbuzz_*` user meta and order meta, then pings the backend with a `forget` request (backend endpoint TBD — out of scope for v1 if backend isn't ready; until then the eraser deletes local meta and reports the backend step as "retained" per WP eraser contract).

---

## 12. Performance

### Required posture

- **Zero added latency on the rendered page.** All API I/O happens in the shutdown phase. The plugin must NOT call `Mbuzz::event()` / `conversion()` during render-blocking hooks like `wp_head` or `the_content`.
- **No polling, no cron.** The plugin schedules zero recurring jobs.
- **Query budget:** ≤ 1 additional query per page load, only on logged-in pages (identify trait lookup). WooCommerce conversions add 1–2 queries on the thank-you page only.
- **Object cache friendly:** all option reads go through `get_option` (which is cached) and we cache the first-paid-order check per request.

### Self-imposed guardrails (CI)

A bundled `tests/performance.php` runs WP-Cli with `--profile=hook` against a fresh install + the plugin + WooCommerce, and fails the build if `template_redirect`-onward adds > 5ms median over 100 runs.

---

## 13. Security

- All admin form submissions are nonce-protected (`mbuzz_settings_nonce`).
- API key stored in `wp_options` with `autoload=no`, never echoed back to the page (password field with placeholder = "••••••" when set).
- API key validation request uses the bearer header and never logs the key.
- All output escaped with `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` as appropriate.
- No `eval`, no `unserialize` of user input, no remote URL fetches except the configured API endpoint.
- `uninstall.php` checks `WP_UNINSTALL_PLUGIN` constant before running.

---

## 14. Versioning, Releases & Updates

### Distribution

1. **WP.org repository** as the primary channel — free, GPL-2.0+.
2. **GitHub releases** as a mirror for developers who want to pin commits.
3. **No premium tier in v1.** If a premium tier ships later, it lives on a separate domain and uses the EDD Software Licensing pattern for updates (does not pollute the WP.org plugin).

### Versioning

Semver. The plugin version is independent of the SDK version it bundles; both are shown on the diagnostics card.

### Update mechanics

- WP.org plugin updater handles updates automatically.
- Each release bumps the `Stable tag` in `readme.txt` and the `Version` in `mbuzz-attribution.php`. Both are enforced by a build script.

### Compat windows

- **WordPress:** current latest minus 2 minor (e.g. if WP 6.7 is current, we support 6.5+).
- **WooCommerce:** current latest minus 2 minor.
- **PHP:** 8.1+ (matches SDK).

---

## 15. Testing

| Layer | Tool | What |
|---|---|---|
| Unit | PHPUnit + Brain Monkey | Pure-PHP classes (Settings, Identity, Integrations) with WP functions mocked. |
| Integration | `wp-env` + `wp-phpunit` | Full WP install, activates WC, fires the real hooks, asserts on the captured SDK transport. |
| E2E | Playwright | Single happy-path: install plugin, set key, place an order, see the conversion on a captured API receiver. |
| Manual QA matrix | — | WP 6.5 / 6.6 / 6.7 × PHP 8.1 / 8.2 / 8.3 × WC 8.x / 9.x. |

The SDK's `setTransport()` is the seam — integration tests configure it once in their bootstrap and assert against captured calls.

---

## 16. Roll-out Plan

| Phase | Scope | Gate |
|---|---|---|
| **0.1.0 alpha** | Core + WC + CF7. Internal dogfood on 1 store. | "It tracks a purchase end-to-end without breaking checkout." |
| **0.2.0 beta** | + EDD, Gravity, WPForms, Fluent, MemberPress, LearnDash. WP-CLI. Privacy. | 5 external beta sites running for 2 weeks with no support tickets. |
| **1.0.0** | WP.org submission. Block. Documentation site. | WP.org approval; SDK 1.1.0 published; docs published. |
| **1.1+** | Backlog: WP REST endpoints for SPA themes, BuddyPress events, custom event mapper UI. | Customer-driven. |

---

## 17. Open Questions

1. **Should the plugin ship its own JS pixel** (bundled, no CDN dependency) or load `pixel.mbuzz.co/p.js`? Bundling is more WP.org-friendly; loading from CDN gives instant fixes. Recommend: bundle, with the CDN URL as a hidden override constant for fast hotfix.
2. **WP Multisite shared API key:** some networks will want one key for the whole network. Resolved by `wp-config.php` constants for v1; revisit a network-admin UI in v1.1 if there's demand.
3. **Pixel + server-side dedupe:** if both the JS pixel and the server-side `purchase` hook fire, backend needs to dedupe by `properties.order_id`. Backend ticket required before GA.

Resolved upstream and folded into the spec:
- WooCommerce HPOS — §6 now specifies `$order->update_meta_data()` + `save()`.
- Order status counting (`processing` vs. `completed`) — §6 "Counting rule" spells out the first-wins behavior.

---

## 18. Out of Scope (Explicit)

- Per-product conversion tracking (separate from order-level purchase) — backlog.
- Cart abandonment tracking — needs a cron + email infra we don't want to own.
- Multi-currency normalization — backend handles it.
- A/B testing or feature-flag integration.
- Custom dashboards inside wp-admin (reporting stays at app.mbuzz.co).

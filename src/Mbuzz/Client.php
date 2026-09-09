<?php
// NOTE: Session handling removed in 0.7.0 - server handles session resolution

declare(strict_types=1);

namespace Mbuzz;

use Mbuzz\Request\TrackRequest;
use Mbuzz\Request\IdentifyRequest;
use Mbuzz\Request\ConversionRequest;

final class Client
{
    private Config $config;
    private Api $api;
    private CookieManager $cookies;
    private Context $context;

    public function __construct(Config $config, ?CookieManager $cookies = null)
    {
        $this->config = $config;
        $this->api = new Api($config);
        $this->cookies = $cookies ?? new CookieManager();
        $this->context = Context::getInstance();
    }

    /**
     * Set custom transport for API (for testing)
     */
    public function setTransport(callable $transport): void
    {
        $this->api->setTransport($transport);
    }

    /**
     * Register a 2xx-response listener. See Api::onSuccess() for the signature.
     */
    public function onSuccess(callable $listener): void
    {
        $this->api->onSuccess($listener);
    }

    /**
     * Register a non-2xx / exception listener. See Api::onError() for the signature.
     */
    public function onError(callable $listener): void
    {
        $this->api->onError($listener);
    }

    /**
     * Drain the deferred POST queue immediately rather than waiting for
     * the shutdown handler. Intended for long-running workers (WP-CLI
     * imports, queue consumers) where shutdown doesn't fire between jobs.
     */
    public function flush(): void
    {
        $this->api->flushDeferred();
    }

    /**
     * Validate an API key against GET /validate.
     *
     * @param string|null $apiKey  Key to validate. Null = use the currently-configured key.
     * @return array<string, mixed>|false  Response body on success, false on failure.
     */
    public function validate(?string $apiKey = null): array|false
    {
        $effectiveKey = $apiKey ?? $this->config->getApiKey();
        if ($effectiveKey === '') {
            return false;
        }

        return $this->api->probeValidate($effectiveKey);
    }

    /**
     * Initialize context from request (cookies) and create session if navigation.
     *
     * Returns true when this request WAS the session endpoint and has been
     * answered — the caller must then stop and send the response, because a
     * Set-Cookie has been emitted and there is nothing further to render.
     */
    public function initFromRequest(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Checked ahead of the skip-path check and the navigation gate below,
        // both deliberately. A customer's own skip_paths must not swallow the
        // one request that still reaches the app on a cached page, and a
        // fetch() can never satisfy sec-fetch-mode: navigate — leaving that
        // gate in front would mint the cookie and then silently skip the
        // session. Settled across Node and Python; not a per-SDK judgement.
        if (SessionEndpoint::isSessionRequest($method, $path)) {
            $this->handleSessionRequest();
            return true;
        }

        // Skip tracking paths
        if ($this->config->shouldSkipPath($path)) {
            return false;
        }

        // Only a cookie the browser already holds. This response may be stored
        // by a full-page cache and replayed to everyone, so minting here would
        // hand every later visitor the same id — see
        // SessionEndpoint::MINT_ON_PAGE_RESPONSE. A first-time visitor is
        // established a moment later by the session endpoint, whose response
        // no cache stores.
        $this->context->initialize($this->cookies);

        // Create session for real page navigations (when visitor exists)
        if ($this->context->getVisitorId() !== null && NavigationDetector::shouldCreateSession()) {
            $this->createSession();
        }

        return false;
    }

    /**
     * Answer the session request: mint the cookie, record the session against
     * the page, and send an empty, uncacheable 204.
     */
    private function handleSessionRequest(): void
    {
        $visitorId = $this->cookies->getVisitorId() ?? IdGenerator::generate();

        $this->cookies->setVisitorId($visitorId);

        $ip = $this->requestIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        SessionEndpoint::createSession(
            $this->api,
            $visitorId,
            $this->decodeSessionBody(),
            $ip,
            $userAgent
        );

        $this->sendSessionResponse();
    }

    /**
     * The endpoint's response: no body, one Set-Cookie, never cacheable.
     * The cookie itself is written by CookieManager, which owns the attributes.
     */
    private function sendSessionResponse(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(SessionEndpoint::NO_CONTENT_STATUS);
        header('Cache-Control: ' . SessionEndpoint::NO_STORE);
    }

    /**
     * Where the request body is read from. Overridable so tests need no
     * php://input stream wrapper.
     *
     * @var callable():(string|false)|null
     */
    private $bodyReader = null;

    /**
     * Set the raw request-body source (for testing).
     *
     * @param callable():(string|false) $reader
     */
    public function setBodyReader(callable $reader): void
    {
        $this->bodyReader = $reader;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeSessionBody(): ?array
    {
        $reader = $this->bodyReader ?? static fn () => file_get_contents('php://input');
        $raw = $reader();

        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function requestIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($forwarded !== null) {
            return trim(explode(',', $forwarded)[0]);
        }

        return $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Tight upper bound on the session POST. Api::post() defers to the
     * shutdown phase on FPM/LiteSpeed so this only matters when deferral
     * isn't available (CLI, plain CGI) — there it caps the worker stall.
     */
    private const SESSION_POST_TIMEOUT = 2;

    /**
     * Create a server-side session via POST /sessions.
     */
    private function createSession(): void
    {
        $ip = $this->context->getClientIp() ?? 'unknown';
        $userAgent = $this->context->getUserAgent() ?? 'unknown';

        $payload = [
            'session' => [
                'visitor_id' => $this->context->getVisitorId(),
                'session_id' => IdGenerator::generateUuid(),
                'url' => $this->context->getUrl(),
                'referrer' => $this->context->getReferrer(),
                'device_fingerprint' => Fingerprint::compute($ip, $userAgent),
                'user_agent' => $userAgent,
                'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];

        $this->api->post('/sessions', $payload, self::SESSION_POST_TIMEOUT);
    }

    /**
     * Track an event
     *
     * @param array<string, mixed> $properties
     * @param string|null $visitorId Explicit visitor ID (required for background jobs)
     * @return array{success: bool, event_id: ?string, event_type: string, visitor_id: ?string}|false
     */
    public function track(string $eventType, array $properties = [], ?string $visitorId = null): array|false
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        // Auto-initialize context if not done
        if (!$this->context->isInitialized()) {
            $this->context->initialize($this->cookies);
        }

        // Resolve visitor_id: explicit param takes precedence over context
        $resolvedVisitorId = $visitorId ?? $this->context->getVisitorId();
        $resolvedUserId = $this->context->getUserId();

        // Must have at least one identifier. This is the OUTERMOST guard for
        // an event — Mbuzz::event() only delegates — so the warning belongs
        // here, not at the layer nearest the HTTP call.
        if ($resolvedVisitorId === null && $resolvedUserId === null) {
            DroppedCall::missingIdentity('event', $eventType);
            return false;
        }

        $request = new TrackRequest(
            eventType: $eventType,
            visitorId: $resolvedVisitorId,
            userId: $resolvedUserId,
            properties: $this->context->enrichProperties($properties),
            ip: $this->context->getClientIp(),
            userAgent: $this->context->getUserAgent(),
        );

        return $request->send($this->api);
    }

    /**
     * Track a conversion
     *
     * @param array{
     *   visitor_id?: string,
     *   user_id?: string,
     *   event_id?: string,
     *   revenue?: float,
     *   currency?: string,
     *   is_acquisition?: bool,
     *   inherit_acquisition?: bool,
     *   properties?: array<string, mixed>,
     *   ip?: string,
     *   user_agent?: string,
     *   identifier?: array<string, string>
     * } $options
     * @return array{success: bool, conversion_id: ?string, attribution: mixed}|false
     */
    public function conversion(string $conversionType, array $options = []): array|false
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        // Auto-initialize context if not done
        if (!$this->context->isInitialized()) {
            $this->context->initialize($this->cookies);
        }

        // Resolve identifiers: explicit params take precedence over context
        $resolvedVisitorId = $options['visitor_id'] ?? $this->context->getVisitorId();
        $resolvedUserId = $options['user_id'] ?? $this->context->getUserId();

        // Must have at least one identifier (visitor_id or user_id). Outermost
        // guard for a conversion — see the note in track().
        if ($resolvedVisitorId === null && $resolvedUserId === null) {
            DroppedCall::missingIdentity('conversion', $conversionType);
            return false;
        }

        $request = new ConversionRequest(
            conversionType: $conversionType,
            visitorId: $resolvedVisitorId,
            userId: $resolvedUserId,
            eventId: $options['event_id'] ?? null,
            revenue: $options['revenue'] ?? null,
            currency: $options['currency'] ?? 'USD',
            isAcquisition: $options['is_acquisition'] ?? false,
            inheritAcquisition: $options['inherit_acquisition'] ?? false,
            properties: $options['properties'] ?? [],
            ip: $options['ip'] ?? $this->context->getClientIp(),
            userAgent: $options['user_agent'] ?? $this->context->getUserAgent(),
            identifier: $options['identifier'] ?? null,
        );

        return $request->send($this->api);
    }

    /**
     * Identify a user
     *
     * @param array<string, mixed> $traits
     */
    public function identify(string $userId, array $traits = []): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        // Auto-initialize context if not done
        if (!$this->context->isInitialized()) {
            $this->context->initialize($this->cookies);
        }

        // Store user ID in context
        $this->context->setUserId($userId);

        $request = new IdentifyRequest(
            userId: $userId,
            visitorId: $this->context->getVisitorId(),
            traits: $traits,
        );

        return $request->send($this->api);
    }
}

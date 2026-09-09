<?php
// NOTE: Session handling removed in 0.7.0 - server handles session resolution

declare(strict_types=1);

namespace Mbuzz;

final class Mbuzz
{
    private static ?Client $client = null;

    /**
     * Prevent instantiation
     */
    private function __construct()
    {
    }

    /**
     * Initialize the SDK
     *
     * @param array{
     *   api_key: string,
     *   enabled?: bool,
     *   debug?: bool,
     *   timeout?: int,
     *   skip_paths?: array<string>,
     *   skip_extensions?: array<string>
     * } $options Configuration options
     */
    public static function init(array $options): void
    {
        $config = Config::getInstance();
        $config->init($options);

        self::$client = new Client($config);
    }

    /**
     * Initialize context from request (call early in request lifecycle).
     * This handles cookie reading/writing.
     *
     * Returns true when the request WAS POST /_mbuzz/session and has already
     * been answered — the visitor cookie is set and a 204 is on its way. The
     * caller must return immediately rather than render a page:
     *
     *   if (Mbuzz::initFromRequest()) { return; }
     *
     * The bundled adapters do this for you.
     */
    public static function initFromRequest(): bool
    {
        self::ensureInitialized();
        return self::$client->initFromRequest();
    }

    /**
     * Track an event
     *
     * @param string $eventType Event name (e.g., 'page_view', 'add_to_cart')
     * @param array<string, mixed> $properties Custom event properties
     * @param string|null $visitorId Explicit visitor ID (required for background jobs)
     * @return array{success: bool, event_id: ?string, event_type: string, visitor_id: ?string}|false
     *
     * @example Normal usage (within request context):
     *   Mbuzz::event('add_to_cart', ['product_id' => 'SKU-123']);
     *
     * @example Background job (must pass explicit visitor_id):
     *   Mbuzz::event('order_processed', ['order_id' => $order->id], $order->mbuzz_visitor_id);
     */
    public static function event(string $eventType, array $properties = [], ?string $visitorId = null): array|false
    {
        self::ensureInitialized();
        return self::$client->track($eventType, $properties, $visitorId);
    }

    /**
     * Track a conversion
     *
     * @param string $conversionType Conversion name (e.g., 'purchase', 'signup')
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
     * } $options Conversion options
     * @return array{success: bool, conversion_id: ?string, attribution: mixed}|false
     */
    public static function conversion(string $conversionType, array $options = []): array|false
    {
        self::ensureInitialized();
        return self::$client->conversion($conversionType, $options);
    }

    /**
     * Identify a user (link visitor to known user)
     *
     * @param string|int $userId Your application's user ID
     * @param array<string, mixed> $traits User attributes (email, name, plan, etc.)
     */
    public static function identify(string|int $userId, array $traits = []): bool
    {
        self::ensureInitialized();
        return self::$client->identify((string) $userId, $traits);
    }

    /**
     * Get current visitor ID
     */
    public static function visitorId(): ?string
    {
        $context = Context::getInstance();
        return $context->isInitialized() ? $context->getVisitorId() : null;
    }

    /**
     * Get current user ID (if set via identify)
     */
    public static function userId(): ?string
    {
        $context = Context::getInstance();
        return $context->isInitialized() ? $context->getUserId() : null;
    }

    /**
     * Drain the deferred POST queue immediately.
     *
     * Intended for long-running workers (WP-CLI imports, queue consumers)
     * where the shutdown handler doesn't fire between jobs. Safe to call
     * repeatedly — the queue is consumed on each call.
     */
    public static function flush(): void
    {
        self::ensureInitialized();
        self::$client->flush();
    }

    /**
     * Validate an API key against the backend.
     *
     * @param string|null $apiKey  Candidate key to validate. Null = validate the
     *                             currently-configured key. Either way, live
     *                             config is not mutated.
     * @return array<string, mixed>|false  Backend response body on 2xx, false otherwise.
     */
    public static function validate(?string $apiKey = null): array|false
    {
        self::ensureInitialized();
        return self::$client->validate($apiKey);
    }

    /**
     * Register a 2xx-response listener. Fires for immediate and deferred POSTs.
     *
     * @param callable $listener function(string $method, string $url, int $status, ?array $body): void
     */
    public static function onSuccess(callable $listener): void
    {
        self::ensureInitialized();
        self::$client->onSuccess($listener);
    }

    /**
     * Register a non-2xx / transport-exception listener.
     *
     * @param callable $listener function(string $method, string $url, int $status, ?array $body, ?\Throwable $exception): void
     */
    public static function onError(callable $listener): void
    {
        self::ensureInitialized();
        self::$client->onError($listener);
    }

    /**
     * Reset SDK state (for testing or request cleanup in long-running processes)
     */
    public static function reset(): void
    {
        Config::reset();
        Context::reset();
        self::$client = null;
    }

    /**
     * Get the underlying client (for advanced usage)
     */
    public static function getClient(): ?Client
    {
        return self::$client;
    }

    private static function ensureInitialized(): void
    {
        if (self::$client === null) {
            throw new \RuntimeException('Mbuzz::init() must be called before using the SDK');
        }
    }
}

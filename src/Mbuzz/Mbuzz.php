<?php

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
     *   api_url?: string,
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
     * Initialize context from request (call early in request lifecycle)
     * This handles cookie reading/writing and session creation.
     */
    public static function initFromRequest(): void
    {
        self::ensureInitialized();
        self::$client->initFromRequest();
    }

    /**
     * Track an event
     *
     * @param string $eventType Event name (e.g., 'page_view', 'add_to_cart')
     * @param array<string, mixed> $properties Custom event properties
     * @return array{success: bool, event_id: ?string, event_type: string, visitor_id: ?string, session_id: ?string}|false
     */
    public static function event(string $eventType, array $properties = []): array|false
    {
        self::ensureInitialized();
        return self::$client->track($eventType, $properties);
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
     *   properties?: array<string, mixed>
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
     * Get current session ID
     */
    public static function sessionId(): ?string
    {
        $context = Context::getInstance();
        return $context->isInitialized() ? $context->getSessionId() : null;
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

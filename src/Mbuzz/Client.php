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
     * Initialize context from request (cookies)
     */
    public function initFromRequest(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Skip tracking paths
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        if ($this->config->shouldSkipPath($path)) {
            return;
        }

        // Initialize context from cookies
        $this->context->initialize($this->cookies);
    }

    /**
     * Track an event
     *
     * @param array<string, mixed> $properties
     * @return array{success: bool, event_id: ?string, event_type: string, visitor_id: ?string}|false
     */
    public function track(string $eventType, array $properties = []): array|false
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        // Auto-initialize context if not done
        if (!$this->context->isInitialized()) {
            $this->context->initialize($this->cookies);
        }

        $request = new TrackRequest(
            eventType: $eventType,
            visitorId: $this->context->getVisitorId(),
            userId: $this->context->getUserId(),
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

        $request = new ConversionRequest(
            conversionType: $conversionType,
            visitorId: $options['visitor_id'] ?? $this->context->getVisitorId(),
            userId: $options['user_id'] ?? $this->context->getUserId(),
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

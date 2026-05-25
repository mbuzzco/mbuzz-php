<?php
// NOTE: Added ip/userAgent/identifier support in 0.7.0

declare(strict_types=1);

namespace Mbuzz\Request;

use Mbuzz\Api;

final class ConversionRequest
{
    /**
     * @param array<string, string>|null $identifier  DEPRECATED — pass the email
     *   or external ID as `$userId` instead. The backend /conversions endpoint
     *   has never permitted this field and the events endpoint treats
     *   `identifier.email` exactly as `user_id`. Will be removed in a future
     *   major release.
     */
    public function __construct(
        private string $conversionType,
        private ?string $visitorId = null,
        private ?string $userId = null,
        private ?string $eventId = null,
        private ?float $revenue = null,
        private string $currency = 'USD',
        private bool $isAcquisition = false,
        private bool $inheritAcquisition = false,
        /** @var array<string, mixed> */
        private array $properties = [],
        private ?string $ip = null,
        private ?string $userAgent = null,
        /** @var array<string, string>|null */
        private ?array $identifier = null,
    ) {
        if ($this->identifier !== null) {
            // One-shot per process — matches the Python/Node patterns.
            self::warnIdentifierDeprecatedOnce();
        }
    }

    private static bool $identifierDeprecationWarned = false;

    private static function warnIdentifierDeprecatedOnce(): void
    {
        if (self::$identifierDeprecationWarned) {
            return;
        }
        self::$identifierDeprecationWarned = true;
        @trigger_error(
            'The `identifier` option on Mbuzz::conversion() is deprecated and ignored by the backend on /conversions. Pass the email or external ID as `user_id` instead.',
            E_USER_DEPRECATED
        );
    }

    /**
     * @return array{success: bool, conversion_id: ?string, attribution: mixed}|false
     */
    public function send(Api $api): array|false
    {
        if (empty($this->conversionType)) {
            return false;
        }

        // Must have at least one identifier
        if ($this->visitorId === null && $this->userId === null && $this->eventId === null) {
            return false;
        }

        $payload = [
            'conversion' => [
                'conversion_type' => $this->conversionType,
                'currency' => $this->currency,
                'is_acquisition' => $this->isAcquisition,
                'inherit_acquisition' => $this->inheritAcquisition,
                'properties' => $this->properties,
                'timestamp' => $this->isoNow(),
            ],
        ];

        // Only include non-null values
        if ($this->visitorId !== null) {
            $payload['conversion']['visitor_id'] = $this->visitorId;
        }
        if ($this->userId !== null) {
            $payload['conversion']['user_id'] = $this->userId;
        }
        if ($this->eventId !== null) {
            $payload['conversion']['event_id'] = $this->eventId;
        }
        if ($this->revenue !== null) {
            $payload['conversion']['revenue'] = $this->revenue;
        }
        if ($this->ip !== null) {
            $payload['conversion']['ip'] = $this->ip;
        }
        if ($this->userAgent !== null) {
            $payload['conversion']['user_agent'] = $this->userAgent;
        }
        if ($this->identifier !== null) {
            $payload['conversion']['identifier'] = $this->identifier;
        }

        $response = $api->postWithResponse('/conversions', $payload);

        if ($response === null) {
            return false;
        }

        return [
            'success' => true,
            'conversion_id' => $response['conversion']['id'] ?? null,
            'attribution' => $response['attribution'] ?? null,
        ];
    }

    private function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }
}

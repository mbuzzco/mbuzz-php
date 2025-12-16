<?php

declare(strict_types=1);

namespace Mbuzz\Request;

use Mbuzz\Api;

final class ConversionRequest
{
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
    ) {
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
                'visitor_id' => $this->visitorId,
                'user_id' => $this->userId,
                'event_id' => $this->eventId,
                'revenue' => $this->revenue,
                'currency' => $this->currency,
                'is_acquisition' => $this->isAcquisition,
                'inherit_acquisition' => $this->inheritAcquisition,
                'properties' => $this->properties,
                'timestamp' => $this->isoNow(),
            ],
        ];

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

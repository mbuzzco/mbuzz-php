<?php
// NOTE: Session ID removed in 0.7.0 - server handles session resolution

declare(strict_types=1);

namespace Mbuzz\Request;

use Mbuzz\Api;

final class TrackRequest
{
    public function __construct(
        private string $eventType,
        private ?string $visitorId = null,
        private ?string $userId = null,
        /** @var array<string, mixed> */
        private array $properties = [],
        private ?string $ip = null,
        private ?string $userAgent = null,
        /** @var array<string, string>|null */
        private ?array $identifier = null,
    ) {
    }

    /**
     * @return array{success: bool, event_id: ?string, event_type: string, visitor_id: ?string}|false
     */
    public function send(Api $api): array|false
    {
        if (empty($this->eventType)) {
            return false;
        }

        if ($this->visitorId === null && $this->userId === null) {
            return false;
        }

        $event = [
            'event_type' => $this->eventType,
            'properties' => $this->properties,
            'timestamp' => $this->isoNow(),
        ];

        // Only include non-null values
        if ($this->visitorId !== null) {
            $event['visitor_id'] = $this->visitorId;
        }
        if ($this->userId !== null) {
            $event['user_id'] = $this->userId;
        }
        if ($this->ip !== null) {
            $event['ip'] = $this->ip;
        }
        if ($this->userAgent !== null) {
            $event['user_agent'] = $this->userAgent;
        }
        if ($this->identifier !== null) {
            $event['identifier'] = $this->identifier;
        }

        $payload = ['events' => [$event]];

        $response = $api->postWithResponse('/events', $payload);

        if ($response === null || empty($response['events'])) {
            return false;
        }

        $eventResponse = $response['events'][0];
        return [
            'success' => true,
            'event_id' => $eventResponse['id'] ?? null,
            'event_type' => $this->eventType,
            'visitor_id' => $this->visitorId,
        ];
    }

    private function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }
}

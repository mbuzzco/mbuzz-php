<?php

declare(strict_types=1);

namespace Mbuzz\Request;

use Mbuzz\Api;

final class TrackRequest
{
    public function __construct(
        private string $eventType,
        private ?string $visitorId = null,
        private ?string $sessionId = null,
        private ?string $userId = null,
        /** @var array<string, mixed> */
        private array $properties = [],
    ) {
    }

    /**
     * @return array{success: bool, event_id: ?string, event_type: string, visitor_id: ?string, session_id: ?string}|false
     */
    public function send(Api $api): array|false
    {
        if (empty($this->eventType)) {
            return false;
        }

        if ($this->visitorId === null && $this->userId === null) {
            return false;
        }

        $payload = [
            'events' => [[
                'event_type' => $this->eventType,
                'visitor_id' => $this->visitorId,
                'session_id' => $this->sessionId,
                'user_id' => $this->userId,
                'properties' => $this->properties,
                'timestamp' => $this->isoNow(),
            ]],
        ];

        $response = $api->postWithResponse('/events', $payload);

        if ($response === null || empty($response['events'])) {
            return false;
        }

        $event = $response['events'][0];
        return [
            'success' => true,
            'event_id' => $event['id'] ?? null,
            'event_type' => $this->eventType,
            'visitor_id' => $this->visitorId,
            'session_id' => $this->sessionId,
        ];
    }

    private function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }
}

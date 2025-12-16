<?php

declare(strict_types=1);

namespace Mbuzz\Request;

use Mbuzz\Api;

final class SessionRequest
{
    public function __construct(
        private string $visitorId,
        private string $sessionId,
        private ?string $url = null,
        private ?string $referrer = null,
    ) {
    }

    public function send(Api $api): bool
    {
        if (empty($this->visitorId) || empty($this->sessionId)) {
            return false;
        }

        // URL is required for session creation
        if ($this->url === null) {
            return false;
        }

        $payload = [
            'session' => [
                'visitor_id' => $this->visitorId,
                'session_id' => $this->sessionId,
                'url' => $this->url,
                'referrer' => $this->referrer,
                'started_at' => $this->isoNow(),
            ],
        ];

        return $api->post('/sessions', $payload);
    }

    private function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }
}

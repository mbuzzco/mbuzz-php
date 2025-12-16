<?php

declare(strict_types=1);

namespace Mbuzz\Request;

use Mbuzz\Api;

final class IdentifyRequest
{
    public function __construct(
        private string $userId,
        private ?string $visitorId = null,
        /** @var array<string, mixed> */
        private array $traits = [],
    ) {
    }

    public function send(Api $api): bool
    {
        if (empty($this->userId)) {
            return false;
        }

        $payload = [
            'user_id' => $this->userId,
            'visitor_id' => $this->visitorId,
            'traits' => $this->traits,
        ];

        return $api->post('/identify', $payload);
    }
}

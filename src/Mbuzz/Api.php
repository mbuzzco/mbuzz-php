<?php

declare(strict_types=1);

namespace Mbuzz;

final class Api
{
    private const USER_AGENT = 'mbuzz-php/0.1.0';

    private Config $config;

    /** @var callable|null */
    private $transport = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Set custom transport for testing
     *
     * @param callable $transport Function(string $method, string $url, ?string $payload, array $headers): array{status: int, body: mixed}
     */
    public function setTransport(callable $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * POST request, returns boolean (fire-and-forget)
     */
    public function post(string $path, array $payload): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        try {
            $response = $this->sendRequest('POST', $path, $payload);
            return $response['status'] >= 200 && $response['status'] < 300;
        } catch (\Throwable $e) {
            $this->log("API error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * POST request, returns parsed JSON response
     *
     * @return array<string, mixed>|null
     */
    public function postWithResponse(string $path, array $payload): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        try {
            $response = $this->sendRequest('POST', $path, $payload);
            if ($response['status'] >= 200 && $response['status'] < 300) {
                return $response['body'];
            }
            return null;
        } catch (\Throwable $e) {
            $this->log("API error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * GET request for validation
     *
     * @return array<string, mixed>|null
     */
    public function get(string $path): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        try {
            $response = $this->sendRequest('GET', $path, null);
            if ($response['status'] >= 200 && $response['status'] < 300) {
                return $response['body'];
            }
            return null;
        } catch (\Throwable $e) {
            $this->log("API error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * @return array{status: int, body: mixed}
     */
    private function sendRequest(string $method, string $path, ?array $payload): array
    {
        $url = $this->config->getApiUrl() . '/' . ltrim($path, '/');

        $headers = [
            'Authorization: Bearer ' . $this->config->getApiKey(),
            'Content-Type: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];

        $jsonPayload = $payload !== null ? json_encode($payload) : null;

        $this->log("Request: {$method} {$url}", $payload ?? []);

        // Use custom transport if set (for testing)
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $jsonPayload, $headers);
        } else {
            $response = $this->curlRequest($method, $url, $jsonPayload, $headers);
        }

        $this->log("Response: {$response['status']}", is_array($response['body']) ? $response['body'] : []);

        return $response;
    }

    /**
     * @param array<string> $headers
     * @return array{status: int, body: mixed}
     */
    private function curlRequest(string $method, string $url, ?string $payload, array $headers): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config->getTimeout(),
            CURLOPT_CONNECTTIMEOUT => $this->config->getTimeout(),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'POST' && $payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // Note: curl_close() removed - it has no effect since PHP 8.0
        // and is deprecated since PHP 8.5

        if ($response === false) {
            throw new \RuntimeException("cURL error: {$error}");
        }

        $body = json_decode($response, true);

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->config->isDebug()) {
            $contextStr = empty($context) ? '' : ' ' . json_encode($context);
            error_log("[Mbuzz] {$message}{$contextStr}");
        }
    }
}

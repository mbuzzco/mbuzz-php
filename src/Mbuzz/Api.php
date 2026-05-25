<?php

declare(strict_types=1);

namespace Mbuzz;

final class Api
{
    private const USER_AGENT = 'mbuzz-php/1.2.0';

    private Config $config;

    /** @var callable|null */
    private $transport = null;

    /**
     * @var array<int, array{path: string, payload: array, timeout: ?int}>
     */
    private array $deferredQueue = [];

    private bool $shutdownRegistered = false;

    /** @var array<int, callable> */
    private array $successListeners = [];

    /** @var array<int, callable> */
    private array $errorListeners = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Set custom transport for testing.
     *
     * When a transport is set, post() also runs synchronously instead of
     * deferring to a shutdown handler — tests get deterministic behavior.
     *
     * @param callable $transport Function(string $method, string $url, ?string $payload, array $headers, ?int $timeout): array{status: int, body: mixed}
     */
    public function setTransport(callable $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Register a listener for 2xx responses. Fires for both immediate and
     * deferred POSTs (the latter on the shutdown path).
     *
     * @param callable $listener function(string $method, string $url, int $status, ?array $body): void
     */
    public function onSuccess(callable $listener): void
    {
        $this->successListeners[] = $listener;
    }

    /**
     * Register a listener for non-2xx responses and transport exceptions.
     * status will be 0 when the exception fired before a response was received.
     *
     * @param callable $listener function(string $method, string $url, int $status, ?array $body, ?\Throwable $exception): void
     */
    public function onError(callable $listener): void
    {
        $this->errorListeners[] = $listener;
    }

    /**
     * POST request, fire-and-forget.
     *
     * Under FPM/LiteSpeed the call is queued and flushed AFTER the response
     * is sent to the client (via fastcgi_finish_request / litespeed_finish_request),
     * so the user never waits on the HTTP round-trip to api.mbuzz.co.
     * Falls back to running in the regular shutdown phase elsewhere.
     *
     * The boolean return is optimistic: true means "queued/sent without
     * an immediate error", not "the API server accepted it".
     */
    public function post(string $path, array $payload, ?int $timeout = null): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        if ($this->transport !== null) {
            try {
                $response = $this->sendRequest('POST', $path, $payload, $timeout);
                return $response['status'] >= 200 && $response['status'] < 300;
            } catch (\Throwable $e) {
                $this->log("API error: {$e->getMessage()}");
                return false;
            }
        }

        $this->deferredQueue[] = [
            'path' => $path,
            'payload' => $payload,
            'timeout' => $timeout,
        ];

        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'flushDeferred']);
            $this->shutdownRegistered = true;
        }

        return true;
    }

    /**
     * POST request, returns parsed JSON response.
     *
     * Always synchronous — callers want the response body (event_id,
     * conversion_id, attribution data).
     *
     * @return array<string, mixed>|null
     */
    public function postWithResponse(string $path, array $payload, ?int $timeout = null): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        try {
            $response = $this->sendRequest('POST', $path, $payload, $timeout);
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
     * GET request for validation.
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
     * Flush queued POSTs. Registered as a shutdown handler; safe to call
     * manually (e.g., from long-running workers between requests).
     */
    public function flushDeferred(): void
    {
        if (empty($this->deferredQueue)) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        $queue = $this->deferredQueue;
        $this->deferredQueue = [];

        foreach ($queue as $item) {
            try {
                $this->sendRequest('POST', $item['path'], $item['payload'], $item['timeout']);
            } catch (\Throwable $e) {
                $this->log("Deferred API error: {$e->getMessage()}");
            }
        }
    }

    /**
     * @return array{status: int, body: mixed}
     */
    private function sendRequest(string $method, string $path, ?array $payload, ?int $timeout = null): array
    {
        $url = $this->config->getApiUrl() . '/' . ltrim($path, '/');

        $headers = [
            'Authorization: Bearer ' . $this->config->getApiKey(),
            'Content-Type: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];

        $jsonPayload = $payload !== null ? json_encode($payload) : null;

        $this->log("Request: {$method} {$url}", $payload ?? []);

        try {
            if ($this->transport !== null) {
                $response = ($this->transport)($method, $url, $jsonPayload, $headers, $timeout);
            } else {
                $response = $this->curlRequest($method, $url, $jsonPayload, $headers, $timeout);
            }
        } catch (\Throwable $e) {
            $this->notifyError($method, $url, 0, null, $e);
            throw $e;
        }

        $this->log("Response: {$response['status']}", is_array($response['body']) ? $response['body'] : []);

        $status = $response['status'];
        $body = is_array($response['body']) ? $response['body'] : null;

        if ($status >= 200 && $status < 300) {
            $this->notifySuccess($method, $url, $status, $body);
        } else {
            $this->notifyError($method, $url, $status, $body, null);
        }

        return $response;
    }

    /**
     * One-shot API key probe against GET /validate.
     *
     * Bypasses the enabled flag — validation is a setup-time check, distinct
     * from tracking. The provided key overrides the configured Authorization
     * header so callers (e.g. the WP plugin's settings form) can verify a
     * candidate key without mutating live config.
     *
     * @return array<string, mixed>|false  Response body on 2xx (empty array if body is null), false otherwise.
     */
    public function probeValidate(string $apiKey): array|false
    {
        $url = $this->config->getApiUrl() . '/validate';
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'User-Agent: ' . self::USER_AGENT,
        ];

        $this->log("Request: GET {$url} (validate)");

        try {
            if ($this->transport !== null) {
                $response = ($this->transport)('GET', $url, null, $headers, null);
            } else {
                $response = $this->curlRequest('GET', $url, null, $headers, null);
            }
        } catch (\Throwable $e) {
            $this->notifyError('GET', $url, 0, null, $e);
            $this->log("Validation error: {$e->getMessage()}");
            return false;
        }

        $status = $response['status'];
        $body = is_array($response['body']) ? $response['body'] : null;

        $this->log("Response: {$status}", $body ?? []);

        if ($status >= 200 && $status < 300) {
            $this->notifySuccess('GET', $url, $status, $body);
            return $body ?? [];
        }

        $this->notifyError('GET', $url, $status, $body, null);
        return false;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function notifySuccess(string $method, string $url, int $status, ?array $body): void
    {
        foreach ($this->successListeners as $listener) {
            try {
                $listener($method, $url, $status, $body);
            } catch (\Throwable $e) {
                $this->log("Success listener threw: {$e->getMessage()}");
            }
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function notifyError(string $method, string $url, int $status, ?array $body, ?\Throwable $exception): void
    {
        foreach ($this->errorListeners as $listener) {
            try {
                $listener($method, $url, $status, $body, $exception);
            } catch (\Throwable $e) {
                $this->log("Error listener threw: {$e->getMessage()}");
            }
        }
    }

    /**
     * @param array<string> $headers
     * @return array{status: int, body: mixed}
     */
    private function curlRequest(string $method, string $url, ?string $payload, array $headers, ?int $timeout = null): array
    {
        $effectiveTimeout = $timeout ?? $this->config->getTimeout();

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $effectiveTimeout,
            CURLOPT_CONNECTTIMEOUT => $effectiveTimeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'POST' && $payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

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

<?php

declare(strict_types=1);

namespace Mbuzz;

final class Context
{
    private static ?self $instance = null;

    private ?string $visitorId = null;
    private ?string $sessionId = null;
    private ?string $userId = null;
    private ?string $url = null;
    private ?string $referrer = null;
    private bool $isNewSession = false;
    private bool $initialized = false;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset context (for new requests in long-running processes or testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Initialize context from cookies and request
     */
    public function initialize(CookieManager $cookies): void
    {
        if ($this->initialized) {
            return;
        }

        // Get or create visitor ID
        $this->visitorId = $cookies->getVisitorId() ?? IdGenerator::generate();
        $isNewVisitor = $cookies->isNewVisitor();

        // Get or create session ID
        $this->sessionId = $cookies->getSessionId() ?? IdGenerator::generate();
        $this->isNewSession = $cookies->isNewSession();

        // Extract request info
        $this->url = $this->extractUrl();
        $this->referrer = $_SERVER['HTTP_REFERER'] ?? null;

        // Set cookies
        if ($isNewVisitor) {
            $cookies->setVisitorId($this->visitorId);
        }
        $cookies->setSessionId($this->sessionId); // Always refresh session cookie

        $this->initialized = true;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getVisitorId(): ?string
    {
        return $this->visitorId;
    }

    public function setVisitorId(string $visitorId): void
    {
        $this->visitorId = $visitorId;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(string $referrer): void
    {
        $this->referrer = $referrer;
    }

    public function isNewSession(): bool
    {
        return $this->isNewSession;
    }

    /**
     * Enrich properties with URL and referrer
     *
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public function enrichProperties(array $properties = []): array
    {
        $enriched = [];

        if ($this->url !== null && !isset($properties['url'])) {
            $enriched['url'] = $this->url;
        }

        if ($this->referrer !== null && !isset($properties['referrer'])) {
            $enriched['referrer'] = $this->referrer;
        }

        return array_merge($enriched, $properties);
    }

    private function extractUrl(): ?string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        if ($host === null) {
            return null;
        }

        return "{$scheme}://{$host}{$uri}";
    }

    private function isSecure(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['SERVER_PORT'] ?? 0) == 443 ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        );
    }
}

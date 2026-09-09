<?php
// NOTE: Session ID removed in 0.7.0 - server handles session resolution

declare(strict_types=1);

namespace Mbuzz;

final class Context
{
    private static ?self $instance = null;

    private ?string $visitorId = null;
    private ?string $userId = null;
    private ?string $url = null;
    private ?string $referrer = null;
    private ?string $ip = null;
    private ?string $userAgent = null;
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

        // Only the cookie the browser already holds, never a freshly minted
        // one. This runs on a page response, which a full-page cache may store
        // and replay to every visitor — a Set-Cookie here would hand everyone
        // the same id and merge unrelated people into one journey. Minting
        // belongs to SessionEndpoint alone, whose POST no cache stores.
        // See SessionEndpoint::MINT_ON_PAGE_RESPONSE.
        //
        // (Until 2.0.0 this also carried an `isNewVisitor && visitorId !== null`
        // branch that set the cookie. It was unreachable — isNewVisitor() is
        // true precisely when getVisitorId() is null — which is the only reason
        // PHP escaped the visitor-collapse defect that hit Ruby, Node and
        // Python. Do not restore it.)
        $this->visitorId = $cookies->getVisitorId();

        // Extract request info
        $this->url = $this->extractUrl();
        $this->referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $this->ip = $this->extractClientIp();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

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

    /**
     * Get client IP address (for server-side session resolution)
     */
    public function getClientIp(): ?string
    {
        return $this->ip;
    }

    /**
     * Get client user agent (for server-side session resolution)
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
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

    /**
     * Extract client IP from request headers
     * Checks X-Forwarded-For, X-Real-IP, then REMOTE_ADDR
     */
    private function extractClientIp(): ?string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($forwarded !== null) {
            return trim(explode(',', $forwarded)[0]);
        }
        return $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    }
}

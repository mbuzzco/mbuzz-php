<?php

declare(strict_types=1);

namespace Mbuzz;

final class CookieManager
{
    public const VISITOR_COOKIE = '_mbuzz_vid';
    public const SESSION_COOKIE = '_mbuzz_sid';

    public const VISITOR_MAX_AGE = 63072000; // 2 years in seconds
    public const SESSION_MAX_AGE = 1800;     // 30 minutes in seconds

    /** @var array<string, string> */
    private array $cookies;

    /** @var callable|null */
    private $setCookieCallback;

    private bool $secure = true;
    private string $path = '/';
    private string $sameSite = 'Lax';

    /**
     * @param array<string, string>|null $cookies Cookie source (defaults to $_COOKIE)
     * @param callable|null $setCookieCallback Callback for setting cookies (defaults to setcookie())
     */
    public function __construct(?array $cookies = null, ?callable $setCookieCallback = null)
    {
        $this->cookies = $cookies ?? $_COOKIE;
        $this->setCookieCallback = $setCookieCallback;

        // Auto-detect secure based on request (only when using real superglobals)
        if ($cookies === null) {
            $this->secure = $this->isSecureRequest();
        }
    }

    /**
     * Get cookie value, returns null if not set
     */
    public function get(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Set cookie with proper attributes
     */
    public function set(string $name, string $value, int $maxAge): bool
    {
        $options = [
            'expires' => time() + $maxAge,
            'path' => $this->path,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ];

        if ($this->setCookieCallback !== null) {
            return ($this->setCookieCallback)($name, $value, $options);
        }

        // Don't try to set cookies if headers already sent
        if (headers_sent()) {
            return false;
        }

        return setcookie($name, $value, $options);
    }

    /**
     * Delete cookie
     */
    public function delete(string $name): bool
    {
        $options = [
            'expires' => time() - 3600,
            'path' => $this->path,
        ];

        unset($this->cookies[$name]);

        if ($this->setCookieCallback !== null) {
            return ($this->setCookieCallback)($name, '', $options);
        }

        if (headers_sent()) {
            return false;
        }

        return setcookie($name, '', $options);
    }

    /**
     * Get visitor ID cookie, or null if not set
     */
    public function getVisitorId(): ?string
    {
        return $this->get(self::VISITOR_COOKIE);
    }

    /**
     * Get session ID cookie, or null if not set
     */
    public function getSessionId(): ?string
    {
        return $this->get(self::SESSION_COOKIE);
    }

    /**
     * Set visitor ID cookie
     */
    public function setVisitorId(string $visitorId): bool
    {
        return $this->set(self::VISITOR_COOKIE, $visitorId, self::VISITOR_MAX_AGE);
    }

    /**
     * Set session ID cookie
     */
    public function setSessionId(string $sessionId): bool
    {
        return $this->set(self::SESSION_COOKIE, $sessionId, self::SESSION_MAX_AGE);
    }

    /**
     * Check if visitor is new (no visitor cookie)
     */
    public function isNewVisitor(): bool
    {
        return $this->getVisitorId() === null;
    }

    /**
     * Check if session is new (no session cookie)
     */
    public function isNewSession(): bool
    {
        return $this->getSessionId() === null;
    }

    private function isSecureRequest(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['SERVER_PORT'] ?? 0) == 443 ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        );
    }
}

<?php

declare(strict_types=1);

namespace Mbuzz;

use InvalidArgumentException;

final class Config
{
    private const DEFAULT_API_URL = 'https://api.mbuzz.co/api/v1';
    private const DEFAULT_TIMEOUT = 5;

    private const DEFAULT_SKIP_PATHS = [
        '/health',
        '/healthz',
        '/ping',
        '/up',
        '/favicon.ico',
        '/robots.txt',
    ];

    private const DEFAULT_SKIP_EXTENSIONS = [
        '.js',
        '.css',
        '.map',
        '.png',
        '.jpg',
        '.jpeg',
        '.gif',
        '.ico',
        '.svg',
        '.webp',
        '.woff',
        '.woff2',
        '.ttf',
        '.eot',
    ];

    private static ?self $instance = null;

    private string $apiKey = '';
    private string $apiUrl = self::DEFAULT_API_URL;
    private bool $enabled = true;
    private bool $debug = false;
    private int $timeout = self::DEFAULT_TIMEOUT;
    /** @var array<string> */
    private array $skipPaths = [];
    /** @var array<string> */
    private array $skipExtensions = [];
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
     * Reset singleton (for testing only)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Initialize configuration
     *
     * @param array{
     *   api_key: string,
     *   api_url?: string,
     *   enabled?: bool,
     *   debug?: bool,
     *   timeout?: int,
     *   skip_paths?: array<string>,
     *   skip_extensions?: array<string>
     * } $options
     * @throws InvalidArgumentException if api_key is missing or empty, or api_url
     *   is not https, or the key's region marker does not match the host choice
     */
    public function init(array $options): void
    {
        $apiKey = $options['api_key'] ?? '';
        if (empty($apiKey)) {
            throw new InvalidArgumentException('api_key is required');
        }

        $apiUrl = self::resolveApiUrl($apiKey, $options['api_url'] ?? null);

        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->enabled = $options['enabled'] ?? true;
        $this->debug = $options['debug'] ?? false;
        $this->timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
        $this->skipPaths = array_merge(
            self::DEFAULT_SKIP_PATHS,
            $options['skip_paths'] ?? []
        );
        $this->skipExtensions = array_merge(
            self::DEFAULT_SKIP_EXTENSIONS,
            $options['skip_extensions'] ?? []
        );
        $this->initialized = true;
    }

    /**
     * api_url is an explicit opt-in for data-resident installs (spec M9). Three
     * rules keep a production install from being redirected by accident:
     * https only; no api_url means api.mbuzz.co, always; and a region key
     * (sk_live_au_...) must be paired with an api_url, and an api_url with a
     * region key, so a mis-pointed site fails here instead of sending contact
     * details to the wrong host. Test keys are exempt from the pairing rule.
     * There is deliberately no fallback host: a failed request is dropped, never
     * retried against api.mbuzz.co.
     */
    private static function resolveApiUrl(string $apiKey, mixed $apiUrl): string
    {
        $hasUrl = is_string($apiUrl) && $apiUrl !== '';
        $isRegionKey = (bool) preg_match('/^sk_[a-z]+_[a-z]{2}_/', $apiKey);
        $isTestKey = str_starts_with($apiKey, 'sk_test_');

        if ($hasUrl && !str_starts_with($apiUrl, 'https://')) {
            throw new InvalidArgumentException('api_url must start with https://');
        }
        if (!$isTestKey && $hasUrl !== $isRegionKey) {
            throw new InvalidArgumentException(
                'api_url and the API key region must be set together: a region key needs '
                . 'api_url, and api_url needs a region key'
            );
        }

        return rtrim($hasUrl ? $apiUrl : self::DEFAULT_API_URL, '/');
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->initialized;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function shouldSkipPath(string $path): bool
    {
        // Check exact path matches
        foreach ($this->skipPaths as $skipPath) {
            if (str_starts_with($path, $skipPath)) {
                return true;
            }
        }

        // Check extension matches
        foreach ($this->skipExtensions as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }

        return false;
    }

    public function isTestKey(): bool
    {
        return str_starts_with($this->apiKey, 'sk_test_');
    }

    /**
     * Point the SDK at a different API host. For integration testing only.
     *
     * `api_url` was deliberately removed from init() in 0.8.2 so a production
     * install can never be redirected away from api.mbuzz.co by configuration.
     * That is still true: this setter refuses unless the configured key is a
     * test key, so the only thing it can redirect is a test account. The
     * integration harness needs it — without it the SDK posts every session to
     * production and the API-side assertions verify nothing.
     *
     * Mirrors the Python SDK, whose test app assigns config.api_url directly
     * for the same reason.
     *
     * @throws \LogicException when the configured key is not a test key
     */
    public function setApiUrlForTesting(string $apiUrl): void
    {
        if (!$this->isTestKey()) {
            throw new \LogicException(
                'setApiUrlForTesting() requires an sk_test_ key: a live install must '
                . 'never be redirected away from ' . self::DEFAULT_API_URL
            );
        }

        $this->apiUrl = rtrim($apiUrl, '/');
    }
}

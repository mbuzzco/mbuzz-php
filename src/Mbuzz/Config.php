<?php

declare(strict_types=1);

namespace Mbuzz;

use InvalidArgumentException;

final class Config
{
    private const DEFAULT_API_URL = 'https://mbuzz.co/api/v1';
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
     * @throws InvalidArgumentException if api_key is missing or empty
     */
    public function init(array $options): void
    {
        $apiKey = $options['api_key'] ?? '';
        if (empty($apiKey)) {
            throw new InvalidArgumentException('api_key is required');
        }

        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($options['api_url'] ?? self::DEFAULT_API_URL, '/');
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
}

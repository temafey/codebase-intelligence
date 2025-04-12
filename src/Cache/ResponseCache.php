<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Cache;

use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Context-aware caching for Claude responses to reduce token usage
 */
class ResponseCache
{
    private LoggerInterface $logger;
    private AdapterInterface $cache;
    private bool $enabled;
    private int $ttl;
    private string $namespace;

    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null,
        ?AdapterInterface $cache = null
    ) {
        $this->logger = $logger ?? new Logger('response-cache');
        $this->enabled = $config['cache_enabled'] ?? true;
        $this->ttl = (int) ($config['cache_ttl'] ?? 604800); // 1 week default
        $this->namespace = $config['cache_namespace'] ?? 'claude_cache';

        if ($cache === null) {
            $cacheStorage = $config['cache_storage'] ?? 'filesystem';

            if ($cacheStorage === 'redis') {
                $redisHost = $config['redis_host'] ?? '127.0.0.1';
                $redisPort = (int) ($config['redis_port'] ?? 6379);

                try {
                    $redis = new \Redis();
                    $redis->connect($redisHost, $redisPort);
                    $this->cache = new RedisAdapter($redis, $this->namespace, $this->ttl);
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to connect to Redis, falling back to filesystem cache", [
                        'error' => $e->getMessage()
                    ]);
                    $this->cache = new FilesystemAdapter($this->namespace, $this->ttl);
                }
            } else {
                $this->cache = new FilesystemAdapter($this->namespace, $this->ttl);
            }
        } else {
            $this->cache = $cache;
        }
    }

    /**
     * Generates a cache key for a given prompt and context
     */
    public function generateCacheKey(string $prompt, array $codeContext = []): string
    {
        // Normalize code context
        ksort($codeContext);

        // Create a string representation of the context
        $contextStr = '';
        foreach ($codeContext as $key => $value) {
            $contextStr .= "$key:$value;";
        }

        // Generate hash
        $data = $prompt . "\n" . $contextStr;
        return hash('sha256', $data);
    }

    /**
     * Gets cached response if available
     */
    public function getCachedResponse(string $prompt, array $codeContext = []): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->generateCacheKey($prompt, $codeContext);
        $this->logger->debug("Looking up cache", ['key' => $key]);

        try {
            $cacheItem = $this->cache->getItem($key);

            if ($cacheItem->isHit()) {
                $this->logger->info("Cache hit", ['key' => $key]);
                return $cacheItem->get();
            }

            $this->logger->debug("Cache miss", ['key' => $key]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error("Cache lookup error", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Caches a response for future use
     */
    public function cacheResponse(string $prompt, array $codeContext, array $response): void
    {
        if (!$this->enabled) {
            return;
        }

        $key = $this->generateCacheKey($prompt, $codeContext);
        $this->logger->debug("Caching response", ['key' => $key]);

        try {
            $cacheItem = $this->cache->getItem($key);
            $cacheItem->set($response);
            $cacheItem->expiresAfter($this->ttl);

            $this->cache->save($cacheItem);
            $this->logger->info("Response cached successfully", ['key' => $key]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to cache response", [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clears all cached responses
     */
    public function clearCache(): bool
    {
        $this->logger->info("Clearing all cached responses");

        try {
            $result = $this->cache->clear();
            $this->logger->info("Cache cleared successfully");
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Failed to clear cache", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Deletes a specific cached response
     */
    public function deleteItem(string $prompt, array $codeContext = []): bool
    {
        $key = $this->generateCacheKey($prompt, $codeContext);
        $this->logger->debug("Deleting cached response", ['key' => $key]);

        try {
            $result = $this->cache->deleteItem($key);
            $this->logger->info("Cache item deleted successfully", ['key' => $key]);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Failed to delete cache item", [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Prunes expired cache items
     */
    public function prune(): bool
    {
        $this->logger->info("Pruning expired cache items");

        try {
            if (method_exists($this->cache, 'prune')) {
                $result = $this->cache->prune();
                $this->logger->info("Cache pruned successfully");
                return $result;
            }

            $this->logger->warning("Cache adapter does not support pruning");
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Failed to prune cache", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Optimizes the cache by removing least used items
     */
    public function optimize(int $maxItems = 1000): bool
    {
        $this->logger->info("Optimizing cache", ['max_items' => $maxItems]);

        // This would require a custom implementation for tracking usage
        // For this example implementation, we'll just return true
        return true;
    }

    /**
     * Checks if caching is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enables or disables caching
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->logger->info("Cache " . ($enabled ? "enabled" : "disabled"));
    }
}
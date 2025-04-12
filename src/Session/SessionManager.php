<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Session;

use CodebaseIntelligence\API\ClaudeClient;
use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Manages Claude sessions for optimal usage and cost efficiency
 */
class SessionManager
{
    private ClaudeClient $claudeClient;
    private LoggerInterface $logger;
    private string $storageDir;
    private string $sessionPrefix;
    private int $sessionTtl;
    private int $tokenBudget;
    private float $offPeakDiscount;
    private Filesystem $filesystem;

    public function __construct(
        ClaudeClient $claudeClient,
        string $storageDir,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->claudeClient = $claudeClient;
        $this->storageDir = $storageDir;
        $this->logger = $logger ?? new Logger('session-manager');
        $this->filesystem = new Filesystem();

        // Create storage directory if it doesn't exist
        $this->filesystem->mkdir($storageDir, 0755);

        // Initialize configuration
        $this->sessionPrefix = $config['session_prefix'] ?? 'claude-session';
        $this->sessionTtl = (int) ($config['session_ttl'] ?? 28800); // 8 hours default
        $this->tokenBudget = (int) ($config['token_budget_daily'] ?? 50000);
        $this->offPeakDiscount = (float) ($config['off_peak_discount'] ?? 0.7); // 30% discount default
    }

    /**
     * Creates a new session with optimized parameters
     */
    public function createSession(string $name = '', bool $useOffPeakOptimization = true): array
    {
        $this->logger->info("Creating new session", ['name' => $name]);

        // Generate session name if not provided
        if (empty($name)) {
            $name = $this->sessionPrefix . '-' . date('YmdHis');
        }

        // Apply off-peak optimization if requested
        $ttl = $this->sessionTtl;
        $rateMulitplier = 1.0;

        if ($useOffPeakOptimization) {
            $hour = (int) date('H');
            if ($hour >= 2 && $hour < 8) {
                // Off-peak hours, apply discount
                $rateMulitplier = $this->offPeakDiscount;
                $this->logger->info("Using off-peak discount", [
                    'discount' => (1 - $this->offPeakDiscount) * 100 . '%',
                    'hour' => $hour
                ]);
            }
        }

        // Apply token budget
        $budget = (int) ($this->tokenBudget * $rateMulitplier);

        try {
            $session = $this->claudeClient->createSession($name, $ttl);

            // Store session metadata
            $this->storeSessionMetadata($session['id'], [
                'name' => $name,
                'created_at' => time(),
                'ttl' => $ttl,
                'budget' => $budget,
                'rate_multiplier' => $rateMulitplier
            ]);

            $this->logger->info("Session created successfully", [
                'session_id' => $session['id'],
                'budget' => $budget
            ]);

            return $session;
        } catch (\Exception $e) {
            $this->logger->error("Failed to create session", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Resumes an existing session
     */
    public function resumeSession(string $sessionId): array
    {
        $this->logger->info("Resuming session", ['session_id' => $sessionId]);

        try {
            $session = $this->claudeClient->resumeSession($sessionId);

            // Update session metadata
            $metadata = $this->getSessionMetadata($sessionId);
            if ($metadata) {
                $metadata['resumed_at'] = time();
                $this->storeSessionMetadata($sessionId, $metadata);
            }

            $this->logger->info("Session resumed successfully", ['session_id' => $sessionId]);

            return $session;
        } catch (\Exception $e) {
            $this->logger->error("Failed to resume session", [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Lists available sessions
     */
    public function listSessions(int $limit = 10): array
    {
        $this->logger->info("Listing sessions", ['limit' => $limit]);

        try {
            $sessions = $this->claudeClient->listSessions($limit);

            // Enhance session data with local metadata
            $sessionsDir = $this->storageDir . '/sessions';
            if ($this->filesystem->exists($sessionsDir)) {
                foreach ($sessions as &$session) {
                    $metadata = $this->getSessionMetadata($session['id']);
                    if ($metadata) {
                        $session = array_merge($session, $metadata);
                    }
                }
            }

            return $sessions;
        } catch (\Exception $e) {
            $this->logger->error("Failed to list sessions", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Deletes a session
     */
    public function deleteSession(string $sessionId): bool
    {
        $this->logger->info("Deleting session", ['session_id' => $sessionId]);

        try {
            $result = $this->claudeClient->deleteSession($sessionId);

            // Delete local metadata
            $metadataFile = $this->storageDir . '/sessions/' . $sessionId . '.json';
            if ($this->filesystem->exists($metadataFile)) {
                $this->filesystem->remove($metadataFile);
            }

            $this->logger->info("Session deleted successfully", ['session_id' => $sessionId]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Failed to delete session", [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Stores session metadata locally
     */
    private function storeSessionMetadata(string $sessionId, array $metadata): void
    {
        $sessionsDir = $this->storageDir . '/sessions';
        $this->filesystem->mkdir($sessionsDir, 0755);

        $metadataFile = $sessionsDir . '/' . $sessionId . '.json';
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }

    /**
     * Gets session metadata
     */
    private function getSessionMetadata(string $sessionId): ?array
    {
        $metadataFile = $this->storageDir . '/sessions/' . $sessionId . '.json';

        if (!$this->filesystem->exists($metadataFile)) {
            return null;
        }

        $content = file_get_contents($metadataFile);
        return json_decode($content, true);
    }

    /**
     * Determines if current time is in off-peak hours
     */
    public function isOffPeakHours(): bool
    {
        $hour = (int) date('H');
        return $hour >= 2 && $hour < 8;
    }

    /**
     * Prunes expired sessions
     */
    public function pruneExpiredSessions(): int
    {
        $this->logger->info("Pruning expired sessions");

        $sessionsDir = $this->storageDir . '/sessions';
        if (!$this->filesystem->exists($sessionsDir)) {
            return 0;
        }

        $prunedCount = 0;
        $files = glob($sessionsDir . '/*.json');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $metadata = json_decode($content, true);

            if (!isset($metadata['created_at']) || !isset($metadata['ttl'])) {
                continue;
            }

            $expiresAt = $metadata['created_at'] + $metadata['ttl'];

            if (time() > $expiresAt) {
                // Session is expired
                $sessionId = basename($file, '.json');

                try {
                    $this->deleteSession($sessionId);
                    $prunedCount++;
                } catch (\Exception $e) {
                    // Session might already be deleted on the server
                    $this->filesystem->remove($file);
                    $prunedCount++;
                }
            }
        }

        $this->logger->info("Pruned expired sessions", ['count' => $prunedCount]);

        return $prunedCount;
    }
}
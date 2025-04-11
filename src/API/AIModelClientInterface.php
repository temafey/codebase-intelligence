<?php

declare(strict_types=1);

namespace CodebaseIntelligence\API;

/**
 * Interface for AI model clients
 */
interface AIModelClientInterface
{
    /**
     * Create a new session
     */
    public function createSession(string $name, int $ttl = 28800): array;

    /**
     * Resume an existing session
     */
    public function resumeSession(string $sessionId): array;

    /**
     * Send a prompt to the AI model
     */
    public function sendPrompt(string $sessionId, string $prompt): array;

    /**
     * Send a file to the AI model
     */
    public function sendFile(string $sessionId, string $filePath, string $description = ''): array;

    /**
     * Send a directory to the AI model
     */
    public function sendDirectory(string $sessionId, string $dirPath, string $description = ''): array;

    /**
     * List available sessions
     */
    public function listSessions(int $limit = 10): array;

    /**
     * Delete a session
     */
    public function deleteSession(string $sessionId): bool;
}

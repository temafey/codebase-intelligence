#!/bin/bash

# Скрипт для создания модуля API

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание модуля API в $TARGET_DIR/src/API..."

# Создаем интерфейс AIModelClientInterface
mkdir -p "$TARGET_DIR/src/API"
cat > "$TARGET_DIR/src/API/AIModelClientInterface.php" << 'EOT'
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
EOT

# Создаем реализацию ClaudeClient
cat > "$TARGET_DIR/src/API/ClaudeClient.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\API;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;

/**
 * Client for interacting with Claude 3.7 Sonnet API
 */
class ClaudeClient implements AIModelClientInterface
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $apiUrl;
    private string $model;

    /**
     * Constructor
     */
    public function __construct(
        string $apiKey,
        string $apiUrl,
        string $model,
        ?LoggerInterface $logger = null
    ) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->model = $model;
        $this->logger = $logger ?? new Logger('claude-client');

        $this->httpClient = new Client([
            'base_uri' => $this->apiUrl,
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'anthropic-version' => '2023-06-01'
            ]
        ]);
    }

    /**
     * Create a new session
     */
    public function createSession(string $name, int $ttl = 28800): array
    {
        $this->logger->info("Creating new session: {$name} with TTL: {$ttl}");

        try {
            $response = $this->httpClient->post('sessions', [
                'json' => [
                    'name' => $name,
                    'ttl' => $ttl,
                    'model' => $this->model
                ]
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->logger->debug("Session created successfully", ['session_id' => $data['id'] ?? 'unknown']);

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to create session", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to create session: {$e->getMessage()}");
        }
    }

    /**
     * Resume an existing session
     */
    public function resumeSession(string $sessionId): array
    {
        $this->logger->info("Resuming session: {$sessionId}");

        try {
            $response = $this->httpClient->post("sessions/{$sessionId}/resume", [
                'json' => [
                    'model' => $this->model
                ]
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->logger->debug("Session resumed successfully");

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to resume session", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to resume session: {$e->getMessage()}");
        }
    }

    /**
     * Send a prompt to Claude
     */
    public function sendPrompt(string $sessionId, string $prompt, string $thinkingMode = 'default'): array
    {
        $this->logger->info("Sending prompt to session: {$sessionId}", ['thinking_mode' => $thinkingMode]);
        $this->logger->debug("Prompt content", ['prompt' => substr($prompt, 0, 100) . '...']);

        try {
            $requestData = [
                'role' => 'user',
                'content' => $prompt,
            ];

            // Add extended thinking flag if requested
            if ($thinkingMode === 'extended_thinking') {
                $requestData['extended_thinking'] = true;
            }

            $response = $this->httpClient->post("sessions/{$sessionId}/messages", [
                'json' => $requestData
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->logger->debug("Prompt sent successfully");

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to send prompt", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to send prompt: {$e->getMessage()}");
        }
    }

    /**
     * Send a file to Claude
     */
    public function sendFile(string $sessionId, string $filePath, string $description = ''): array
    {
        $this->logger->info("Sending file to session: {$sessionId}", ['file' => $filePath]);

        if (!file_exists($filePath)) {
            $this->logger->error("File not found", ['file' => $filePath]);
            throw new \RuntimeException("File not found: {$filePath}");
        }

        try {
            $fileContents = file_get_contents($filePath);
            $fileName = basename($filePath);
            $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

            $response = $this->httpClient->post("sessions/{$sessionId}/files", [
                'multipart' => [
                    [
                        'name' => 'file',
                        'filename' => $fileName,
                        'contents' => $fileContents,
                        'headers' => [
                            'Content-Type' => $mimeType
                        ]
                    ],
                    [
                        'name' => 'description',
                        'contents' => $description
                    ]
                ]
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->logger->debug("File sent successfully");

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to send file", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to send file: {$e->getMessage()}");
        }
    }

    /**
     * Send a directory to Claude
     *
     * This will zip the directory first and then send it
     */
    public function sendDirectory(string $sessionId, string $dirPath, string $description = ''): array
    {
        $this->logger->info("Sending directory to session: {$sessionId}", ['directory' => $dirPath]);

        if (!is_dir($dirPath)) {
            $this->logger->error("Directory not found", ['directory' => $dirPath]);
            throw new \RuntimeException("Directory not found: {$dirPath}");
        }

        // Create temporary zip file
        $zipFile = tempnam(sys_get_temp_dir(), 'claude_dir_') . '.zip';
        $this->createZipFromDirectory($dirPath, $zipFile);

        try {
            $result = $this->sendFile($sessionId, $zipFile, $description);
            // Clean up temporary file
            unlink($zipFile);
            return $result;
        } catch (\Exception $e) {
            // Make sure to clean up even on error
            if (file_exists($zipFile)) {
                unlink($zipFile);
            }
            throw $e;
        }
    }

    /**
     * Create a zip file from a directory
     */
    private function createZipFromDirectory(string $sourcePath, string $outZipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($outZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip file: {$outZipPath}");
        }

        $sourcePath = rtrim($sourcePath, '/\\') . '/';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourcePath));

            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();
    }

    /**
     * List available sessions
     */
    public function listSessions(int $limit = 10): array
    {
        $this->logger->info("Listing available sessions", ['limit' => $limit]);

        try {
            $response = $this->httpClient->get('sessions', [
                'query' => [
                    'limit' => $limit
                ]
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->logger->debug("Sessions retrieved successfully", ['count' => count($data['sessions'] ?? [])]);

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to list sessions", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to list sessions: {$e->getMessage()}");
        }
    }

    /**
     * Delete a session
     */
    public function deleteSession(string $sessionId): bool
    {
        $this->logger->info("Deleting session: {$sessionId}");

        try {
            $this->httpClient->delete("sessions/{$sessionId}");
            $this->logger->debug("Session deleted successfully");

            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to delete session", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to delete session: {$e->getMessage()}");
        }
    }

    /**
     * Determine appropriate thinking mode based on prompt and context
     */
    public function determineThinkingMode(string $prompt, array $codeContext): string
    {
        // Оценка сложности запроса
        $complexity = $this->evaluateComplexity($prompt, $codeContext);

        // Если сложность выше порога, используйте extended thinking
        if ($complexity > 0.7) {
            return 'extended_thinking';
        }

        return 'default';
    }

    /**
     * Evaluate complexity of a prompt and context
     */
    private function evaluateComplexity(string $prompt, array $codeContext): float
    {
        $score = 0.0;

        // Проверка длины запроса
        if (strlen($prompt) > 1000) {
            $score += 0.3;
        }

        // Проверка наличия ключевых слов, указывающих на сложность
        if (preg_match('/архитектур|оптимизац|рефакторинг|безопасност|анализ|производительност/ui', $prompt)) {
            $score += 0.2;
        }

        // Проверка объема затрагиваемого кода
        if (count($codeContext) > 5) {
            $score += 0.2;
        }

        return min($score, 1.0);
    }
}
EOT

echo "Создание модуля API завершено."
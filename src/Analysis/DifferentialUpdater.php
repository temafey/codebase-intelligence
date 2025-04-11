<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates differential updates for efficient codebase updating
 */
class DifferentialUpdater
{
    private LoggerInterface $logger;
    private Filesystem $filesystem;
    private string $codebasePath;
    private string $versionFile;
    private string $diffDir;

    public function __construct(
        string $codebasePath,
        string $storageDir,
        ?LoggerInterface $logger = null
    ) {
        $this->codebasePath = $codebasePath;
        $this->logger = $logger ?? new Logger('differential-updater');
        $this->filesystem = new Filesystem();

        // Create storage directory if it doesn't exist
        $this->filesystem->mkdir($storageDir, 0755);

        $this->versionFile = $storageDir . '/version_manifest.md5';
        $this->diffDir = $storageDir . '/diffs';

        // Create diffs directory if it doesn't exist
        $this->filesystem->mkdir($this->diffDir, 0755);
    }

    // Методы класса...
}

<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

/**
 * Interface for language-specific code analyzers
 */
interface LanguageAnalyzerInterface
{
    /**
     * Get regex patterns for identifying semantic units
     */
    public function getSemanticPatterns(): array;

    /**
     * Get patterns for files to exclude from analysis
     */
    public function getExclusionPatterns(): array;

    /**
     * Identify semantic units in code content
     */
    public function identifySemanticUnits(string $content): array;

    /**
     * Get default file extensions for this language
     */
    public function getFileExtensions(): array;

    /**
     * Extract dependencies from the code
     */
    public function extractDependencies(string $content): array;
}

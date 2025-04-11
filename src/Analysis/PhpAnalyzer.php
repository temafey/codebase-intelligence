<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

/**
 * PHP-specific code analyzer
 */
class PhpAnalyzer implements LanguageAnalyzerInterface
{
    /**
     * Get semantic patterns for PHP code
     */
    public function getSemanticPatterns(): array
    {
        return [
            'class_pattern' => '/\s*(abstract\s+|final\s+)?class\s+\w+(\s+extends\s+\w+)?(\s+implements\s+[\w\s,]+)?\s*{/m',
            'function_pattern' => '/\s*(public|protected|private)?\s*(static\s+)?function\s+\w+\s*\(/m',
            'section_pattern' => '/\/\/\s*SECTION:\s*.+$/m',
            'comment_pattern' => '/\/\*\*[\s\S]*?\*\//m',
            'namespace_pattern' => '/\s*namespace\s+[\w\\\\]+;/m',
            'use_pattern' => '/\s*use\s+[\w\\\\]+(\s+as\s+\w+)?;/m',
            'interface_pattern' => '/\s*interface\s+\w+(\s+extends\s+[\w\s,]+)?\s*{/m',
            'trait_pattern' => '/\s*trait\s+\w+\s*{/m',
            'enum_pattern' => '/\s*enum\s+\w+\s*:\s*\w+\s*{/m',
        ];
    }

    /**
     * Get exclusion patterns for PHP projects
     */
    public function getExclusionPatterns(): array
    {
        return [
            'vendor/*',
            'node_modules/*',
            'tests/*',
            'var/*',
            'cache/*',
            'logs/*',
            '.git/*',
        ];
    }

    /**
     * Identify semantic units in PHP code
     */
    public function identifySemanticUnits(string $content): array
    {
        $units = [];
        $patterns = $this->getSemanticPatterns();

        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $units[] = [
                        'position' => $match[1],
                        'type' => $type,
                        'content' => $match[0]
                    ];
                }
            }
        }

        // Sort units by position
        usort($units, fn($a, $b) => $a['position'] <=> $b['position']);

        return $units;
    }

    /**
     * Get default file extensions for PHP
     */
    public function getFileExtensions(): array
    {
        return ['php'];
    }

    /**
     * Extract dependencies from PHP code
     */
    public function extractDependencies(string $content): array
    {
        $dependencies = [];

        // Extract namespace declarations
        if (preg_match('/namespace\s+([\w\\\\]+);/m', $content, $matches)) {
            $dependencies['namespace'] = $matches[1];
        }

        // Extract use statements
        if (preg_match_all('/use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $class = $match[1];
                $alias = $match[2] ?? null;

                $dependencies['imports'][] = [
                    'class' => $class,
                    'alias' => $alias
                ];
            }
        }

        // Extract class/interface/trait extends and implements
        if (preg_match('/class\s+\w+\s+extends\s+([\w\\\\]+)/m', $content, $matches)) {
            $dependencies['extends'] = $matches[1];
        }

        if (preg_match('/class\s+\w+(?:\s+extends\s+[\w\\\\]+)?\s+implements\s+([\w\\\\,\s]+)/m', $content, $matches)) {
            $implements = array_map('trim', explode(',', $matches[1]));
            $dependencies['implements'] = $implements;
        }

        return $dependencies;
    }
}

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/**
 * Detects reusable component candidates in an artifact: MDX-declared and
 * JSX-file components, explicit `data-component` markers, and class tokens
 * that repeat or name a common section. Facts are collected per artifact,
 * merged across staged pages, then finalized into the capped, ranked list.
 */
final class ComponentDetection
{
    /**
     * @param array<int, array<string, mixed>> $sourceDocumentComponents
     * @return array<int, array<string, mixed>>
     */
    public static function detect(array $files, string $entryPath, array $sourceDocumentComponents = array()): array
    {
        return self::finalizeFacts(self::collectFacts($files, $sourceDocumentComponents), $entryPath);
    }

    /**
     * Collect the uncapped sufficient statistics used by component detection.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<int,array<string,mixed>> $sourceDocumentComponents
     * @return array{components:array<int,array<string,mixed>>,classes:array<string,int>}
     */
    public static function collectFacts(array $files, array $sourceDocumentComponents = array()): array
    {
        $components = array();
        $classes = array();
        foreach ( $sourceDocumentComponents as $component ) {
            $key = 'mdx:' . (string) ($component['source'] ?? '') . ':' . (string) ($component['name'] ?? '');
            $components[$key] = $component;
        }

        foreach ( $files as $file ) {
            if ( in_array($file['kind'], array('jsx', 'tsx'), true) && empty($file['binary']) ) {
                foreach ( self::detectJsxFileComponents($file) as $component ) {
                    $components['jsx-file:' . (string) $component['source'] . ':' . (string) $component['name']] = $component;
                }
            }

            if ( 'html' !== $file['kind'] || ! empty($file['binary']) ) {
                continue;
            }

            $content = (string) $file['content'];
            if ( preg_match_all('/data-component\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $name ) {
                    $key = ArtifactPath::sanitizeKey($name);
                    if ( '' === $key ) {
                        continue;
                    }
                    $components['explicit:' . $key] = array(
                        'name'        => $key,
                        'source'      => $file['path'],
                        'signal'      => 'data-component',
                        'occurrences' => ($components['explicit:' . $key]['occurrences'] ?? 0) + 1,
                        'provenance'  => array('source_path' => $file['path']),
                    );
                }
            }

            if ( preg_match_all('/class\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $classList ) {
                    $classTokens = preg_split('/\s+/', trim($classList));
                    foreach ( false === $classTokens ? array() : $classTokens as $class ) {
                        $class = ArtifactPath::sanitizeKey($class);
                        if ( '' === $class || strlen($class) < 3 ) {
                            continue;
                        }
                        $classes[$class] = ($classes[$class] ?? 0) + 1;
                    }
                }
            }
        }

        return array('components' => array_values($components), 'classes' => $classes);
    }

    /**
     * @param array<int,array{components:array<int,array<string,mixed>>,classes:array<string,int>}> $facts
     * @return array{components:array<int,array<string,mixed>>,classes:array<string,int>}
     */
    public static function mergeFacts(array $facts): array
    {
        $components = array();
        $classes = array();
        foreach ($facts as $fact) {
            foreach ($fact['components'] as $component) {
                $identity = (string) ($component['signal'] ?? '') . ':' . (string) ($component['source'] ?? '') . ':' . (string) ($component['name'] ?? '');
                if ('data-component' === ($component['signal'] ?? null)) $identity = 'data-component:' . (string) ($component['name'] ?? '');
                if (isset($components[$identity])) $component['occurrences'] = (int) ($components[$identity]['occurrences'] ?? 1) + (int) ($component['occurrences'] ?? 1);
                $components[$identity] = $component;
            }
            // PHP stores an all-digit class token as an int array key; read it
            // back as the string the source authored. See blocks-engine#3098.
            foreach ($fact['classes'] as $classKey => $count) { $class = (string) $classKey; $classes[$class] = (int) ($classes[$class] ?? 0) + (int) $count; }
        }
        return array('components' => array_values($components), 'classes' => $classes);
    }

    /** @param array{components:array<int,array<string,mixed>>,classes:array<string,int>} $facts @return array<int,array<string,mixed>> */
    public static function finalizeFacts(array $facts, string $entryPath): array
    {
        $components = $facts['components'];

        foreach ( $facts['classes'] as $classKey => $count ) {
            // PHP stores an all-digit class token as an int array key; read it
            // back as the string the source authored. See blocks-engine#3098.
            $class = (string) $classKey;
            if ( $count < 2 && ! preg_match('/(?:card|grid|hero|nav|header|footer|feature|testimonial|pricing|product|gallery|section)/', $class) ) {
                continue;
            }

            $components[] = array(
                'name'        => $class,
                'source'      => $entryPath,
                'signal'      => 'class-token',
                'occurrences' => $count,
                'provenance'  => array('source_path' => $entryPath),
            );
        }

        usort(
            $components,
            static function (array $left, array $right): int {
                $occurrenceComparison = ($right['occurrences'] ?? 1) <=> ($left['occurrences'] ?? 1);
                return 0 !== $occurrenceComparison ? $occurrenceComparison : strcmp((string) $left['name'], (string) $right['name']);
            }
        );

        return array_slice($components, 0, 25);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<int, array<string, mixed>>
     */
    private static function detectJsxFileComponents(array $file): array
    {
        $components = array();
        $content = (string) ($file['content'] ?? '');

        if ( preg_match_all('/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        if ( preg_match_all('/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        return array_map(
            fn (string $name): array => array(
                'name'        => $name,
                'source'      => (string) ($file['path'] ?? ''),
                'signal'      => 'jsx-component-file',
                'occurrences' => 1,
                'provenance'  => array('source_path' => (string) ($file['path'] ?? '')),
            ),
            array_keys($components)
        );
    }
}

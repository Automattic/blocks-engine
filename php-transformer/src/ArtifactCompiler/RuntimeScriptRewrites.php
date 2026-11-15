<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/**
 * Rewrites runtime scripts so the selectors and element ids they query keep
 * resolving after conversion: projected selectors become the generated marker
 * classes, and lookups of superseded ids fall back to a detached element.
 */
final class RuntimeScriptRewrites
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $projections
     * @return array<int, array<string, mixed>>
     */
    public static function applyToFiles(array $files, array $projections): array
    {
        $byPath = self::runtimeScriptProjectionMap($projections, 'path');

        foreach ( $files as &$file ) {
            $pathProjection = $byPath[$file['path'] ?? ''] ?? null;
            if ( ! is_array($pathProjection) || ! in_array($file['kind'] ?? '', array('js', 'mjs'), true) || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            $content = self::projectRuntimeScriptContent($file['content'], $pathProjection);
            if ( $content === $file['content'] ) {
                continue;
            }
            $file = ArtifactNormalizer::withTextPayload($file, $content);
            $file['provenance']['projected_from_hash'] = $file['provenance']['hash'] ?? '';
            $file['provenance']['hash'] = hash('sha256', $content);
        }
        unset($file);

        return $files;
    }

    /** @param array<string, mixed> $package @param array<int, array<string, mixed>> $projections @return array<string, mixed> */
    public static function applyToPackage(array $package, array $projections): array
    {
        if ( ! is_array($package['islands'] ?? null) ) {
            return $package;
        }
        foreach ( $package['islands'] as &$island ) {
            if ( ! is_array($island['scripts'] ?? null) ) {
                continue;
            }
            foreach ( $island['scripts'] as &$script ) {
                if ( ! is_string($script['content'] ?? null) ) {
                    continue;
                }
                $projection = self::runtimeScriptProjectionForContent($script['content'], $projections);
                if ( is_array($projection) ) {
                    $script['content'] = self::projectRuntimeScriptContent($script['content'], $projection);
                }
            }
            unset($script);
        }
        unset($island);

        return $package;
    }

    /** @param array<int, array<string, mixed>> $projections @return array<string, array<string, true>> */
    private static function runtimeScriptProjectionForContent(string $content, array $projections): array
    {
        $matched = array();
        foreach ( $projections as $projection ) {
            if ( ! is_array($projection) ) {
                continue;
            }
            foreach ( $projection['selectors'] ?? array() as $selector => $markers ) {
                if ( ! is_string($selector) || ! is_array($markers) || ! preg_match('~(?:querySelector(?:All)?|closest|matches)\s*\(\s*(["\'])' . preg_quote($selector, '~') . '\1~', $content) ) {
                    continue;
                }
                foreach ( $markers as $marker ) {
                    if ( is_string($marker) && '' !== $marker ) {
                        $matched[$selector][$marker] = true;
                    }
                }
            }
            foreach ( $projection['superseded_ids'] ?? array() as $id ) {
                if ( is_string($id) && '' !== $id && preg_match('~getElementById\s*\(\s*(["\'])' . preg_quote($id, '~') . '\1\s*\)~', $content) ) {
                    $matched['superseded_ids'][$id] = true;
                }
            }
        }

        return $matched;
    }

    /** @param array<int, array<string, mixed>> $projections @return array<string, array<string, array<string, true>>> */
    private static function runtimeScriptProjectionMap(array $projections, string $identityKey): array
    {
        $map = array();
        foreach ( $projections as $projection ) {
            if ( ! is_array($projection) ) {
                continue;
            }
            $identity = $projection[$identityKey] ?? null;
            if ( ! is_string($identity) || '' === $identity || ! is_array($projection['selectors'] ?? null) || ! is_array($projection['superseded_ids'] ?? null) ) {
                continue;
            }
            foreach ( $projection['selectors'] as $selector => $markers ) {
                if ( ! is_string($selector) || ! is_array($markers) ) {
                    continue;
                }
                foreach ( $markers as $marker ) {
                    if ( is_string($marker) && '' !== $marker ) {
                        $map[$identity][$selector][$marker] = true;
                    }
                }
            }
            foreach ( $projection['superseded_ids'] as $id ) {
                if ( is_string($id) && '' !== $id ) {
                    $map[$identity]['superseded_ids'][$id] = true;
                }
            }
        }

        return $map;
    }

    /** @param array<string, array<string, true>> $projection */
    private static function projectRuntimeScriptContent(string $content, array $projection): string
    {
        foreach ( $projection as $selector => $markers ) {
            if ( 'superseded_ids' === $selector ) {
                continue;
            }
            $projectedSelector = implode(',', array_map(static fn (string $marker): string => '.' . $marker, array_keys($markers)));
            $selectorPattern = preg_quote($selector, '~');
            $content = preg_replace_callback(
                '~((?:querySelector(?:All)?|closest|matches)\s*\(\s*)(["\'])' . $selectorPattern . '\2~',
                static fn (array $match): string => $match[1] . $match[2] . $projectedSelector . $match[2],
                $content
            ) ?? $content;
        }
        foreach ( array_keys($projection['superseded_ids'] ?? array()) as $id ) {
            $content = preg_replace_callback(
                '~document\s*\.\s*getElementById\s*\(\s*(["\'])' . preg_quote($id, '~') . '\1\s*\)~',
                static fn (array $match): string => '(' . $match[0] . " || document.createElement('div'))",
                $content
            ) ?? $content;
        }

        return $content;
    }
}

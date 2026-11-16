<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/**
 * Carries a document's materialized inline scripts into its runtime islands:
 * each island lists the inline scripts that reference it as required scripts,
 * and every inline script without an island becomes a preserved script island.
 */
final class InlineScriptIslands
{
    /**
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public static function withInlineScripts(array $runtimeIslands, string $sourcePath, array $files): array
    {
        $files = ArtifactNormalizer::inlineOccurrences($files);
        $inlineScripts = array_values(array_filter($files, fn (mixed $file): bool => is_array($file) && 'inline-script' === ($file['source'] ?? '') && $sourcePath === ($file['source_path'] ?? '') && ArtifactFileIndex::isMaterializedScriptAsset($file)));
        foreach ( $runtimeIslands as &$runtimeIsland ) {
            if ( ! is_array($runtimeIsland) || 'script' === ($runtimeIsland['kind'] ?? '') ) {
                continue;
            }
            $requiredScripts = ArrayField::array($runtimeIsland, 'required_scripts');
            foreach ( $inlineScripts as $file ) {
                $content = is_scalar($file['content'] ?? null) ? trim((string) $file['content']) : '';
                if ( '' === $content || ! self::inlineScriptReferencesRuntimeIsland($content, $runtimeIsland) ) {
                    continue;
                }
                $requiredScripts[] = array_filter(array(
                    'script_source_kind' => 'inline',
                    'script_role'        => 'first_party',
                    'selector'           => is_scalar($file['selector'] ?? null) ? (string) $file['selector'] : '',
                    'script_body'        => $content,
                    'body_bytes'         => strlen($content),
                    'body_truncated'     => false,
                    'attributes'         => self::inlineScriptAttributes($file),
                ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
            }
            $runtimeIsland['required_scripts'] = SourceDom::dedupeArrayRows($requiredScripts);
        }
        unset($runtimeIsland);

        foreach ( $files as $file ) {
            if ( ! is_array($file) || 'inline-script' !== ($file['source'] ?? '') || $sourcePath !== ($file['source_path'] ?? '') || ! ArtifactFileIndex::isMaterializedScriptAsset($file) ) {
                continue;
            }

            $selector = is_scalar($file['selector'] ?? null) ? (string) $file['selector'] : '';
            $content = is_scalar($file['content'] ?? null) ? trim((string) $file['content']) : '';
            if ( '' === $selector || '' === $content || self::hasRuntimeIsland($runtimeIslands, 'script', $selector) ) {
                continue;
            }

            $attributes = self::inlineScriptAttributes($file);

            $attributeHtml = '';
            foreach ( $attributes as $name => $value ) {
                if ( $name === $value ) {
                    $attributeHtml .= ' ' . $name;
                    continue;
                }
                $attributeHtml .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }

            $runtimeIslands[] = array_filter(array(
                'kind'                => 'script',
                'selector'            => $selector,
                'tag'                 => 'script',
                'diagnostic_code'     => 'preserved_runtime_island',
                'preservation_reason' => 'script_requires_runtime',
                'runtime_requirement' => 'client_script_execution',
                'disposition'         => 'preserve',
                'preservation_status' => 'accepted_runtime_preservation',
                'js_handling'         => 'preserve_verbatim',
                'source_snippet'      => '<script' . $attributeHtml . '></script>',
                'source_bytes'        => strlen($content),
                'source_truncated'    => false,
                'attributes'          => $attributes,
                'script_role'         => 'first_party',
                'script_source_kind'  => 'inline',
                'script_body'         => $content,
                'body_bytes'          => strlen($content),
                'body_truncated'      => false,
                'required_assets'     => array(),
                'required_scripts'    => array(),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
        }

        return $runtimeIslands;
    }

    /**
     * @param array<int, array<string, mixed>> $runtimeIslands
     */
    private static function hasRuntimeIsland(array $runtimeIslands, string $kind, string $selector): bool
    {
        foreach ( $runtimeIslands as $island ) {
            if ( is_array($island) && $kind === ($island['kind'] ?? '') && $selector === ($island['selector'] ?? '') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $runtimeIsland
     */
    private static function inlineScriptReferencesRuntimeIsland(string $content, array $runtimeIsland): bool
    {
        $attributes = ArrayField::array($runtimeIsland, 'attributes');
        $id = is_scalar($attributes['id'] ?? null) ? trim((string) $attributes['id']) : '';
        if ( '' !== $id && str_contains($content, $id) ) {
            return true;
        }

        $classes = preg_split('/\s+/', is_scalar($attributes['class'] ?? null) ? trim((string) $attributes['class']) : '') ?: array();
        foreach ( $classes as $class ) {
            if ( '' !== $class && str_contains($content, $class) ) {
                return true;
            }
        }

        $selector = is_scalar($runtimeIsland['selector'] ?? null) ? trim((string) $runtimeIsland['selector']) : '';
        return '' !== $selector && str_contains($content, $selector);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, string>
     */
    private static function inlineScriptAttributes(array $file): array
    {
        $attributes = array();
        if ( isset($file['type']) && is_scalar($file['type']) && '' !== trim((string) $file['type']) ) {
            $attributes['type'] = (string) $file['type'];
        }
        foreach ( array('defer', 'async') as $field ) {
            if ( ! empty($file[$field]) ) {
                $attributes[$field] = $field;
            }
        }

        return $attributes;
    }
}

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary;

/**
 * Reads transform options: the generated block namespace, the asset metadata
 * carried by provenance, context or the options themselves, runtime script and
 * selector evidence, and shared stylesheet paths.
 */
final class TransformOptions
{
    /**
     * Resolve the generated custom-block namespace from transform options,
     * defaulting to a generic namespace for standalone transforms.
     *
     * @param array<string, mixed> $options
     */
    public static function generatedBlockNamespaceFromOptions(array $options): string
    {
        $namespace = is_scalar($options['generated_block_namespace'] ?? null) ? trim((string) $options['generated_block_namespace']) : '';

        return '' !== $namespace ? $namespace : 'custom';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    public static function assetMetadataFromOptions(array $options): array
    {
        $metadata = array();

        foreach ( array( $options['provenance'] ?? null, $options['context'] ?? null, $options ) as $container ) {
            if ( ! is_array($container) || ! isset($container['asset_metadata']) || ! is_array($container['asset_metadata']) ) {
                continue;
            }

            foreach ( $container['asset_metadata'] as $path => $asset ) {
                if ( ! is_string($path) || '' === trim($path) || ! is_array($asset) ) {
                    continue;
                }

                $metadata[trim($path)] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public static function runtimeScriptMetadataFromOptions(array $options): array
    {
        $metadata = array();
        foreach ( $options['runtime_script_metadata'] ?? array() as $script ) {
            if ( ! is_array($script) ) {
                continue;
            }

            $metadata[] = array_filter(array(
                'path'               => ArrayField::string($script, 'path'),
                'selector'           => ArrayField::string($script, 'selector'),
                'attributes'         => ArrayField::array($script, 'attributes'),
                'script_role'        => 'runtime',
                'script_source_kind' => is_string($script['script_source_kind'] ?? null) ? $script['script_source_kind'] : 'external',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return SourceDom::dedupeArrayRows($metadata);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    public static function runtimeSelectorsFromOptions(array $options, string $key): array
    {
        $selectors = array();
        foreach ( $options[$key] ?? array() as $selector ) {
            if ( is_string($selector) && RuntimeSelectorVocabulary::isBoundedScriptSelector($selector) ) {
                $selectors[$selector] = true;
            }
        }

        return $selectors;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, true>
     */
    public static function sharedStylesheetPaths(array $options): array
    {
        $paths = array();
        foreach ( ArrayField::array($options, 'shared_stylesheet_paths') as $path ) {
            if ( is_string($path) && '' !== $path ) {
                $paths[$path] = true;
            }
        }

        return $paths;
    }
}

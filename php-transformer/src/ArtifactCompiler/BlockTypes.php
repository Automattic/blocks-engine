<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/**
 * Block-type artifacts for generated block directories: every `block.json`
 * root becomes one `wordpress-block-type-artifact/v1` entry with its metadata,
 * asset and dependency contracts, provenance hash and file list, sorted by name.
 */
final class BlockTypes
{
    /**
     * @param array<int, array<string, mixed>> $files
     */
    private static function fileHashPayload(array $files): string
    {
        $payload = '';
        foreach ( $files as $file ) {
            $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (string) $file['content'];
            $payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0" . $content . "\0";
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public static function detect(array $files, array &$diagnostics): array
    {
        $blockTypes = array();
        $blockRoots = array();

        foreach ( $files as $file ) {
            if ( 'block.json' !== basename((string) $file['path']) ) {
                continue;
            }
            $directory = dirname((string) $file['path']);
            $directory = '.' === $directory ? '' : $directory;
            $blockRoots[$directory] = $file;
        }

        foreach ( $blockRoots as $directory => $blockJsonFile ) {
            $blockJson = json_decode((string) $blockJsonFile['content'], true);
            if ( ! is_array($blockJson) ) {
                $blockJson = array();
                $diagnostics[] = ArtifactDiagnostics::make('invalid_block_json', 'warning', 'A generated block.json file could not be decoded.', array('path' => $blockJsonFile['path']));
            }

            $name = isset($blockJson['name']) && is_string($blockJson['name']) ? trim($blockJson['name']) : '';
            if ( '' === $name ) {
                $name = 'generated/' . ('' === $directory ? 'block' : ArtifactPath::sanitizeKey(basename($directory)));
                $diagnostics[] = ArtifactDiagnostics::make('block_json_missing_name', 'warning', 'A generated block.json file did not declare a name; a stable generated name was assigned.', array('path' => $blockJsonFile['path'], 'name' => $name));
            }

            $blockFiles = self::filesUnderDirectory($files, $directory);
            $blockTypes[] = array(
                'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
                'name'            => $name,
                'slug'            => ArtifactPath::sanitizeKey(basename($name)),
                'directory'       => $directory,
                'block_json_path' => $blockJsonFile['path'],
                'block_json'      => $blockJson,
                'metadata'        => self::blockMetadataContract($blockJson),
                'assets'          => self::blockAssetContract($blockJson, $blockFiles),
                'dependencies'    => self::blockDependencyContract($blockJson, $blockFiles),
                'provenance'      => array(
                    'source'      => $blockJsonFile['source'] ?? 'artifact',
                    'source_hash' => hash('sha256', self::fileHashPayload($blockFiles)),
                    'files'       => array_values(array_map(static fn (array $file): string => (string) $file['path'], $blockFiles)),
                ),
                'files'           => array_values(
                    array_map(
                        static fn (array $file): array => array(
                            'path'  => $file['path'],
                            'kind'  => $file['kind'],
                            'bytes' => $file['bytes'],
                        ),
                        $blockFiles
                    )
                ),
            );
        }

        usort(
            $blockTypes,
            static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        return $blockTypes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private static function filesUnderDirectory(array $files, string $directory): array
    {
        $matched = array();
        $prefix = '' === $directory ? '' : $directory . '/';
        foreach ( $files as $file ) {
            if ( '' === $prefix || str_starts_with((string) $file['path'], $prefix) ) {
                $matched[] = $file;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @return array<string, mixed>
     */
    private static function blockMetadataContract(array $blockJson): array
    {
        $metadata = array();
        foreach ( array('apiVersion', 'title', 'category', 'description', 'keywords', 'attributes', 'supports', 'usesContext', 'providesContext', 'textdomain', 'example', 'variations', 'parent', 'ancestor', 'allowedBlocks') as $key ) {
            if ( array_key_exists($key, $blockJson) ) {
                $metadata[$key] = $blockJson[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function blockAssetContract(array $blockJson, array $files): array
    {
        $assets = array(
            'render'        => array(),
            'editor_script' => array(),
            'script'        => array(),
            'view_script'   => array(),
            'editor_style'  => array(),
            'style'         => array(),
            'view_style'    => array(),
        );

        foreach ( array(
            'render'       => 'render',
            'editorScript' => 'editor_script',
            'script'       => 'script',
            'viewScript'   => 'view_script',
            'editorStyle'  => 'editor_style',
            'style'        => 'style',
            'viewStyle'    => 'view_style',
        ) as $sourceField => $targetField ) {
            foreach ( self::normalizeAssetReferences($blockJson[$sourceField] ?? null, $files, $sourceField) as $reference ) {
                $assets[$targetField][] = $reference;
            }
        }

        return $assets;
    }

    /**
     * @param mixed $value
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeAssetReferences(mixed $value, array $files, string $sourceField): array
    {
        $references = array();
        $values = is_array($value) ? array_values($value) : array($value);
        foreach ( $values as $item ) {
            if ( ! is_string($item) || '' === trim($item) ) {
                continue;
            }

            $item = trim($item);
            $isFileRef = str_starts_with($item, 'file:');
            $file = $isFileRef ? self::findBlockFileByRelativePath($files, substr($item, 5)) : null;

            $reference = array(
                'reference'    => $item,
                'source_field' => $sourceField,
                'type'         => $isFileRef ? 'file' : 'handle',
            );
            if ( is_array($file) ) {
                $reference['path'] = $file['path'];
                $reference['kind'] = $file['kind'];
                $reference['bytes'] = $file['bytes'];
            }

            $references[] = $reference;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private static function blockDependencyContract(array $blockJson, array $files): array
    {
        $declared = array();
        foreach ( array('editorScript', 'script', 'viewScript', 'editorStyle', 'style', 'viewStyle') as $field ) {
            if ( array_key_exists($field, $blockJson) ) {
                $declared[$field] = $blockJson[$field];
            }
        }

        $assetFiles = array();
        foreach ( $files as $file ) {
            if ( ! str_ends_with((string) $file['path'], '.asset.php') ) {
                continue;
            }

            $assetFile = array(
                'path'  => $file['path'],
                'kind'  => $file['kind'],
                'bytes' => $file['bytes'],
            );
            $parsed = self::parseAssetPhpManifest((string) ($file['content'] ?? ''));
            if ( array() !== $parsed ) {
                $assetFile['manifest'] = $parsed;
            }
            $assetFiles[] = $assetFile;
        }

        return array(
            'declared'    => $declared,
            'asset_files' => $assetFiles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseAssetPhpManifest(string $content): array
    {
        $manifest = array();
        if ( preg_match('/["\']version["\']\s*=>\s*["\']([^"\']+)["\']/', $content, $version) ) {
            $manifest['version'] = $version[1];
        }
        if ( preg_match('/["\']dependencies["\']\s*=>\s*array\s*\((.*?)\)/s', $content, $dependencies) && preg_match_all('/["\']([^"\']+)["\']/', $dependencies[1], $matches) ) {
            $manifest['dependencies'] = array_values($matches[1]);
        }

        return $manifest;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private static function findBlockFileByRelativePath(array $files, string $relativePath): ?array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), './');
        foreach ( $files as $file ) {
            if ( basename((string) $file['path']) === $relativePath || str_ends_with((string) $file['path'], '/' . $relativePath) ) {
                return $file;
            }
        }

        return null;
    }
}

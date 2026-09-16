<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/**
 * Versioned provenance record for a generated artifact.
 *
 * Generated themes and companion plugins are deterministic, so they are
 * structurally identical across sites; this record is the stamp that makes a
 * generated artifact findable and updatable after materialization. It carries
 * the generator identity, the engine version that produced the artifact, and a
 * deterministic hash of the normalized artifact input, in the same
 * product-neutral style as {@see CompanionPluginPayload::SCHEMA}.
 */
final class GeneratedArtifactProvenance
{
    /**
     * Product-neutral contract identifier owned by Blocks Engine.
     */
    public const SCHEMA = 'blocks-engine/generated-artifact-provenance/v1';

    /**
     * Stable identifier of the generating engine package.
     */
    public const GENERATOR = 'automattic/blocks-engine-php-transformer';

    /**
     * Build the provenance record for one artifact compilation.
     *
     * The inputs are the same normalized inputs the companion payload is built
     * from, so the record describes exactly what was compiled. The raw
     * artifact envelope is reduced to its resolved site identity first:
     * inline compilation and staged receipt composition present different
     * envelopes for the same artifact, and two runs over the same input must
     * produce the same record whichever path compiled it. Any input change
     * changes the hash.
     *
     * @param array<int, array<string, mixed>> $blockTypes      Block-type artifacts from detectBlockTypes().
     * @param array<int, array<string, mixed>> $files           Normalized artifact files (carry content).
     * @param array<string, mixed>             $artifact        Raw artifact envelope (for site identity).
     * @param array<int, array<string, mixed>> $generatedBlocks Static-render blocks generated at core/html fallbacks.
     * @param array<string, mixed>             $runtimeIslandPackage Generic runtime-island package.
     * @param array<int, array<string, mixed>> $editorScripts  Editor-only scripts for existing core blocks.
     * @param array<string, bool>              $themeOwnedRequiredScripts Theme-owned required scripts keyed by source path and selector.
     * @return array<string, string>
     */
    public function fromArtifactInputs(array $blockTypes, array $files, array $artifact, array $generatedBlocks = array(), array $runtimeIslandPackage = array(), array $editorScripts = array(), array $themeOwnedRequiredScripts = array()): array
    {
        return array(
            'schema' => self::SCHEMA,
            'generator' => self::GENERATOR,
            'engine_version' => self::engineVersion(),
            'artifact_hash' => $this->artifactHash(array(
                'block_types' => $blockTypes,
                'files' => $files,
                'artifact_identity' => (new CompanionPluginPayload())->siteIdentity($artifact),
                'generated_blocks' => $generatedBlocks,
                'runtime_island_package' => $runtimeIslandPackage,
                'editor_scripts' => $editorScripts,
                'theme_owned_required_scripts' => $themeOwnedRequiredScripts,
            )),
        );
    }

    /**
     * Deterministic hash of the normalized artifact input.
     *
     * @param array<string, mixed> $inputs
     */
    public function artifactHash(array $inputs): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize($inputs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The package version from the committed VERSION file.
     *
     * `src/` must not depend on the plugin bootstrap being loaded, so the
     * committed VERSION file (shipped in the dist) is the primary source; the
     * bootstrap constant is only consulted when the file is unavailable.
     */
    public static function engineVersion(): string
    {
        $versionFile = dirname(__DIR__, 2) . '/VERSION';
        if ( is_readable($versionFile) ) {
            $version = trim((string) file_get_contents($versionFile));
            if ( '' !== $version && 1 === preg_match('/^\d+\.\d+\.\d+/', $version) ) {
                return $version;
            }
        }

        if ( defined('BLOCKS_ENGINE_PHP_TRANSFORMER_VERSION') ) {
            return (string) constant('BLOCKS_ENGINE_PHP_TRANSFORMER_VERSION');
        }

        return '0.0.0';
    }

    /**
     * Recursively key-sort arrays so input key order never changes the hash.
     */
    private function canonicalize(mixed $value): mixed
    {
        if ( ! is_array($value) ) {
            return $value;
        }

        $canonical = array();
        foreach ( $value as $key => $item ) {
            $canonical[$key] = $this->canonicalize($item);
        }
        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}

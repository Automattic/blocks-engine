<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/**
 * Producer for the companion-plugin payload consumed by Static Site Importer.
 *
 * This is the producer half of the companion-plugin / plugin-materialization
 * keystone (issue #491). Slice 1 (SSI #492) built the consumer:
 * Static_Site_Importer_Companion_Plugin::scaffold() turns a payload into an
 * installable, theme-independent plugin that houses generated custom blocks
 * (registered from their own block.json) and preserved island JS. This class is
 * the producer seam: it packages the generated block definitions the artifact
 * already carries (block.json + render + view JS + assets) into a payload whose
 * shape exactly matches what scaffold() consumes.
 *
 * Contract (consumed by scaffold(), keys it reads):
 *   - site_slug   (string)  per-site naming; SSI may override at install time.
 *   - site_name   (string)  optional human-readable name; defaults to slug.
 *   - mu_plugin   (bool)    optional; emit as a must-use loader.
 *   - blocks[]    (array)   each: name, block_json, render, view_js, assets{}.
 *   - preserved_js[] (array) each: content, handle, src, block, selector,
 *                            source_path. Populated from the generic runtime
 *                            island package for downstream plugin materialization.
 *
 * When the artifact carries no custom blocks (mapping still prefers
 * core/Automattic blocks with a core/html fallback, and no subtree qualified for
 * generation), the payload is empty and the compiler omits it. This class does
 * not decide what becomes a custom block; it packages two sources into the
 * scaffold contract: block types the artifact already declares via block.json,
 * and the dynamic blocks the transformer generated at core/html fallbacks
 * (issue #497), which already arrive in the per-block shape.
 */
final class CompanionPluginPayload
{
    /**
     * Shared contract identifier. Mirrors the consumer schema declared by
     * Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA so SSI can assert
     * conformance. scaffold() does not require it, but stamping it makes the
     * producer<->consumer contract explicit and greppable across repos.
     */
    public const SCHEMA = 'static-site-importer/companion-plugin/v1';

    /**
     * Build the companion-plugin payload from detected generated blocks.
     *
     * @param array<int, array<string, mixed>> $blockTypes      Block-type artifacts from detectBlockTypes().
     * @param array<int, array<string, mixed>> $files           Normalized artifact files (carry content).
     * @param array<string, mixed>             $artifact        Raw artifact envelope (for site identity).
     * @param array<int, array<string, mixed>> $generatedBlocks     Dynamic blocks generated at core/html fallbacks (issue #497).
     * @param array<string, mixed>             $runtimeIslandPackage Generic runtime-island package.
     * @return array<string, mixed> Empty array when there are no generated blocks or preserved scripts.
     */
    public function fromBlockTypes(array $blockTypes, array $files, array $artifact, array $generatedBlocks = array(), array $runtimeIslandPackage = array()): array
    {
        $blocks = array();
        $seenNames = array();
        foreach ( $blockTypes as $blockType ) {
            if ( ! is_array($blockType) ) {
                continue;
            }
            $block = $this->buildBlock($blockType, $files);
            if ( array() !== $block ) {
                $blocks[] = $block;
                $seenNames[(string) ($block['name'] ?? '')] = true;
            }
        }

        // Append dynamically generated custom blocks (the classify -> route ->
        // generate producer link). These already arrive in scaffold()'s per-block
        // shape, so they only need normalizing and name-deduping against detected
        // block types.
        foreach ( $generatedBlocks as $generatedBlock ) {
            $block = $this->normalizeGeneratedBlock($generatedBlock);
            if ( array() === $block ) {
                continue;
            }
            $name = (string) $block['name'];
            if ( isset($seenNames[$name]) ) {
                continue;
            }
            $seenNames[$name] = true;
            $blocks[] = $block;
        }

        $preservedJs = $this->preservedJs($runtimeIslandPackage);
        $runtimeEffects = $this->runtimeEffects($runtimeIslandPackage);
        if ( array() === $blocks && array() === $preservedJs && array() === $runtimeEffects['units'] ) {
            return array();
        }

        $payload = array(
            'schema' => self::SCHEMA,
            'blocks' => $blocks,
            'preserved_js' => $preservedJs,
        );
        if (array() !== $runtimeEffects['units']) $payload['runtime_effects'] = $runtimeEffects;

        $siteSlug = $this->siteSlug($artifact);
        if ( '' !== $siteSlug ) {
            $payload['site_slug'] = $siteSlug;
        }
        $siteName = $this->siteName($artifact);
        if ( '' !== $siteName ) {
            $payload['site_name'] = $siteName;
        }
        if ( $this->muPlugin($artifact) ) {
            $payload['mu_plugin'] = true;
        }

        return $payload;
    }

    /**
     * Map preserved first-party runtime scripts into SSI's companion payload.
     *
     * @param array<string, mixed> $runtimeIslandPackage
     * @return array<int, array<string, string>>
     */
    private function preservedJs(array $runtimeIslandPackage): array
    {
        $entries = array();
        $seen = array();
        $islands = array_values(array_filter(
            is_array($runtimeIslandPackage['islands'] ?? null) ? $runtimeIslandPackage['islands'] : array(),
            static fn (mixed $island): bool => is_array($island)
        ));
        usort($islands, static fn (array $left, array $right): int => ('script' === ($right['kind'] ?? '')) <=> ('script' === ($left['kind'] ?? '')));
        foreach ( $islands as $island ) {
            if ( ! is_array($island) || 'preserve' !== ($island['disposition'] ?? '') ) {
                continue;
            }
            $handleHint = $this->sanitizeSlug((string) ($island['handle_hint'] ?? 'runtime'));
            foreach ( $island['scripts'] ?? array() as $index => $script ) {
                if ( ! is_array($script) || 'telemetry' === ($script['role'] ?? '') ) {
                    continue;
                }
                $content = is_scalar($script['content'] ?? null) ? trim((string) $script['content']) : '';
                if ( '' === $content ) {
                    continue;
                }
                if ($this->allUnitsSuppressible($script['region_effects'] ?? null)) continue;
                $handle = trim($handleHint . '-' . ((int) $index + 1), '-');
                $block = '';
                $signature = hash('sha256', $block . "\0" . $content);
                if ( isset($seen[$signature]) ) {
                    continue;
                }
                $seen[$signature] = true;
                $entries[] = array_filter(array(
                    'handle'      => $handle,
                    'content'     => $content,
                    'block'       => $block,
                    'selector'    => is_scalar($island['selector'] ?? null) ? (string) $island['selector'] : '',
                    'source_path' => is_scalar($island['source_path'] ?? null) ? (string) $island['source_path'] : '',
                ), static fn (string $value): bool => '' !== $value);
            }
        }

        return $entries;
    }

    /** @return array{units:array<int,array<string,mixed>>,retained_modules:array<int,array<string,string>>} */
    private function runtimeEffects(array $runtimeIslandPackage): array
    {
        $units = array();
        $modules = array();
        $seen = array();
        $islands = array_values(array_filter($runtimeIslandPackage['islands'] ?? array(), 'is_array'));
        usort($islands, static fn(array $left, array $right): int => ('script' === ($right['kind'] ?? '')) <=> ('script' === ($left['kind'] ?? '')));
        foreach ($islands as $island) {
            if (!is_array($island)) continue;
            foreach ($island['scripts'] ?? array() as $script) {
                if (!is_array($script) || !is_string($script['content'] ?? null) || !$this->allUnitsSuppressible($script['region_effects'] ?? null)) continue;
                $manifest = $script['region_effects'];
                foreach ($manifest['units'] as $unit) {
                    if (isset($seen[$unit['id']])) continue;
                    $seen[$unit['id']] = true;
                    $source = $unit['source'];
                    $content = substr($script['content'], $source['start'], $source['end'] - $source['start']);
                    $units[] = $unit;
                    $modules[] = array_filter(array(
                        'unit_id' => $unit['id'],
                        'content' => $content,
                        'selector' => is_scalar($island['selector'] ?? null) ? (string) $island['selector'] : '',
                        'source_path' => is_scalar($island['source_path'] ?? null) ? (string) $island['source_path'] : '',
                    ), static fn(string $value): bool => '' !== $value);
                }
            }
        }
        usort($units, static fn(array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));
        usort($modules, static fn(array $left, array $right): int => strcmp($left['unit_id'], $right['unit_id']));
        return array('units' => $units, 'retained_modules' => $modules);
    }

    private function allUnitsSuppressible(mixed $manifest): bool
    {
        if (!is_array($manifest) || !is_array($manifest['units'] ?? null) || array() === $manifest['units']) return false;
        foreach ($manifest['units'] as $unit) if (!is_array($unit) || 'independently_suppressible' !== ($unit['status'] ?? null) || !is_array($unit['source'] ?? null)) return false;
        return true;
    }

    /**
     * Per-site companion-plugin block namespace (`ssi-<site_slug>`), or '' when
     * the artifact carries no resolvable site identity. The producer emits
     * generated-block references under this namespace so they match the blocks
     * the SSI scaffold registers.
     *
     * @param array<string, mixed> $artifact Raw artifact envelope.
     */
    public function blockNamespace(array $artifact): string
    {
        $slug = $this->siteSlug($artifact);

        return '' === $slug ? '' : 'ssi-' . $slug;
    }

    /**
     * Normalize a generated-block entry to scaffold()'s per-block contract.
     * Generated blocks already declare a sanitizable name, a block_json object,
     * and a dynamic render; producer-only diagnostic keys (e.g. signature) are
     * dropped so only contract keys reach SSI.
     *
     * @param array<string, mixed> $block Generated-block entry from the transformer.
     * @return array<string, mixed> Empty array when the entry is unusable.
     */
    private function normalizeGeneratedBlock(array $block): array
    {
        $name = is_scalar($block['name'] ?? null) ? $this->sanitizeSlug((string) $block['name']) : '';
        if ( '' === $name ) {
            return array();
        }

        $blockJson = is_array($block['block_json'] ?? null) ? $block['block_json'] : array();
        if ( array() === $blockJson ) {
            return array();
        }

        $normalized = array(
            'name'       => $name,
            'block_json' => $blockJson,
        );

        if ( is_scalar($block['render'] ?? null) && '' !== (string) $block['render'] ) {
            $normalized['render'] = (string) $block['render'];
        }
        if ( is_scalar($block['view_js'] ?? null) && '' !== (string) $block['view_js'] ) {
            $normalized['view_js'] = (string) $block['view_js'];
        }
        if ( is_array($block['assets'] ?? null) && array() !== $block['assets'] ) {
            $normalized['assets'] = $block['assets'];
        }

        return $normalized;
    }

    /**
     * Build one block entry conforming to scaffold()'s per-block contract.
     *
     * @param array<string, mixed>             $blockType One detectBlockTypes() entry.
     * @param array<int, array<string, mixed>> $files     Normalized artifact files.
     * @return array<string, mixed> Empty array when the block cannot be packaged.
     */
    private function buildBlock(array $blockType, array $files): array
    {
        $name = is_scalar($blockType['slug'] ?? null) ? (string) $blockType['slug'] : '';
        if ( '' === $name ) {
            $fqn = is_scalar($blockType['name'] ?? null) ? (string) $blockType['name'] : '';
            $name = '' === $fqn ? '' : (string) substr(strrchr('/' . $fqn, '/') ?: '', 1);
        }
        if ( '' === $name ) {
            return array();
        }

        $blockJson = is_array($blockType['block_json'] ?? null) ? $blockType['block_json'] : array();
        if ( array() === $blockJson ) {
            return array();
        }

        $directory = is_scalar($blockType['directory'] ?? null) ? (string) $blockType['directory'] : '';
        $contents = $this->blockFileContents($files, $directory);

        $blockJsonPath = is_scalar($blockType['block_json_path'] ?? null) ? (string) $blockType['block_json_path'] : '';
        $blockJsonRel = $this->relativePath($blockJsonPath, $directory);

        $renderPath = $this->firstReferencedFilePath($blockType, 'render');
        $renderRel = $this->relativePath($renderPath, $directory);
        $viewJsPath = $this->firstReferencedFilePath($blockType, 'view_script');
        $viewJsRel = $this->relativePath($viewJsPath, $directory);

        // Paths handled by dedicated keys are excluded from the generic assets
        // map so scaffold() does not write them twice.
        $handled = array_filter(array($blockJsonRel, $renderRel, $viewJsRel), static fn (string $p): bool => '' !== $p);

        $block = array(
            'name'       => $name,
            'block_json' => $blockJson,
        );

        if ( '' !== $renderRel && array_key_exists($renderRel, $contents) ) {
            $block['render'] = $contents[$renderRel];
        }
        if ( '' !== $viewJsRel && array_key_exists($viewJsRel, $contents) ) {
            $block['view_js'] = $contents[$viewJsRel];
        }

        $assets = array();
        foreach ( $contents as $relative => $content ) {
            if ( in_array($relative, $handled, true) ) {
                continue;
            }
            $assets[$relative] = $content;
        }
        if ( array() !== $assets ) {
            ksort($assets);
            $block['assets'] = $assets;
        }

        return $block;
    }

    /**
     * Collect block-directory file contents keyed by path relative to the block.
     *
     * @param array<int, array<string, mixed>> $files     Normalized artifact files.
     * @param string                           $directory Block source directory.
     * @return array<string, string>
     */
    private function blockFileContents(array $files, string $directory): array
    {
        $prefix = '' === $directory ? '' : rtrim($directory, '/') . '/';
        $contents = array();
        foreach ( $files as $file ) {
            $path = is_scalar($file['path'] ?? null) ? (string) $file['path'] : '';
            if ( '' === $path ) {
                continue;
            }
            if ( '' !== $prefix && ! str_starts_with($path, $prefix) ) {
                continue;
            }
            $relative = $this->relativePath($path, $directory);
            if ( '' === $relative ) {
                continue;
            }
            $contents[$relative] = $this->fileContent($file);
        }

        return $contents;
    }

    /**
     * Decode a normalized file's content, base64-decoding binary payloads.
     *
     * @param array<string, mixed> $file Normalized file record.
     */
    private function fileContent(array $file): string
    {
        if ( ! empty($file['binary']) && is_scalar($file['content_base64'] ?? null) ) {
            $decoded = base64_decode((string) $file['content_base64'], true);
            if ( false !== $decoded ) {
                return $decoded;
            }
        }

        return is_scalar($file['content'] ?? null) ? (string) $file['content'] : '';
    }

    /**
     * Resolve the first resolved file path for a block asset contract field.
     *
     * @param array<string, mixed> $blockType detectBlockTypes() entry.
     * @param string               $field     Asset contract field, e.g. render.
     */
    private function firstReferencedFilePath(array $blockType, string $field): string
    {
        $references = $blockType['assets'][$field] ?? null;
        if ( ! is_array($references) ) {
            return '';
        }
        foreach ( $references as $reference ) {
            if ( is_array($reference) && 'file' === ($reference['type'] ?? '') && is_scalar($reference['path'] ?? null) ) {
                return (string) $reference['path'];
            }
        }

        return '';
    }

    /**
     * Reduce an artifact-absolute path to a block-directory-relative path.
     */
    private function relativePath(string $path, string $directory): string
    {
        $path = trim($path);
        if ( '' === $path ) {
            return '';
        }
        $prefix = '' === $directory ? '' : rtrim($directory, '/') . '/';
        if ( '' !== $prefix && str_starts_with($path, $prefix) ) {
            return substr($path, strlen($prefix));
        }

        return '' === $prefix ? $path : '';
    }

    /**
     * Sanitized per-site slug from the raw artifact, when carried.
     *
     * @param array<string, mixed> $artifact Raw artifact envelope.
     */
    private function siteSlug(array $artifact): string
    {
        $candidates = array(
            $artifact['site_slug'] ?? null,
            is_array($artifact['site'] ?? null) ? ($artifact['site']['slug'] ?? null) : null,
            is_array($artifact['site'] ?? null) ? ($artifact['site']['name'] ?? null) : null,
            $artifact['name'] ?? null,
        );
        foreach ( $candidates as $candidate ) {
            if ( ! is_scalar($candidate) ) {
                continue;
            }
            $slug = $this->sanitizeSlug((string) $candidate);
            if ( '' !== $slug ) {
                return $slug;
            }
        }

        return '';
    }

    /**
     * Human-readable site name from the raw artifact, when carried.
     *
     * @param array<string, mixed> $artifact Raw artifact envelope.
     */
    private function siteName(array $artifact): string
    {
        $candidates = array(
            $artifact['site_name'] ?? null,
            is_array($artifact['site'] ?? null) ? ($artifact['site']['name'] ?? null) : null,
            is_array($artifact['site'] ?? null) ? ($artifact['site']['title'] ?? null) : null,
        );
        foreach ( $candidates as $candidate ) {
            if ( is_scalar($candidate) && '' !== trim((string) $candidate) ) {
                return trim((string) $candidate);
            }
        }

        return '';
    }

    /**
     * Whether the artifact requests a must-use companion plugin.
     *
     * @param array<string, mixed> $artifact Raw artifact envelope.
     */
    private function muPlugin(array $artifact): bool
    {
        if ( ! empty($artifact['mu_plugin']) ) {
            return true;
        }
        if ( is_array($artifact['site'] ?? null) && ! empty($artifact['site']['mu_plugin']) ) {
            return true;
        }

        return false;
    }

    /**
     * Lowercase, hyphen-delimited slug; portable since WP is not loaded here.
     */
    private function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim((string) $value, '-');
    }
}

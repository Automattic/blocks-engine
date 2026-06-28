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
 *   - preserved_js[] (array) verbatim island JS projected from the generic
 *                            runtime-island package (SSI #488). Two shapes:
 *                            block-scoped entries carry content, handle, src, block
 *                            and are enqueued via render_block when that block renders;
 *                            site-wide entries carry content, handle, src, scope='site',
 *                            order (no `block` key) and are enqueued for the whole site.
 *                            Free-standing behavior islands with no generated-block
 *                            owner take the site-wide shape so their JS lands instead
 *                            of being parked.
 *   - preserved_js_deferred[] (array) each: content, handle, src, reason. Island JS
 *                            whose owning block exists but was not packaged into this
 *                            payload (owner_block_not_packaged) — a genuine anomaly,
 *                            surfaced rather than silently dropped or guessed.
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
     * @param array<int, array<string, mixed>> $generatedBlocks Dynamic blocks generated at core/html fallbacks (issue #497).
     * @param array<string, mixed>             $runtimeIslandPackage Generic runtime-island package feed (issue #488).
     * @return array<string, mixed> Empty array when there are no generated blocks.
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

        if ( array() === $blocks ) {
            // No generated custom blocks: the payload is absent. SSI keeps the
            // existing required-plugin path and the core/html fallback applies.
            return array();
        }

        $preserved = $this->preservedJs($runtimeIslandPackage, $blocks, $this->blockNamespace($artifact));

        $payload = array(
            'schema' => self::SCHEMA,
            'blocks' => $blocks,
            // Preserved island JS projected from the generic runtime-island package:
            // block-scoped to its owning generated block, or site-wide for free-standing
            // behavior islands with no generated-block owner (SSI #488).
            'preserved_js' => $preserved['preserved_js'],
        );
        if ( array() !== $preserved['deferred'] ) {
            $payload['preserved_js_deferred'] = $preserved['deferred'];
        }

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
     * Project preserved island JS from the generic runtime-island package into the
     * scaffold's preserved_js contract (issue #488), gated by the
     * preserve-vs-rebuild signal (issue #224).
     *
     * An island captured at custom-block generation carries the owning block's
     * fully-qualified name (`owner_block`). When that block ships in this payload the
     * island is emitted block-scoped, and the consumer enqueues its JS via render_block
     * only when that block renders. Free-standing islands (canvas, form, and standalone
     * scripts elsewhere in the DOM) have no generated-block owner; they are promoted to
     * site-wide entries (`scope='site'`, no `block`, deterministic `order`) so the
     * consumer enqueues them for the whole site instead of parking the JS. The narrow
     * anomaly where an owner_block is named but was not packaged is still deferred
     * (owner_block_not_packaged) rather than guessed into a site-wide scope.
     * Telemetry/droppable and external-unmaterialized scripts carry no verbatim JS body,
     * so they contribute neither an entry nor a deferral.
     *
     * @param array<string, mixed>             $runtimeIslandPackage Generic runtime-island package.
     * @param array<int, array<string, mixed>> $blocks               Packaged companion blocks.
     * @param string                           $blockNamespace       Per-site block namespace (`ssi-<slug>`).
     * @return array{preserved_js: array<int, array<string, mixed>>, deferred: array<int, array<string, string>>}
     */
    private function preservedJs(array $runtimeIslandPackage, array $blocks, string $blockNamespace): array
    {
        $islands = is_array($runtimeIslandPackage['islands'] ?? null) ? $runtimeIslandPackage['islands'] : array();
        if ( array() === $islands ) {
            return array( 'preserved_js' => array(), 'deferred' => array() );
        }

        // Fully-qualified names of the generated blocks this payload actually
        // packages, so an island scope is only honored when its owning block ships.
        $ownedBlocks = array();
        if ( '' !== $blockNamespace ) {
            foreach ( $blocks as $block ) {
                $name = (string) ($block['name'] ?? '');
                if ( '' !== $name ) {
                    $ownedBlocks[$blockNamespace . '/' . $name] = true;
                }
            }
        }

        $preserved = array();
        $deferred  = array();
        foreach ( $islands as $index => $island ) {
            if ( ! is_array($island) ) {
                continue;
            }
            // Preserve-vs-rebuild gate (#224): only verbatim-preserve islands carry JS.
            if ( 'preserve' !== ($island['disposition'] ?? '') || 'preserve_verbatim' !== ($island['js_handling'] ?? '') ) {
                continue;
            }
            $content = $this->islandScriptContent($island);
            $handle  = is_scalar($island['handle_hint'] ?? null) ? (string) $island['handle_hint'] : '';
            if ( '' === $content || '' === $handle ) {
                // Nothing carryable (telemetry-only or external-unmaterialized) or
                // no stable handle: the consumer requires both, so emit neither an
                // entry nor a deferral.
                continue;
            }

            $entry = array(
                'content' => $content,
                'handle'  => $handle,
                'src'     => 'islands/' . $handle . '.js',
            );

            $owner = is_scalar($island['owner_block'] ?? null) ? (string) $island['owner_block'] : '';
            if ( '' !== $owner && isset($ownedBlocks[$owner]) ) {
                // Scoped to the owning generated block: the consumer enqueues it via
                // render_block only when that block renders (UNCHANGED, #488).
                $entry['block'] = $owner;
                $preserved[]    = $entry;
                continue;
            }

            if ( '' === $owner ) {
                // Free-standing behavior island with no generated-block owner: promote
                // to a site-wide preserved_js entry. The consumer enqueues these for
                // the whole site rather than gating on a block, so the JS lands instead
                // of being parked. Islands arrive ordered, so the package index gives a
                // deterministic enqueue order. No `block` key by contract.
                $entry['scope'] = 'site';
                $entry['order'] = (int) $index;
                $preserved[]    = $entry;
                continue;
            }

            // The owning block exists but was not packaged into this payload — a
            // genuine anomaly. Keep deferring (rather than guessing a site-wide scope)
            // so the loss is visible for follow-up.
            $entry['reason'] = 'owner_block_not_packaged';
            $deferred[]      = $entry;
        }

        return array( 'preserved_js' => $preserved, 'deferred' => $deferred );
    }

    /**
     * The first preserve-worthy verbatim JS body carried by an island's scripts:
     * an inline body or materialized external content. Telemetry/droppable scripts
     * and external-but-unmaterialized scripts contribute no carryable content.
     *
     * @param array<string, mixed> $island One runtime-island-package island.
     */
    private function islandScriptContent(array $island): string
    {
        $scripts = is_array($island['scripts'] ?? null) ? $island['scripts'] : array();
        foreach ( $scripts as $script ) {
            if ( ! is_array($script) || ! empty($script['droppable']) || 'telemetry' === ($script['role'] ?? '') ) {
                continue;
            }
            $content = is_scalar($script['content'] ?? null) ? (string) $script['content'] : '';
            if ( '' !== trim($content) ) {
                return $content;
            }
        }

        return '';
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

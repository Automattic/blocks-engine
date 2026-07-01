<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Emits static HTML artifacts from a normalized scenegraph.
 */
final class StaticHtmlEmitter
{
    /**
     * Minimum intrinsic width (px) at which a page root frame is rendered as a
     * centered fluid container instead of a fixed canvas width. Roots at least
     * this wide are treated as full-page desktop canvases that must fit the
     * viewport; narrower roots are typically embedded components and keep their
     * intrinsic size.
     */
    private const FLUID_ROOT_MIN_WIDTH = 1024.0;

    private const EXTERNAL_VECTOR_SVG_BYTES = 65536;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $assetsById = array();

    /**
     * @var array<string, bool>
     */
    private array $usedAssetPaths = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $generatedAssetFiles = array();

    /**
     * @var array<string, string>
     */
    private array $generatedVectorSvgPathsByHash = array();

    private bool $renderTextGlyphPaths = false;

    private ?FontResolver $fontResolver = null;

    private function fontResolver(): FontResolver
    {
        return $this->fontResolver ??= new FontResolver();
    }

    private ?DesignSystemExtractor $designSystemExtractor = null;

    private ?VectorSvgRenderer $vectorSvgRenderer = null;

    private ?StyleDeclarationBuilder $styleDeclarationBuilder = null;

    private ?TransformDiagnosticsBuilder $transformDiagnosticsBuilder = null;

    private ?EffectOverflowPolicy $effectOverflowPolicy = null;

    private ?CssPositioningResolver $cssPositioningResolver = null;

    private ?StickyLayoutCoordinator $stickyLayoutCoordinator = null;

    private function designSystemExtractor(): DesignSystemExtractor
    {
        return $this->designSystemExtractor ??= new DesignSystemExtractor();
    }

    private function vectorSvgRenderer(): VectorSvgRenderer
    {
        return $this->vectorSvgRenderer ??= new VectorSvgRenderer(
            fn (array $node): array => $this->nodeList($node),
            fn (float $value): string => $this->number($value),
            fn (string $value): string => $this->sanitizeAttribute($value),
            fn (array $paints): ?string => $this->firstSolidPaint($paints),
            fn (array $node): ?string => $this->backgroundColor($node),
            fn (array $node): array => $this->nodeImagePaints($node),
            fn (array $node): array => $this->explicitNodeAssetReferences($node),
        );
    }

    private function styleDeclarationBuilder(): StyleDeclarationBuilder
    {
        return $this->styleDeclarationBuilder ??= new StyleDeclarationBuilder(
            fn (float $value): string => $this->number($value),
            fn (array $paints): ?array => $this->firstCssPaint($paints),
            fn (mixed $value, mixed $opacity = null): ?string => $this->color($value, $opacity),
        );
    }

    private function transformDiagnosticsBuilder(): TransformDiagnosticsBuilder
    {
        return $this->transformDiagnosticsBuilder ??= new TransformDiagnosticsBuilder();
    }

    private function effectOverflowPolicy(): EffectOverflowPolicy
    {
        return $this->effectOverflowPolicy ??= new EffectOverflowPolicy();
    }

    private function cssPositioningResolver(): CssPositioningResolver
    {
        return $this->cssPositioningResolver ??= new CssPositioningResolver(
            $this->layoutIntentClassifier(),
            fn (float $value): string => $this->number($value),
        );
    }

    private function stickyLayoutCoordinator(): StickyLayoutCoordinator
    {
        return $this->stickyLayoutCoordinator ??= new StickyLayoutCoordinator(
            fn (array $node): array => $this->nodeList($node),
            fn (array $node): string => $this->textContent($node),
        );
    }

    private function layoutIntentClassifier(): LayoutIntentClassifier
    {
        return new LayoutIntentClassifier($this->assetsById);
    }

    /**
     * Resolved destination-node-id => page-path map used to turn NODE/prototype links into slug hrefs.
     *
     * @var array<string, string>
     */
    private array $linkTargetPaths = array();

    /**
     * Running link-coverage tallies populated while emitting nodes.
     *
     * @var array<string, mixed>
     */
    private array $linkCoverage = array();

    /**
     * Page-relative typographic hierarchy: rounded font-size key => heading tag
     * (h1-h6). Populated per emitted page so the largest/boldest text becomes the
     * top heading and smaller sizes descend.
     *
     * @var array<string, string>
     */
    private array $headingLevels = array();

    /**
     * Memoized list-item id sets keyed by container node id, so list-container
     * (<ul>) and list-item (<li>) decisions stay consistent within a page.
     *
     * @var array<string, array<int, string>>
     */
    private array $listItemIdCache = array();

    /**
     * Tree depth at which a frame can read as a top-level <section> for the page
     * being emitted. When the page is a single wrapping frame, its bands sit one
     * level down (depth 1); when bands are emitted as sibling root nodes, they
     * sit at the root (depth 0). Set per emitted page; everything deeper than
     * this is nested structure and stays a <div>.
     */
    private int $sectionDepth = 0;

    /**
     * Maps each per-node CSS class (the `figma-node-*` hook) to a human-readable
     * base name derived from the node's name/role. Used to mint shared,
     * authored-looking class names when several nodes share identical styles.
     *
     * @var array<string, string>
     */
    private array $nodeReadableNames = array();

    /**
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $options Transformation options.
     * @return array<string, mixed>
     */
    public function emit(array $scenegraph, array $options = array()): array
    {
        $this->renderTextGlyphPaths = true === ($options['render_text_glyph_paths'] ?? false);
        $this->usedAssetPaths = array();
        $this->generatedAssetFiles = array();
        $this->generatedVectorSvgPathsByHash = array();
        $this->nodeReadableNames = array();
        $this->stickyLayoutCoordinator()->reset();
        $this->linkTargetPaths = $this->normalizeLinkTargetPaths($options);
        $this->linkCoverage = $this->newLinkCoverage();
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $nodes = $this->nodeList($scenegraph);
        $this->stickyLayoutCoordinator()->detectStickyGhostCandidates($nodes);
        $this->listItemIdCache = array();
        $this->prepareHeadingRanking($nodes);
        $diagnostics = array();
        $nodeStyleDiagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);
        $this->cssPositioningResolver = null;

        $this->sectionDepth = $this->sectionDepthFor($nodes);

        $body = '';
        $cssRules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            '.figma-root{position:relative;width:100%}',
            'p,h1,h2,h3,h4,h5,h6{margin:0}',
            'ul,ol{margin:0;padding:0;list-style:none}',
            'img{display:block;max-width:100%;height:auto}',
            'a.figma-link{display:contents;color:inherit;text-decoration:inherit}',
            '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}',
        );
        if ( $this->renderTextGlyphPaths ) {
            $cssRules[] = '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}';
        }
        $operatorFontCss = $this->fontCss($options);
        $familyOverrides = $this->fontFamilyOverrides($options);

        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $body .= $this->emitNode($node, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
        }

        $assetFiles = array_merge($this->referencedAssetFiles($assetFiles), array_values($this->generatedAssetFiles));

        $shared   = $this->applySharedStyleClasses($cssRules);
        $cssRules = $shared['rules'];
        $body     = $this->applySharedClassMapToHtml($body, $shared['class_map']);

        $fontFamilies = $this->fontFamilies($nodeStyleDiagnostics);
        $fontUsage = $this->fontUsage($nodeStyleDiagnostics);
        $fontResolution = $this->fontResolver()->resolve($fontUsage, $operatorFontCss, $familyOverrides);
        $fontCss = (string) $fontResolution['css'];

        $designSystem = $this->designSystemExtractor()->extract($scenegraph);
        foreach ( $this->designSystemDiagnostics($designSystem) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }

        $css = ('' !== $fontCss ? $fontCss . "\n" : '')
            . ('' !== $designSystem['css'] ? $designSystem['css'] : '')
            . implode("\n", $cssRules) . "\n";
        $files = array(
            array(
                'path'      => 'index.html',
                'role'      => 'entrypoint',
                'mime_type' => 'text/html',
                'content'   => "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . $title . "</title>\n<link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n<main class=\"figma-root\" data-figma-root=\"true\">\n" . $body . "</main>\n</body>\n</html>\n",
            ),
            array(
                'path'      => 'style.css',
                'role'      => 'stylesheet',
                'mime_type' => 'text/css',
                'content'   => $css,
            ),
        );

        foreach ( $assetFiles as $assetFile ) {
            $files[] = $assetFile;
        }

        $files = (new InlineCssFileInjector())->inject($files, $css);

        $visualNodeMap = $this->visualNodeMap($nodes);
        $transformDiagnostics = $this->transformDiagnostics($nodes, $visualNodeMap, $assetFiles, $fontFamilies, $fontUsage, $fontResolution, $css, $diagnostics, $body);
        foreach ( $this->unresolvedSourceFontDiagnostics($fontResolution) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }

        return array(
            'status'        => 'success',
            'diagnostics'   => $diagnostics,
            'files'         => $files,
            'assets'        => $this->assetReport($assetFiles),
            'source_report' => array(
                'name'                         => $title,
                'node_count'                   => $this->countNodes($nodes),
                'schema'                       => $scenegraph['schema'] ?? null,
                'node_style_diagnostic_count'  => count($nodeStyleDiagnostics),
                'node_style_mismatch_count'    => $this->countNodeStyleMismatches($nodeStyleDiagnostics),
                'node_style_diagnostics'       => $nodeStyleDiagnostics,
                'visual_node_count'            => count($visualNodeMap),
                'visual_node_map'              => $visualNodeMap,
                'font_families'                => $fontFamilies,
                'font_usage'                   => $fontUsage,
                'font_css_supplied'            => (bool) $fontResolution['operator_supplied'],
                'render_text_glyph_paths'      => $this->renderTextGlyphPaths,
                'design_system'                => array(
                    'coverage'    => $designSystem['coverage'],
                    'frame_names' => $designSystem['frame_names'],
                ),
                'transform_diagnostics'        => $transformDiagnostics,
            ),
            'metrics'       => array(
                'node_count'  => $this->countNodes($nodes),
                'asset_count' => count($assetFiles),
            ),
        );
    }

    /**
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $pagePlan Planned pages with frame_id, name, path, and entrypoint.
     * @param array<string, mixed> $options Transformation options.
     * @return array<string, mixed>
     */
    public function emitSite(array $scenegraph, array $pagePlan, array $options = array()): array
    {
        $this->renderTextGlyphPaths = true === ($options['render_text_glyph_paths'] ?? false);
        $this->usedAssetPaths = array();
        $this->generatedAssetFiles = array();
        $this->generatedVectorSvgPathsByHash = array();
        $this->nodeReadableNames = array();
        $this->stickyLayoutCoordinator()->reset();
        $this->linkTargetPaths = $this->linkTargetPathsFromPagePlan($pagePlan, $options);
        $this->linkCoverage = $this->newLinkCoverage();
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $diagnostics = array();
        $nodeStyleDiagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);
        $nodeMap = $this->nodeMap($scenegraph);

        $cssRules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            '.figma-root{position:relative;width:100%}',
            'p,h1,h2,h3,h4,h5,h6{margin:0}',
            'ul,ol{margin:0;padding:0;list-style:none}',
            'img{display:block;max-width:100%;height:auto}',
            'a.figma-link{display:contents;color:inherit;text-decoration:inherit}',
            '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}',
        );
        if ( $this->renderTextGlyphPaths ) {
            $cssRules[] = '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}';
        }
        $operatorFontCss = $this->fontCss($options);
        $familyOverrides = $this->fontFamilyOverrides($options);
        $files = array();
        $pages = array();
        $renderedNodes = array();
        $seenPaths = array();
        $mediaBlocks = array();
        $plannedPages = $this->plannedPages($pagePlan);

        $stickyDetectionRoots = array();
        foreach ( $plannedPages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameIds = array();
            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            if ( '' !== $frameId ) {
                $frameIds[] = $frameId;
            }
            foreach ( is_array($page['variants'] ?? null) ? $page['variants'] : array() as $variant ) {
                if ( is_array($variant) && isset($variant['frame_id']) && is_scalar($variant['frame_id']) ) {
                    $frameIds[] = (string) $variant['frame_id'];
                }
            }

            foreach ( array_values(array_unique($frameIds)) as $candidateFrameId ) {
                if ( isset($nodeMap[$candidateFrameId]) && is_array($nodeMap[$candidateFrameId]) ) {
                    $stickyDetectionRoots[] = $nodeMap[$candidateFrameId];
                }
            }
        }
        $this->stickyLayoutCoordinator()->detectStickyGhostCandidates($stickyDetectionRoots);

        foreach ( $plannedPages as $index => $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = (string) ($page['frame_id'] ?? '');
            $frameNode = '' !== $frameId && isset($nodeMap[$frameId]) ? $nodeMap[$frameId] : null;
            if ( null === $frameNode ) {
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'planned_page_frame_missing',
                    'message'  => 'Planned page frame was not found in the scenegraph.',
                    'frame_id' => $frameId,
                );
                continue;
            }

            $pageName = (string) ($page['name'] ?? $frameNode['name'] ?? 'Page');
            $path = $this->pagePath($page, $pageName, $index);
            if ( isset($seenPaths[$path]) ) {
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'duplicate_page_path_omitted',
                    'message'  => 'Planned page path duplicates an earlier page and was omitted.',
                    'path'     => $path,
                    'frame_id' => $frameId,
                );
                continue;
            }
            $seenPaths[$path] = true;

            $this->listItemIdCache = array();
            $this->prepareHeadingRanking(array($frameNode));
            // A planned page is a single wrapping frame; its bands are its
            // direct children one level down.
            $this->sectionDepth = 1;
            $body = $this->emitNode($frameNode, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
            $files[] = array(
                'path'      => $path,
                'role'      => true === ($page['entrypoint'] ?? false) ? 'entrypoint' : 'document',
                'mime_type' => 'text/html',
                'content'   => $this->htmlDocument($this->sanitizeText($pageName), $this->stylesheetHref($path), $body),
            );
            $renderedNodes[] = $frameNode;

            foreach ( $this->breakpointMediaBlocks($page, $frameNode, $nodeMap) as $mediaBlock ) {
                $mediaBlocks[] = $mediaBlock;
            }

            $pages[] = array(
                'frame_id'   => $frameId,
                'name'       => $pageName,
                'path'       => $path,
                'entrypoint' => true === ($page['entrypoint'] ?? false),
                'node_count' => $this->countNodes(array($frameNode)),
            );
        }

        if ( empty($files) ) {
            $this->sectionDepth = $this->sectionDepthFor($this->nodeList($scenegraph));
            foreach ( $this->nodeList($scenegraph) as $node ) {
                if ( ! is_array($node) ) {
                    continue;
                }
                $body = $this->emitNode($node, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
                $files[] = array(
                    'path'      => 'index.html',
                    'role'      => 'entrypoint',
                    'mime_type' => 'text/html',
                    'content'   => $this->htmlDocument($title, 'style.css', $body),
                );
                $renderedNodes[] = $node;
            }
        }

        $assetFiles = array_merge($this->referencedAssetFiles($assetFiles), array_values($this->generatedAssetFiles));

        $shared   = $this->applySharedStyleClasses($cssRules, true);
        $cssRules = $shared['rules'];
        if ( ! empty($shared['class_map']) ) {
            foreach ( $files as $fileIndex => $file ) {
                if ( 'text/html' === ($file['mime_type'] ?? '') && isset($file['content']) ) {
                    $files[$fileIndex]['content'] = $this->applySharedClassMapToHtml((string) $file['content'], $shared['class_map']);
                }
            }
        }

        $fontFamilies = $this->fontFamilies($nodeStyleDiagnostics);
        $fontUsage = $this->fontUsage($nodeStyleDiagnostics);
        $fontResolution = $this->fontResolver()->resolve($fontUsage, $operatorFontCss, $familyOverrides);
        $fontCss = (string) $fontResolution['css'];
        $designSystem = $this->designSystemExtractor()->extract($scenegraph);
        foreach ( $this->designSystemDiagnostics($designSystem) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }
        $css = ('' !== $fontCss ? $fontCss . "\n" : '')
            . ('' !== $designSystem['css'] ? $designSystem['css'] : '')
            . implode("\n", array_values(array_unique($cssRules))) . "\n";
        if ( ! empty($mediaBlocks) ) {
            // Responsive overrides cascade AFTER the widest-first base rules so
            // narrower breakpoints win at their own viewport width.
            $css .= implode("\n", $mediaBlocks) . "\n";
        }
        $files[] = array(
            'path'      => 'style.css',
            'role'      => 'stylesheet',
            'mime_type' => 'text/css',
            'content'   => $css,
        );

        foreach ( $assetFiles as $assetFile ) {
            $files[] = $assetFile;
        }

        $files = (new InlineCssFileInjector())->inject($files, $css);

        $visualNodeMap = $this->visualNodeMap($renderedNodes);
        $transformDiagnostics = $this->transformDiagnostics($renderedNodes, $visualNodeMap, $assetFiles, $fontFamilies, $fontUsage, $fontResolution, $css, $diagnostics, $this->htmlFilesContent($files));
        foreach ( $this->unresolvedSourceFontDiagnostics($fontResolution) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }

        return array(
            'status'        => 'success',
            'diagnostics'   => $diagnostics,
            'files'         => $files,
            'assets'        => $this->assetReport($assetFiles),
            'source_report' => array(
                'name'                         => $title,
                'node_count'                   => $this->countNodes($renderedNodes),
                'schema'                       => $scenegraph['schema'] ?? null,
                'pages'                        => $pages,
                'node_style_diagnostic_count'  => count($nodeStyleDiagnostics),
                'node_style_mismatch_count'    => $this->countNodeStyleMismatches($nodeStyleDiagnostics),
                'node_style_diagnostics'       => $nodeStyleDiagnostics,
                'visual_node_count'            => count($visualNodeMap),
                'visual_node_map'              => $visualNodeMap,
                'font_families'                => $fontFamilies,
                'font_usage'                   => $fontUsage,
                'font_css_supplied'            => (bool) $fontResolution['operator_supplied'],
                'render_text_glyph_paths'      => $this->renderTextGlyphPaths,
                'design_system'                => array(
                    'coverage'    => $designSystem['coverage'],
                    'frame_names' => $designSystem['frame_names'],
                ),
                'transform_diagnostics'        => $transformDiagnostics,
            ),
            'metrics'       => array(
                'node_count'  => $this->countNodes($renderedNodes),
                'asset_count' => count($assetFiles),
                'page_count'  => count($pages),
            ),
        );
    }

    /**
     * Build the `@media (max-width: …)` CSS blocks for one responsive page.
     *
     * The primary (widest) variant frame is already rendered as the base layout
     * by {@see emitSite}. For every narrower breakpoint variant this walks the
     * variant frame, computes per-node style declarations with the same
     * machinery the base used, maps each variant node onto its base counterpart
     * by structural position, and emits only the declarations that DIFFER from
     * the base inside a `max-width` media block keyed on the variant viewport.
     *
     * Single-variant pages return an empty list, so they render exactly as
     * before with no `@media` output.
     *
     * @param array<string, mixed>                $page     Planned page (carries `variants`).
     * @param array<string, mixed>                $baseNode Primary variant frame node.
     * @param array<string, array<string, mixed>> $nodeMap  Id => node lookup.
     * @return array<int, string>
     */
    private function breakpointMediaBlocks(array $page, array $baseNode, array $nodeMap): array
    {
        $variants = is_array($page['variants'] ?? null) ? array_values($page['variants']) : array();
        if ( count($variants) < 2 ) {
            return array();
        }

        $baseStyles = array();
        $this->collectVariantNodeStyles($baseNode, 0, null, 'r', $baseStyles);

        // Derive the primary (base) viewport width from the variants list so we
        // can compute midpoint breakpoints between adjacent variant widths.
        $primaryViewportWidth = null;
        foreach ( $variants as $variant ) {
            if ( is_array($variant) && true === ($variant['primary'] ?? false) && is_numeric($variant['viewport_width'] ?? null) ) {
                $primaryViewportWidth = (float) $variant['viewport_width'];
                break;
            }
        }

        $blocks = array();
        // Variants are ordered widest-first, so iterating in order emits the
        // narrower breakpoints later in the cascade — exactly the precedence
        // overlapping `max-width` queries need.
        // Track the previously seen (wider) viewport width so each breakpoint
        // keys at the midpoint between adjacent variant widths rather than at
        // the narrow variant's own width, which would be too narrow for most
        // browsers and phones.
        $prevViewportWidth = $primaryViewportWidth;
        foreach ( $variants as $variant ) {
            if ( ! is_array($variant) || true === ($variant['primary'] ?? false) ) {
                continue;
            }

            $variantId = isset($variant['frame_id']) && is_scalar($variant['frame_id']) ? (string) $variant['frame_id'] : '';
            $viewportWidth = $variant['viewport_width'] ?? null;
            if ( '' === $variantId || ! isset($nodeMap[$variantId]) || ! is_numeric($viewportWidth) ) {
                continue;
            }

            $variantStyles = array();
            $this->collectVariantNodeStyles($nodeMap[$variantId], 0, null, 'r', $variantStyles);

            $rules = $this->breakpointDiffRules($baseStyles, $variantStyles);
            if ( empty($rules) ) {
                $prevViewportWidth = (float) $viewportWidth;
                continue;
            }

            // Key the breakpoint at the midpoint between this variant and its
            // next-wider neighbour (the previous iteration, or the primary).
            // Midpoint avoids keying at the narrow variant's own width (e.g.
            // 390px) which would leave most desktop-resized browsers outside
            // the mobile styles. Falls back to the variant's own width when no
            // wider neighbour width is known.
            if ( null !== $prevViewportWidth && $prevViewportWidth > (float) $viewportWidth ) {
                $breakpointPx = (int) round(($prevViewportWidth + (float) $viewportWidth) / 2);
            } else {
                $breakpointPx = (int) round((float) $viewportWidth);
            }

            $blocks[] = '@media (max-width:' . $this->number((float) $breakpointPx) . 'px){'
                . "\n" . implode("\n", $rules) . "\n}";

            $prevViewportWidth = (float) $viewportWidth;
        }

        return $blocks;
    }

    /**
     * Walk a variant frame and record each node's emitted class name and ordered
     * style declarations, keyed by a deterministic structural path. The
     * traversal mirrors {@see emitNode} (same child skipping and the same
     * BOOLEAN_OPERATION vector short-circuit) so a node at a given position
     * always resolves to the same key across breakpoint variants — that key is
     * what lets base and narrower styles be diffed without re-deriving layout.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $map  pathKey => array{class: string, styles: array<int, string>}
     */
    private function collectVariantNodeStyles(array $node, int $depth, ?array $parentNode, string $pathKey, array &$map): void
    {
        if ( $this->stickyLayoutCoordinator()->isSuppressedStickyGhost($node) ) {
            return;
        }

        $id = $this->sanitizeAttribute((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $className = 'figma-node-' . $this->slug($id . '-' . $name);
        $styles = $this->stickyLayoutCoordinator()->stickyAwareStyleDeclarations($node, $this->styleDeclarations($node, $type, $parentNode));

        $map[$pathKey] = array(
            'class'           => $className,
            'styles'          => $styles,
            'contains_sticky' => $this->stickyLayoutCoordinator()->containsStickyPrimary($node),
        );

        $vectorSvg = $this->supportedVectorSvg($node, $type, $parentNode);
        if ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) {
            return;
        }

        $childOrdinal = 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) || $this->stickyLayoutCoordinator()->isSuppressedStickyGhost($child) || $this->isFullyClippedDecorativeChild($child, $node) ) {
                continue;
            }

            $childType = strtoupper((string) ($child['type'] ?? 'FRAME'));
            $childKey = $pathKey . '/' . $childOrdinal . ':' . $childType;
            $this->collectVariantNodeStyles($child, $depth + 1, $node, $childKey, $map);
            ++$childOrdinal;
        }
    }

    /**
     * Diff a narrower variant's per-node styles against the base styles, keeping
     * only declarations whose value changed (or that the base lacked). Rules are
     * keyed on the BASE class name so the overrides land on the already-rendered
     * elements, and are emitted in base-traversal order for deterministic output.
     *
     * @param array<string, array<string, mixed>> $baseStyles
     * @param array<string, array<string, mixed>> $variantStyles
     * @return array<int, string>
     */
    private function breakpointDiffRules(array $baseStyles, array $variantStyles): array
    {
        $rules = array();
        foreach ( $baseStyles as $pathKey => $base ) {
            if ( ! isset($variantStyles[$pathKey]) ) {
                continue;
            }

            $baseMap = $this->styleDeclarationMap(is_array($base['styles'] ?? null) ? $base['styles'] : array());
            $variantDeclarations = is_array($variantStyles[$pathKey]['styles'] ?? null) ? $variantStyles[$pathKey]['styles'] : array();

            $changed = array();
            $baseContainsSticky = true === ($base['contains_sticky'] ?? false);
            foreach ( $variantDeclarations as $declaration ) {
                $parts = explode(':', (string) $declaration, 2);
                if ( 2 !== count($parts) ) {
                    continue;
                }

                $property = trim($parts[0]);
                $value = trim($parts[1]);
                if ( $baseContainsSticky && 'overflow' === $property ) {
                    continue;
                }
                if ( ! array_key_exists($property, $baseMap) || $baseMap[$property] !== $value ) {
                    $changed[] = $property . ':' . $value;
                }
            }

            if ( empty($changed) ) {
                continue;
            }

            $rules[] = '.' . (string) $base['class'] . '{' . implode(';', $changed) . '}';
        }

        return $rules;
    }

    private function htmlDocument(string $title, string $stylesheetHref, string $body): string
    {
        return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . $title . "</title>\n<link rel=\"stylesheet\" href=\"" . $this->sanitizeAttribute($stylesheetHref) . "\">\n</head>\n<body>\n<main class=\"figma-root\" data-figma-root=\"true\">\n" . $body . "</main>\n</body>\n</html>\n";
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function htmlFilesContent(array $files): string
    {
        $html = '';
        foreach ( $files as $file ) {
            if ( is_array($file) && 'text/html' === ($file['mime_type'] ?? null) && isset($file['content']) && is_scalar($file['content']) ) {
                $html .= "\n" . (string) $file['content'];
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>                 $cssRules
     * @param array<int, array<string, mixed>>   $diagnostics
     */
    private function emitNode(array $node, array &$cssRules, array &$diagnostics, array &$nodeStyleDiagnostics, int $depth, ?array $parentNode): string
    {
        if ( $this->stickyLayoutCoordinator()->isSuppressedStickyGhost($node) ) {
            return '';
        }

        // Designer-hidden layers carry an explicit `visible: false` from Figma.
        // Skip emitting them and their entire subtree. Absent/null `visible`
        // means visible, so only an explicit false is honored. A hidden node
        // emitted as a top-level render root (depth 0, e.g. an explicitly
        // selected frame) still renders; hidden descendants never do.
        if ( $depth > 0 && false === ($node['visible'] ?? null) ) {
            return '';
        }

        $id = $this->sanitizeAttribute((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $attributeName = $this->sanitizeAttribute($name);
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        if ( 'TEXT' === $type ) {
            $text = $this->textGlyphSvg($node);
            if ( null === $text ) {
                // Multi-paragraph text splits into per-paragraph boxes so
                // `paragraphSpacing` lands as a margin; otherwise render the node
                // as a single element.
                $text = $this->multiParagraphTextContent($node) ?? $this->textContent($node);
            }
        } else {
            $text = $this->textContent($node);
        }
        $tag = $this->semanticTag($node, $type, $name, $depth, $parentNode);
        $className = 'figma-node-' . $this->slug($id . '-' . $name);
        $children = $this->nodeList($node);
        $content = $text;
        $vectorSvg = $this->supportedVectorSvg($node, $type, $parentNode);
        $assetPath = $this->nodeAssetPath($node);
        $hasVectorAssetFallback = $this->isUnsupportedVectorType($type) && null !== $assetPath;

        if ( 'input' !== $tag && ! ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) ) {
            foreach ( $children as $child ) {
                if ( is_array($child) ) {
                    if ( $this->isFullyClippedDecorativeChild($child, $node) ) {
                        continue;
                    }
                    $content .= $this->emitNode($child, $cssRules, $diagnostics, $nodeStyleDiagnostics, $depth + 1, $node);
                }
            }
        }

        if ( null !== $vectorSvg ) {
            $content = $this->vectorSvgMarkup($vectorSvg, $node, $type) . $content;
        }

        $hasRenderableVectorFallback = '' !== trim($content);
        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg && ! $hasVectorAssetFallback && ! $hasRenderableVectorFallback ) {
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'unsupported_vector_node_placeholder',
                'message'  => 'Unsupported vector-like Figma node emitted as a static placeholder.',
            ) + $this->vectorPlaceholderDiagnostic($node, $type, $parentNode);

            $content = '';
        }

        $styles = $this->styleDeclarations($node, $type, $parentNode);
        $styles = $this->stickyLayoutCoordinator()->stickyAwareStyleDeclarations($node, $styles);
        if ( ! empty($styles) ) {
            $cssRules[] = '.' . $className . '{' . implode(';', $styles) . '}';
            $this->nodeReadableNames[$className] = $this->sharedClassBaseName($name, $type);
        }
        $nodeStyleDiagnostics[] = $this->nodeStyleDiagnostic($node, $type, $className, $tag, $styles, $parentNode);

        if ( 'TEXT' === $type ) {
            $paragraphSpacingDiagnostic = $this->paragraphSpacingDiagnostic($node);
            if ( null !== $paragraphSpacingDiagnostic ) {
                $diagnostics[] = $paragraphSpacingDiagnostic;
            }
        }

        $attributes = sprintf(' class="%1$s" data-figma-node-id="%2$s" data-figma-node-name="%3$s"', $className, $id, $attributeName);
        if ( 'input' === $tag ) {
            $attributes .= $this->inputControlAttributes($node);
        }
        if ( 'RECTANGLE' === $type && '' === $content ) {
            $attributes .= ' aria-hidden="true"';
        }
        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg && ! $hasVectorAssetFallback && ! $hasRenderableVectorFallback ) {
            $attributes .= ' data-figma-unsupported-vector="true" aria-hidden="true"';
        } elseif ( $hasVectorAssetFallback ) {
            $attributes .= ' role="img" aria-label="' . $this->sanitizeAttribute('' !== $name ? $name : $type) . '"';
        }

        if ( 'input' === $tag ) {
            $element = sprintf("<input%1\$s>\n", $attributes);
        } else {
            $element = sprintf("<%1\$s%2\$s>%3\$s</%1\$s>\n", $tag, $attributes, $content);
        }

        return $this->wrapWithLink($node, $element, $diagnostics, $this->isButtonLike($node));
    }

    /**
     * Selects a semantic HTML element for a node from its type, name, position,
     * and content. Landmarks (header/nav/section/footer/article) come from
     * structure and position; content tags (h1-h6/p/ul/li/button/span) come from
     * the page-relative typographic hierarchy and node shape. Falls back to the
     * historical name-based mapping and a generic section/div when no stronger
     * signal exists.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function semanticTag(array $node, string $type, string $name, int $depth, ?array $parentNode): string
    {
        $lowerName = strtolower($name);

        if ( 'TEXT' === $type ) {
            // A label inside a button-like control is inline phrasing content.
            if ( null !== $parentNode && $this->isButtonLike($parentNode) ) {
                return 'span';
            }

            $heading = $this->headingLevel($node, $lowerName, $depth);
            if ( null !== $heading ) {
                return $heading;
            }

            return 'p';
        }

        $children = array_values(array_filter($this->nodeList($node), 'is_array'));

        // List items: a repeated, structurally-similar child of a list container.
        if ( null !== $parentNode && $this->isListItemOf($node, $parentNode) ) {
            return 'li';
        }

        if ( $this->isInputLike($node) ) {
            return 'input';
        }

        // A standalone button-like control (no link) becomes a real <button>.
        // Linked controls stay structural and gain a button class on their anchor.
        if ( empty($node['figma_link']) && $this->isButtonLike($node) ) {
            return 'button';
        }

        $landmark = $this->landmarkTag($node, $lowerName, $children, $depth, $parentNode);
        if ( null !== $landmark ) {
            return $landmark;
        }

        // A container of repeated sibling items reads as an unordered list.
        if ( ! empty($this->listItemIds($node)) ) {
            return 'ul';
        }

        // A <section> is reserved for genuine top-level content regions — the
        // page bands a hand-author would wrap in <section>. Every other frame
        // (nested wrappers, rows, columns, cards, decorative groups) stays a
        // <div>, so a typical page emits a handful of sections, not hundreds.
        if ( 'FRAME' === $type && $this->isTopLevelSection($node, $depth, $parentNode, $children) ) {
            return 'section';
        }

        return 'div';
    }

    /**
     * Decides whether a frame is a genuine top-level content region worthy of a
     * <section>, rather than a nested structural container. The signals are
     * generic and position/size/content based — no file-specific names:
     *
     *  - Position: a top-level page band — exactly one level below the page
     *    root ({@see $sectionDepth}), so only the bands a hand-author wraps in
     *    <section> qualify, never the structure nested inside them.
     *  - Size: spans most of the page width, like a full-width content band.
     *  - Significance: holds meaningful mixed content (multiple text runs, or
     *    sub-regions), not a thin wrapper around a single element.
     *
     * Deeper frames — rows, columns, cards, wrappers, and decorative groups —
     * are never sections; they stay <div>. That is what keeps a page to a
     * handful of sections instead of hundreds.
     *
     * @param array<string, mixed>             $node
     * @param array<string, mixed>|null        $parentNode
     * @param array<int, array<string, mixed>> $children
     */
    private function isTopLevelSection(array $node, int $depth, ?array $parentNode, array $children): bool
    {
        // Only the page's top-level bands qualify: the single level directly
        // below the page root. Anything deeper is nested structure (a <div>).
        if ( $depth !== $this->sectionDepth ) {
            return false;
        }

        // A band needs real content, not an empty or single-element wrapper:
        // either several text runs, or more than one structural sub-region.
        $textRuns = $this->textDescendantCount($node);
        if ( $textRuns < 2 && count($children) < 2 ) {
            return false;
        }

        // A band spans most of the page: its width is a large fraction of the
        // wrapping page frame's width. Narrow columns and side rails stay <div>.
        // Root-level bands (depth 0) have no parent frame to measure against;
        // their content significance alone settles it.
        if ( null !== $parentNode ) {
            $width = $this->boxValue($node, 'width');
            $parentWidth = $this->boxValue($parentNode, 'width');
            if ( null !== $width && null !== $parentWidth && $parentWidth > 0.0 ) {
                return ( $width / $parentWidth ) >= 0.6;
            }
        }

        // Without reliable geometry, fall back to the content signal alone: a
        // top-level band carrying meaningful mixed content reads as a section.
        return true;
    }

    /**
     * Determines the tree depth at which top-level page bands live for a set of
     * root nodes. When the page is a single frame that wraps several band
     * frames, the bands are its direct children (depth 1). When the bands are
     * emitted as sibling root nodes — or when the single root frame is itself a
     * content region rather than a wrapper — the bands sit at the root (depth 0).
     *
     * @param array<int, mixed> $rootNodes
     */
    private function sectionDepthFor(array $rootNodes): int
    {
        $frames = array_values(array_filter(
            $rootNodes,
            static fn ($node): bool => is_array($node) && 'FRAME' === strtoupper((string) ($node['type'] ?? ''))
        ));

        // A single root frame is a page wrapper only when it groups several band
        // frames of its own. Otherwise it is itself a content region.
        if ( 1 === count($frames) ) {
            $childFrames = array_filter(
                $this->nodeList($frames[0]),
                static fn ($child): bool => is_array($child) && 'FRAME' === strtoupper((string) ($child['type'] ?? ''))
            );

            return count($childFrames) >= 2 ? 1 : 0;
        }

        return 0;
    }

    /**
     * Maps a container to a landmark element from explicit name signals first,
     * then position + content heuristics for generically-named regions.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $children
     * @param array<string, mixed>|null $parentNode
     */
    private function landmarkTag(array $node, string $lowerName, array $children, int $depth, ?array $parentNode): ?string
    {
        if ( str_contains($lowerName, 'header') ) {
            return 'header';
        }

        if ( str_contains($lowerName, 'footer') ) {
            return 'footer';
        }

        if ( str_contains($lowerName, 'nav') || str_contains($lowerName, 'menu') ) {
            return 'nav';
        }

        if ( str_contains($lowerName, 'article') ) {
            return 'article';
        }

        if ( empty($children) ) {
            return null;
        }

        $linkCount = $this->linkChildCount($children);

        // Top region with a logo and a cluster of links reads as a site header;
        // a bottom region with links or legal text reads as a footer.
        if ( $depth <= 1 && null !== $parentNode ) {
            $region = $this->verticalRegion($node, $parentNode);
            if ( 'top' === $region && $this->hasLogoChild($children) && ( $linkCount >= 1 || count($children) >= 2 ) ) {
                return 'header';
            }
            if ( 'bottom' === $region && ( $linkCount >= 1 || $this->hasLegalText($node) ) ) {
                return 'footer';
            }
        }

        // A tight cluster whose children are all links reads as navigation; a
        // region that merely contains a couple of link-bearing sub-areas does not.
        if ( $linkCount >= 2 && $linkCount === count($children) ) {
            return 'nav';
        }

        return null;
    }

    /**
     * Builds the page-relative heading ranking. The most common text size is
     * treated as body copy; distinct larger sizes are ranked descending into
     * h1..h6 (largest/boldest first).
     *
     * @param array<int, mixed> $nodes
     */
    private function prepareHeadingRanking(array $nodes): void
    {
        $this->headingLevels = array();
        $sizes = array();
        $this->collectTextSizes($nodes, $sizes);
        if ( empty($sizes) ) {
            return;
        }

        $bodySize = $this->modeFontSize($sizes);
        $headingSizes = array();
        foreach ( $sizes as $size ) {
            if ( $size > $bodySize ) {
                $headingSizes[$this->sizeKey($size)] = $size;
            }
        }
        if ( empty($headingSizes) ) {
            return;
        }

        $values = array_values($headingSizes);
        rsort($values);
        $level = 1;
        foreach ( $values as $size ) {
            $this->headingLevels[$this->sizeKey($size)] = 'h' . min($level, 6);
            $level++;
        }
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, float> $sizes
     */
    private function collectTextSizes(array $nodes, array &$sizes): void
    {
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
                $size = $this->textFontSize($node);
                if ( null !== $size ) {
                    $sizes[] = $size;
                }
            }
            $this->collectTextSizes($this->nodeList($node), $sizes);
        }
    }

    /**
     * @param array<int, float> $sizes
     */
    private function modeFontSize(array $sizes): float
    {
        $counts = array();
        foreach ( $sizes as $size ) {
            $key = $this->sizeKey($size);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $bestCount = -1;
        $bestSize = 0.0;
        foreach ( $sizes as $size ) {
            $count = $counts[$this->sizeKey($size)];
            if ( $count > $bestCount || ( $count === $bestCount && $size < $bestSize ) ) {
                $bestCount = $count;
                $bestSize = $size;
            }
        }

        return $bestSize;
    }

    private function sizeKey(float $size): string
    {
        return number_format($size, 1, '.', '');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function headingLevel(array $node, string $lowerName, int $depth): ?string
    {
        $size = $this->textFontSize($node);
        if ( null !== $size ) {
            $key = $this->sizeKey($size);
            // Long running text at a heading size still reads as a paragraph.
            if ( isset($this->headingLevels[$key]) && $this->textWordCount($node) <= 24 ) {
                return $this->headingLevels[$key];
            }
        }

        // Name-based fallback preserves explicit title/heading/headline intent.
        if ( str_contains($lowerName, 'title') || str_contains($lowerName, 'heading') || str_contains($lowerName, 'headline') ) {
            return 0 === $depth ? 'h1' : 'h2';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textFontSize(array $node): ?float
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( isset($style['font_size']) && is_numeric($style['font_size']) ) {
            return (float) $style['font_size'];
        }
        if ( isset($node['fontSize']) && is_numeric($node['fontSize']) ) {
            return (float) $node['fontSize'];
        }

        return null;
    }

    /**
     * Identifies a button-like control: a small container with a single text
     * label that is filled, rounded, or named like a button.
     *
     * @param array<string, mixed> $node
     */
    private function isButtonLike(array $node): bool
    {
        if ( $this->isInputLike($node) ) {
            return false;
        }
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }
        if ( 1 !== $this->textDescendantCount($node) ) {
            return false;
        }

        $width = $this->boxValue($node, 'width');
        if ( null !== $width && $width > 480.0 ) {
            return false;
        }
        $height = $this->boxValue($node, 'height');
        if ( null !== $height && $height > 160.0 ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $nameHint = str_contains($name, 'button') || str_contains($name, 'btn') || str_contains($name, 'cta');

        return $nameHint || null !== $this->backgroundColor($node) || $this->cornerRadius($node) > 0.0;
    }

    /**
     * Identifies input-like control chrome before generic button heuristics see a
     * rounded, filled single-text frame and emit it as a button.
     *
     * @param array<string, mixed> $node
     */
    private function isInputLike(array $node): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        if ( str_contains($name, 'button') || str_contains($name, 'btn') || str_contains($name, 'cta') ) {
            return false;
        }

        $hasInputName = str_contains($name, 'input')
            || str_contains($name, 'text field')
            || str_contains($name, 'textfield')
            || str_contains($name, 'form field')
            || preg_match('/(^|[^a-z])field([^a-z]|$)/', $name);
        if ( ! $hasInputName ) {
            return false;
        }

        $textCount = $this->textDescendantCount($node);
        if ( $textCount < 1 || $textCount > 2 ) {
            return false;
        }

        $width = $this->boxValue($node, 'width');
        $height = $this->boxValue($node, 'height');
        if ( (null !== $width && $width > 640.0) || (null !== $height && $height > 120.0) ) {
            return false;
        }

        return null !== $this->backgroundColor($node) || $this->cornerRadius($node) > 0.0 || $this->hasStrokePaint($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasStrokePaint(array $node): bool
    {
        foreach ( array('figma_paints', 'strokes', 'strokePaints') as $key ) {
            $paints = $node[$key] ?? null;
            if ( ! is_array($paints) ) {
                continue;
            }

            if ( 'figma_paints' === $key ) {
                $paints = $paints['strokes'] ?? array();
            }

            if ( ! empty($paints) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function inputControlAttributes(array $node): string
    {
        $placeholder = trim($this->subtreePlainText($node));
        $name = (string) ($node['name'] ?? '');
        $haystack = strtolower($name . ' ' . $placeholder);
        $type = str_contains($haystack, 'email') || str_contains($haystack, 'e-mail') ? 'email' : 'text';

        $attributes = ' type="' . $type . '"';
        if ( '' !== $placeholder ) {
            $attributes .= ' placeholder="' . $this->sanitizeAttribute($placeholder) . '"';
            $attributes .= ' aria-label="' . $this->sanitizeAttribute($placeholder) . '"';
        } elseif ( '' !== $name ) {
            $attributes .= ' aria-label="' . $this->sanitizeAttribute($name) . '"';
        }

        return $attributes;
    }

    /**
     * Returns the ids of a container's children when they form a list: at least
     * three structurally-similar, text-bearing siblings of one type that are not
     * a navigation/landmark cluster. Empty otherwise.
     *
     * @param array<string, mixed> $container
     * @return array<int, string>
     */
    private function listItemIds(array $container): array
    {
        $id = (string) ($container['id'] ?? '');
        if ( '' !== $id && array_key_exists($id, $this->listItemIdCache) ) {
            return $this->listItemIdCache[$id];
        }

        $result = $this->computeListItemIds($container);
        if ( '' !== $id ) {
            $this->listItemIdCache[$id] = $result;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, string>
     */
    private function computeListItemIds(array $container): array
    {
        $name = strtolower((string) ($container['name'] ?? ''));
        foreach ( array('header', 'footer', 'nav', 'menu', 'article') as $hint ) {
            if ( str_contains($name, $hint) ) {
                return array();
            }
        }

        $children = array_values(array_filter($this->nodeList($container), 'is_array'));
        if ( 3 > count($children) ) {
            return array();
        }

        // Link-saturated clusters read as navigation, not generic content lists.
        if ( $this->linkChildCount($children) >= count($children) ) {
            return array();
        }

        $type = strtoupper((string) ($children[0]['type'] ?? ''));
        $heights = array();
        foreach ( $children as $child ) {
            if ( strtoupper((string) ($child['type'] ?? '')) !== $type ) {
                return array();
            }
            if ( ! $this->subtreeHasText($child) ) {
                return array();
            }
            $height = $this->boxValue($child, 'height');
            if ( null !== $height ) {
                $heights[] = $height;
            }
        }

        if ( count($heights) >= 2 ) {
            $min = min($heights);
            $max = max($heights);
            if ( $min > 0.0 && ( $max / $min ) > 1.5 ) {
                return array();
            }
        }

        $ids = array();
        foreach ( $children as $child ) {
            $ids[] = (string) ($child['id'] ?? '');
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isListItemOf(array $node, array $parentNode): bool
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' === $id ) {
            return false;
        }

        return in_array($id, $this->listItemIds($parentNode), true);
    }

    /**
     * Classifies a node's vertical position among its siblings as top, bottom,
     * or middle, using box coordinates and falling back to source order.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function verticalRegion(array $node, array $parentNode): ?string
    {
        $siblings = array_values(array_filter($this->nodeList($parentNode), 'is_array'));
        if ( 2 > count($siblings) ) {
            return 'middle';
        }

        $thisId = (string) ($node['id'] ?? '');
        $positions = array();
        $haveAll = true;
        foreach ( $siblings as $sibling ) {
            $y = $this->boxValue($sibling, 'y');
            if ( null === $y ) {
                $haveAll = false;
                break;
            }
            $positions[(string) ($sibling['id'] ?? '')] = $y;
        }

        if ( $haveAll && isset($positions[$thisId]) ) {
            $y = $positions[$thisId];
            if ( $y <= min($positions) ) {
                return 'top';
            }
            if ( $y >= max($positions) ) {
                return 'bottom';
            }

            return 'middle';
        }

        $firstId = (string) ($siblings[0]['id'] ?? '');
        $lastId = (string) ($siblings[count($siblings) - 1]['id'] ?? '');
        if ( $thisId === $firstId ) {
            return 'top';
        }
        if ( $thisId === $lastId ) {
            return 'bottom';
        }

        return 'middle';
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function linkChildCount(array $children): int
    {
        $count = 0;
        foreach ( $children as $child ) {
            if ( is_array($child) && $this->subtreeHasLink($child) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasLink(array $node): bool
    {
        if ( ! empty($node['figma_link']) ) {
            return true;
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasLink($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function hasLogoChild(array $children): bool
    {
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $name = strtolower((string) ($child['name'] ?? ''));
            if ( str_contains($name, 'logo') || str_contains($name, 'brand') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasLegalText(array $node): bool
    {
        $text = strtolower($this->subtreePlainText($node));

        return str_contains($text, '©') || str_contains($text, 'copyright') || str_contains($text, 'rights reserved');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textDescendantCount(array $node): int
    {
        $count = 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) ) {
                $count++;
            }
            $count += $this->textDescendantCount($child);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasText(array $node): bool
    {
        return '' !== trim($this->subtreePlainText($node));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreePlainText(array $node): string
    {
        $parts = array();
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $own = $this->nodePlainText($node);
            if ( '' !== $own ) {
                $parts[] = $own;
            }
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childText = $this->subtreePlainText($child);
            if ( '' !== $childText ) {
                $parts[] = $childText;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodePlainText(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }

        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $out = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $out .= (string) $segment['characters'];
                }
            }
            if ( '' !== $out ) {
                return $out;
            }
        }

        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                return (string) $node[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textWordCount(array $node): int
    {
        $words = preg_split('/\s+/', trim($this->nodePlainText($node)));
        if ( ! is_array($words) ) {
            return 0;
        }

        return count(array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function cornerRadius(array $node): float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box['corner_radius']) && is_numeric($box['corner_radius']) ) {
            return (float) $box['corner_radius'];
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function boxValue(array $node, string $key): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box[$key]) && is_numeric($box[$key]) ) {
            return (float) $box[$key];
        }
        if ( isset($node[$key]) && is_numeric($node[$key]) ) {
            return (float) $node[$key];
        }

        return null;
    }

    /**
     * Build the design-system coverage diagnostic: an informational record of
     * how many color/type/spacing tokens were extracted from how many detected
     * style-guide frames. Returns an empty list when no design-system frame was
     * detected, so files without one stay silent.
     *
     * @param array{css: string, coverage: array<string, int>, frame_names: array<int, string>} $designSystem
     * @return array<int, array<string, mixed>>
     */
    private function designSystemDiagnostics(array $designSystem): array
    {
        $coverage = is_array($designSystem['coverage'] ?? null) ? $designSystem['coverage'] : array();
        if ( (int) ($coverage['frame_count'] ?? 0) < 1 ) {
            return array();
        }

        return array(
            array(
                'severity'       => 'info',
                'code'           => 'design_system_extracted',
                'message'        => 'Extracted a global design system from detected style-guide frames.',
                'frame_count'    => (int) ($coverage['frame_count'] ?? 0),
                'color_tokens'   => (int) ($coverage['color_tokens'] ?? 0),
                'type_tokens'    => (int) ($coverage['type_tokens'] ?? 0),
                'spacing_tokens' => (int) ($coverage['spacing_tokens'] ?? 0),
                'frame_names'    => is_array($designSystem['frame_names'] ?? null) ? $designSystem['frame_names'] : array(),
            ),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function fontCss(array $options): string
    {
        if ( isset($options['font_css']) && is_scalar($options['font_css']) ) {
            return trim((string) $options['font_css']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function fontFamilyOverrides(array $options): array
    {
        $overrides = $options['font_family_overrides'] ?? array();
        if ( ! is_array($overrides) ) {
            return array();
        }
        $result = array();
        foreach ( $overrides as $family => $css ) {
            if ( is_string($family) && '' !== $family && is_string($css) ) {
                $result[strtolower($family)] = $css;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $styles
     * @return array<string, mixed>
     */
    private function nodeStyleDiagnostic(array $node, string $type, string $className, string $tag, array $styles, ?array $parentNode): array
    {
        $expected = $this->expectedNodeStyleData($node, $type, $parentNode);
        $emitted = $this->emittedNodeStyleData($styles);
        $matches = array();
        $mismatches = array();

        foreach ( array_keys($expected + $emitted) as $key ) {
            $left = $expected[$key] ?? null;
            $right = $emitted[$key] ?? null;
            $matches[$key] = $left === $right;
            if ( ! $matches[$key] ) {
                $mismatches[] = $key;
            }
        }

        return array(
            'node'       => array(
                'id'    => (string) ($node['id'] ?? ''),
                'name'  => (string) ($node['name'] ?? ''),
                'type'  => $type,
                'tag'   => $tag,
                'class' => $className,
            ),
            'expected'   => $expected,
            'emitted'    => $emitted,
            'matches'    => $matches,
            'mismatches' => $mismatches,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, string|null>
     */
    private function expectedNodeStyleData(array $node, string $type, ?array $parentNode): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $data = array(
            'background'  => 'TEXT' !== $type && ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE'), true) ? $this->backgroundColor($node) : null,
            'width'       => $this->expectedCssLength($box['width'] ?? null),
            'height'      => $this->expectedCssLength($box['height'] ?? null),
            'x'           => null,
            'y'           => null,
            'text_color'  => null,
            'font_family' => null,
            'font_size'   => null,
            'font_weight' => null,
            'line_height' => null,
        );

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( null !== $parentNode && $this->isFreeformContainer($parentNode) ) {
            $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
            $data['x'] = $this->expectedCssLength($this->positionOffset($box, $parentBox, 'x'));
            $data['y'] = $this->expectedCssLength($this->positionOffset($box, $parentBox, 'y'));
        } elseif ( null !== $parentNode && 'absolute' === ($layout['positioning'] ?? null) ) {
            $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
            $data['x'] = $this->expectedCssLength($this->relativeOffset($box, $parentBox, 'x'));
            $data['y'] = $this->expectedCssLength($this->relativeOffset($box, $parentBox, 'y'));
        }

        if ( 'TEXT' === $type ) {
            foreach ( $this->expectedTextStyleData($node) as $key => $value ) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, string|null>
     */
    private function expectedTextStyleData(array $node): array
    {
        $declarations = $this->styleDeclarationMap($this->textStyles($node));
        return array(
            'text_color'  => $declarations['color'] ?? null,
            'font_family' => $declarations['font-family'] ?? null,
            'font_size'   => $declarations['font-size'] ?? null,
            'font_weight' => $declarations['font-weight'] ?? null,
            'line_height' => $declarations['line-height'] ?? null,
        );
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, string|null>
     */
    private function emittedNodeStyleData(array $styles): array
    {
        $map = $this->styleDeclarationMap($styles);
        return array(
            'background'  => $map['background'] ?? null,
            'width'       => $map['width'] ?? null,
            'height'      => $map['height'] ?? null,
            'x'           => $map['left'] ?? null,
            'y'           => $map['top'] ?? null,
            'text_color'  => $map['color'] ?? null,
            'font_family' => $map['font-family'] ?? null,
            'font_size'   => $map['font-size'] ?? null,
            'font_weight' => $map['font-weight'] ?? null,
            'line_height' => $map['line-height'] ?? null,
        );
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, string>
     */
    private function styleDeclarationMap(array $styles): array
    {
        $map = array();
        foreach ( $styles as $style ) {
            $parts = explode(':', $style, 2);
            if ( 2 === count($parts) ) {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $map;
    }

    private function expectedCssLength(mixed $value): ?string
    {
        return is_numeric($value) ? $this->number((float) $value) . 'px' : null;
    }

    /**
     * @param array<int, array<string, mixed>> $nodeStyleDiagnostics
     */
    private function countNodeStyleMismatches(array $nodeStyleDiagnostics): int
    {
        $count = 0;
        foreach ( $nodeStyleDiagnostics as $diagnostic ) {
            $count += count(is_array($diagnostic['mismatches'] ?? null) ? $diagnostic['mismatches'] : array());
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $nodeStyleDiagnostics
     * @return array<int, string>
     */
    private function fontFamilies(array $nodeStyleDiagnostics): array
    {
        $families = array();
        foreach ( $nodeStyleDiagnostics as $diagnostic ) {
            $family = $diagnostic['expected']['font_family'] ?? null;
            if ( is_scalar($family) && '' !== $this->primaryFontFamily((string) $family) ) {
                $families[] = $this->primaryFontFamily((string) $family);
            }
        }

        sort($families);
        return array_values(array_unique($families));
    }

    /**
     * @param array<int, array<string, mixed>> $nodeStyleDiagnostics
     * @return array<int, array<string, mixed>>
     */
    private function fontUsage(array $nodeStyleDiagnostics): array
    {
        $usageByFamily = array();
        foreach ( $nodeStyleDiagnostics as $diagnostic ) {
            $expected = is_array($diagnostic['expected'] ?? null) ? $diagnostic['expected'] : array();
            if ( ! isset($expected['font_family']) || ! is_scalar($expected['font_family']) ) {
                continue;
            }

            $family = $this->primaryFontFamily((string) $expected['font_family']);
            if ( '' === $family ) {
                continue;
            }

            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $weight = isset($expected['font_weight']) && is_numeric($expected['font_weight']) ? (int) $expected['font_weight'] : 400;
            $usageByFamily[$family] ??= array('weights' => array(), 'weight_counts' => array(), 'text_node_count' => 0, 'visible_text_area_px' => 0.0, 'sample_nodes' => array());
            $usageByFamily[$family]['weights'][] = $weight;
            $usageByFamily[$family]['weight_counts'][(string) $weight] = ($usageByFamily[$family]['weight_counts'][(string) $weight] ?? 0) + 1;
            $usageByFamily[$family]['text_node_count']++;
            $usageByFamily[$family]['visible_text_area_px'] += $this->diagnosticTextArea($expected);
            if ( count($usageByFamily[$family]['sample_nodes']) < 10 ) {
                $usageByFamily[$family]['sample_nodes'][] = array(
                    'node_id' => (string) ($node['id'] ?? ''),
                    'name' => (string) ($node['name'] ?? ''),
                    'weight' => $weight,
                );
            }
        }

        ksort($usageByFamily);
        $usage = array();
        foreach ( $usageByFamily as $family => $data ) {
            $weights = array_values(array_unique($data['weights']));
            sort($weights);
            ksort($data['weight_counts']);
            $usage[] = array(
                'family' => $family,
                'weights' => $weights,
                'weight_counts' => $data['weight_counts'],
                'text_node_count' => (int) $data['text_node_count'],
                'visible_text_area_px' => (int) round((float) $data['visible_text_area_px']),
                'sample_nodes' => $data['sample_nodes'],
            );
        }

        return $usage;
    }

    /**
     * @param array<string, string|null> $expected
     */
    private function diagnosticTextArea(array $expected): float
    {
        $width = $this->cssPxValue($expected['width'] ?? null);
        $height = $this->cssPxValue($expected['height'] ?? null);
        return max(0.0, $width) * max(0.0, $height);
    }

    private function cssPxValue(mixed $value): float
    {
        if ( ! is_scalar($value) ) {
            return 0.0;
        }

        $value = trim((string) $value);
        return preg_match('/^-?\d+(?:\.\d+)?px$/', $value) ? (float) substr($value, 0, -2) : 0.0;
    }

    /**
     * Extract the primary family from a (possibly multi-value) font-family
     * declaration, dropping the generic fallback so font detection keys on the
     * source family.
     */
    private function primaryFontFamily(string $value): string
    {
        $first = explode(',', $value, 2)[0];

        return trim($first, " \t\n\r\0\x0B\"'");
    }

    /**
     * Build one info diagnostic per unresolved source font family so operators
     * know exactly which families still need a supplied font.
     *
     * @param array<string, mixed> $fontResolution
     * @return array<int, array<string, mixed>>
     */
    private function unresolvedSourceFontDiagnostics(array $fontResolution): array
    {
        $diagnostics = array();
        foreach ( array_values($fontResolution['unresolved_families'] ?? array()) as $fontFamily ) {
            $diagnostics[] = array(
                'severity' => 'info',
                'code' => 'font_css_missing_for_source_font',
                'message' => 'Source font family could not be resolved to embedded or CDN font CSS; supply font_css to restore visual parity.',
                'context' => array('font_family' => (string) $fontFamily),
            );
        }

        return $diagnostics;
    }

    /**
     * @return array<string, mixed>
     */
    private function newLinkCoverage(): array
    {
        return array(
            'sources_found'      => 0,
            'anchors_emitted'    => 0,
            'url_links'          => 0,
            'node_links'         => 0,
            'unresolved'         => 0,
            'unresolved_targets' => array(),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function normalizeLinkTargetPaths(array $options): array
    {
        $map = array();
        $raw = is_array($options['link_target_paths'] ?? null) ? $options['link_target_paths'] : array();
        foreach ( $raw as $nodeId => $path ) {
            if ( is_scalar($nodeId) && is_scalar($path) && '' !== (string) $nodeId && '' !== (string) $path ) {
                $map[(string) $nodeId] = (string) $path;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function linkTargetPathsFromPagePlan(array $pagePlan, array $options): array
    {
        $map = $this->normalizeLinkTargetPaths($options);
        foreach ( $this->plannedPages($pagePlan) as $index => $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            if ( '' === $frameId || isset($map[$frameId]) ) {
                continue;
            }

            $name = (string) ($page['name'] ?? $frameId);
            $map[$frameId] = $this->pagePath($page, $name, is_int($index) ? $index : 0);
        }

        return $map;
    }

    /**
     * Wrap an emitted element in a real anchor when the node carries Figma link data.
     *
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function wrapWithLink(array $node, string $element, array &$diagnostics, bool $buttonLike = false): string
    {
        $link = is_array($node['figma_link'] ?? null) ? $node['figma_link'] : array();
        if ( empty($link) ) {
            return $element;
        }

        $this->linkCoverage['sources_found']++;
        $type = (string) ($link['type'] ?? '');
        $nodeId = (string) ($node['id'] ?? '');
        $targetNodeId = (string) ($link['target_node_id'] ?? '');
        $href = null;
        $resolved = false;

        if ( 'url' === $type ) {
            $this->linkCoverage['url_links']++;
            $href = $this->sanitizeLinkUrl((string) ($link['url'] ?? ''));
            $resolved = '#' !== $href;
        } elseif ( 'node' === $type ) {
            $this->linkCoverage['node_links']++;
            if ( '' !== $targetNodeId && isset($this->linkTargetPaths[$targetNodeId]) ) {
                $href = $this->linkTargetPaths[$targetNodeId];
                $resolved = true;
            } else {
                $href = '#';
            }
        }

        if ( null === $href ) {
            return $element;
        }

        if ( ! $resolved ) {
            $this->linkCoverage['unresolved']++;
            if ( count($this->linkCoverage['unresolved_targets']) < 50 ) {
                $this->linkCoverage['unresolved_targets'][] = array(
                    'node_id'        => $nodeId,
                    'link_type'      => $type,
                    'target_node_id' => $targetNodeId,
                    'source'         => (string) ($link['source'] ?? ''),
                );
            }
            $diagnostics[] = array(
                'severity' => 'info',
                'code'     => 'link_target_unresolved',
                'message'  => 'Figma link target could not be resolved to a generated page and was emitted as a placeholder anchor.',
                'context'  => array(
                    'node_id'        => $nodeId,
                    'link_type'      => $type,
                    'target_node_id' => $targetNodeId,
                    'source'         => (string) ($link['source'] ?? ''),
                ),
            );
        }

        $this->linkCoverage['anchors_emitted']++;

        return sprintf(
            "<a class=\"%4\$s\" href=\"%1\$s\" data-figma-link-type=\"%2\$s\">%3\$s</a>\n",
            $this->sanitizeAttribute($href),
            $this->sanitizeAttribute($type),
            $element,
            $buttonLike ? 'figma-link button' : 'figma-link'
        );
    }

    private function sanitizeLinkUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url ) {
            return '#';
        }

        if ( str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, '?') ) {
            return $url;
        }

        if ( 1 === preg_match('/^(https?:|mailto:|tel:)/i', $url) ) {
            return $url;
        }

        // Reject unsafe or unsupported schemes (javascript:, data:, etc.).
        if ( 1 === preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) ) {
            return '#';
        }

        // Schemeless relative reference (e.g. about.html, ../contact/).
        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function linkDiagnostics(): array
    {
        $coverage = $this->linkCoverage;

        return array(
            'schema'             => 'blocks-engine/figma-transformer/link-coverage/v1',
            'sources_found'      => (int) ($coverage['sources_found'] ?? 0),
            'anchors_emitted'    => (int) ($coverage['anchors_emitted'] ?? 0),
            'url_links'          => (int) ($coverage['url_links'] ?? 0),
            'node_links'         => (int) ($coverage['node_links'] ?? 0),
            'unresolved'         => (int) ($coverage['unresolved'] ?? 0),
            'unresolved_targets' => array_values(is_array($coverage['unresolved_targets'] ?? null) ? $coverage['unresolved_targets'] : array()),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function visualNodeMap(array $nodes): array
    {
        return (new VisualNodeMapBuilder($this->assetsById, $this->renderTextGlyphPaths))->build($nodes);
    }

    /**
     * Build production-transform diagnostics for Figma import development.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @param array<int, array<string, mixed>> $assetFiles
     * @param array<int, string> $fontFamilies
     * @param array<int, array<string, mixed>> $fontUsage
     * @param array<string, mixed> $fontResolution
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function transformDiagnostics(array $nodes, array $visualNodeMap, array $assetFiles, array $fontFamilies, array $fontUsage, array $fontResolution, string $css, array $diagnostics, string $html = ''): array
    {
        $image = array(
            'paint_refs'      => 0,
            'node_refs'       => 0,
            'resolved_assets' => 0,
            'image_block_count' => 0,
            'total_node_count' => 0,
            'image_block_nodes' => array(),
            'missing_assets'  => array(),
        );
        $vectors = array(
            'nodes'                       => 0,
            'rendered_paths'              => 0,
            'rendered_asset_fallbacks'    => 0,
            'vector_network_decoded'      => 0,
            'boolean_operations_composed' => 0,
            'placeholders'                => 0,
            'placeholder_reasons'         => array(),
            'placeholder_nodes'           => array(),
            'child_composition'           => array(
                'vector_parent_node_count' => 0,
                'vector_child_node_count' => 0,
                'composed_parent_node_count' => 0,
                'uncomposed_parent_node_count' => 0,
                'uncomposed_vector_child_node_count' => 0,
                'sample_nodes' => array(),
            ),
        );
        $nodeDiagnosticIndex = $this->nodeDiagnosticIndex($nodes);
        $cssOffsetDiagnostics = $this->cssAbsoluteOffsetDiagnostics($css, $nodeDiagnosticIndex);
        $visualOffsetDiagnostics = $this->visualOffCanvasDiagnostics($visualNodeMap, $nodeDiagnosticIndex);
        $visualClipDiagnostics = $this->visualClipDiagnostics($visualNodeMap, $nodeDiagnosticIndex);
        $layout = array(
            'large_negative_left_count' => preg_match_all('/left:-[0-9]{3,}/', $css),
            'large_css_offset_count' => count($cssOffsetDiagnostics),
            'large_css_offset_nodes' => $cssOffsetDiagnostics,
            'off_canvas_visual_node_count' => count($visualOffsetDiagnostics),
            'off_canvas_visual_nodes' => $visualOffsetDiagnostics,
            'clipped_visual_node_count' => (int) ($visualClipDiagnostics['clipped_visual_node_count'] ?? 0),
            'clipped_visual_area_ratio' => (float) ($visualClipDiagnostics['clipped_visual_area_ratio'] ?? 0.0),
            'clipped_visual_area_px' => (int) ($visualClipDiagnostics['clipped_visual_area_px'] ?? 0),
            'clipped_visual_nodes' => is_array($visualClipDiagnostics['clipped_visual_nodes'] ?? null) ? $visualClipDiagnostics['clipped_visual_nodes'] : array(),
            'large_absolute_offset_count' => 0,
            'large_absolute_offset_nodes' => array(),
            'empty_visible_container_count' => 0,
            'empty_visible_container_blocker_count' => 0,
            'empty_visible_container_categories' => array(),
            'empty_visible_containers' => array(),
            'decorative_underlays'      => array(
                'count' => 0,
                'nodes' => array(),
            ),
            'image_heavy_landmark_candidates' => array(),
            'layout_mismatch_count' => 0,
            'layout_mismatch_status' => 'not_evaluated',
            'stacking_order' => array(
                'mixed_positioning_parent_count' => 0,
                'absolute_child_count' => 0,
                'flow_child_count' => 0,
                'sample_nodes' => array(),
            ),
            'sticky_ghosts' => array(
                'count' => count($this->stickyLayoutCoordinator()->stickyGhostCandidates()),
                'candidates' => $this->stickyLayoutCoordinator()->stickyGhostCandidates(),
            ),
        );
        $components = array(
            'schema' => 'blocks-engine/figma-transformer/component-coverage/v1',
            'clone_source_node_count' => 0,
            'emitted_clone_node_count' => 0,
            'override_applied_node_count' => 0,
            'override_candidate_node_count' => 0,
            'missing_emitted_clone_node_count' => 0,
            'clone_nodes' => array(),
            'override_nodes' => array(),
            'missing_emitted_clone_nodes' => array(),
        );
        $effects = array(
            'schema' => 'blocks-engine/figma-transformer/effect-coverage/v1',
            'source_effect_node_count' => 0,
            'emitted_effect_node_count' => 0,
            'missing_emitted_effect_node_count' => 0,
            'by_type' => array(),
            'field_coverage' => array(),
            'effect_nodes' => array(),
            'missing_emitted_effect_nodes' => array(),
        );
        $maskEffectClipping = array(
            'schema' => 'blocks-engine/figma-transformer/mask-effect-clipping/v1',
            'mask_node_count' => 0,
            'mask_metadata_node_count' => 0,
            'clips_content_node_count' => 0,
            'effect_node_count' => 0,
            'clipped_effect_node_count' => 0,
            'by_mask_type' => array(),
            'sample_nodes' => array(),
        );

        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->collectTransformDiagnostics($node, $image, $vectors, $layout, $components, $effects, $maskEffectClipping, $html, $css);
            }
        }

        $image['missing_assets'] = array_values($image['missing_assets']);
        $image['image_block_nodes'] = array_values($image['image_block_nodes']);
        $vectors['placeholder_nodes'] = array_values($vectors['placeholder_nodes']);
        $vectors['decode_coverage'] = $this->transformDiagnosticsBuilder()->vectorDecodeCoverage($vectors);
        $layout['decorative_underlays']['nodes'] = array_values($layout['decorative_underlays']['nodes']);
        $layout['decorative_underlays']['count'] = count($layout['decorative_underlays']['nodes']);
        $layout['large_absolute_offset_nodes'] = array_values($layout['large_absolute_offset_nodes']);
        $layout['empty_visible_containers'] = array_values($layout['empty_visible_containers']);
        $layout['empty_visible_container_count'] = count($layout['empty_visible_containers']);
        $layout['empty_visible_container_blocker_count'] = count(array_filter(
            $layout['empty_visible_containers'],
            static fn (array $container): bool => true === ($container['blocks_parity'] ?? true)
        ));
        ksort($layout['empty_visible_container_categories']);
        $layout['image_heavy_landmark_candidates'] = array_values($layout['image_heavy_landmark_candidates']);
        $layout['stacking_order']['sample_nodes'] = array_slice($layout['stacking_order']['sample_nodes'], 0, 25);
        $components['clone_nodes'] = array_slice($components['clone_nodes'], 0, 25);
        $components['override_nodes'] = array_slice($components['override_nodes'], 0, 25);
        $components['missing_emitted_clone_nodes'] = array_slice($components['missing_emitted_clone_nodes'], 0, 25);
        ksort($effects['field_coverage']);
        $effects['effect_nodes'] = array_slice($effects['effect_nodes'], 0, 25);
        $effects['missing_emitted_effect_nodes'] = array_slice($effects['missing_emitted_effect_nodes'], 0, 25);
        ksort($effects['by_type']);
        ksort($maskEffectClipping['by_mask_type']);
        $maskEffectClipping['sample_nodes'] = array_slice($maskEffectClipping['sample_nodes'], 0, 25);
        $generatedSvgAssets = $this->generatedSvgAssetDiagnostics($assetFiles);
        $assets = array(
            'emitted_files' => count($assetFiles),
            'paths'         => array_values(array_map(static fn (array $file): string => (string) ($file['path'] ?? ''), $assetFiles)),
        );
        $fontCss = (string) ($fontResolution['css'] ?? '');
        $fonts = array(
            'families'                => $fontFamilies,
            'usage'                   => $fontUsage,
            'count'                   => count($fontFamilies),
            'css_supplied'            => (bool) ($fontResolution['operator_supplied'] ?? false),
            'materialized'            => '' !== $fontCss,
            'missing_css'             => array_values($fontResolution['unresolved_families'] ?? array()),
            'resolved_css'            => array_values($fontResolution['resolved_families'] ?? array()),
            'cdn_families'            => array_values($fontResolution['cdn_families'] ?? array()),
            'family_overrides_applied' => array_values($fontResolution['family_overrides_applied'] ?? array()),
            'coverage'                => array_values($fontResolution['coverage'] ?? array()),
        );

        $text = $this->textCoverageDiagnostics($nodes, $html);
        $links = $this->linkDiagnostics();

        return array(
            'schema' => 'blocks-engine/figma-transformer/transform-diagnostics/v1',
            'selection' => $this->selectionDiagnostics($nodes),
            'images' => $image,
            'vectors' => $vectors,
            'fonts' => $fonts,
            'text' => $text,
            'components' => $components,
            'effects' => $effects,
            'mask_effect_clipping' => $maskEffectClipping,
            'assets' => $assets,
            'generated_svg_assets' => $generatedSvgAssets,
            'layout' => $layout,
            'links' => $links,
            'artifact_quality' => $this->transformDiagnosticsBuilder()->artifactQualityDiagnostics($image, $vectors, $fonts, $assets, $generatedSvgAssets, $layout, $links, $text, $components, $effects, $maskEffectClipping),
            'diagnostic_codes' => $this->diagnosticCodeCounts($diagnostics),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function selectionDiagnostics(array $nodes): array
    {
        $frames = array();
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $frames[] = $this->selectedFrameDiagnostic($node, 'index.html', true);
            }
        }

        return array(
            'schema' => 'blocks-engine/figma-transformer/selection/v1',
            'mode' => count($frames) > 1 ? 'root_nodes' : 'single_root',
            'page_count' => count($frames),
            'selected_frames' => $frames,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function selectedFrameDiagnostic(array $node, string $path, bool $entrypoint): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $assetReferences = $this->countAssetReferences($node);

        return array_filter(array(
            'frame_id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '',
            'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : '',
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'path' => $path,
            'entrypoint' => $entrypoint,
            'width' => $this->reportNumericValue($box['width'] ?? null),
            'height' => $this->reportNumericValue($box['height'] ?? null),
            'node_count' => $this->countNodes(array($node)),
            'asset_reference_count' => $assetReferences,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function countAssetReferences(array $node): int
    {
        $count = (! empty($this->explicitNodeAssetReferences($node)) || ! empty($this->nodeImagePaints($node))) ? 1 : 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $count += $this->countAssetReferences($child);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<string, mixed>
     */
    private function generatedSvgAssetDiagnostics(array $assetFiles): array
    {
        $assets = array();
        foreach ( $assetFiles as $file ) {
            $sourceId = (string) ($file['source_id'] ?? '');
            if ( 'image/svg+xml' !== ($file['mime_type'] ?? null) || ! str_starts_with($sourceId, 'generated-vector-') ) {
                continue;
            }

            $content = (string) ($file['content'] ?? '');
            $assets[] = array_merge(array(
                'id'        => $sourceId,
                'path'      => (string) ($file['path'] ?? ''),
                'mime_type' => 'image/svg+xml',
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            ), $this->svgAssetMetrics($content));
        }

        usort($assets, static fn (array $a, array $b): int => ((int) $b['bytes'] <=> (int) $a['bytes']) ?: strcmp((string) $a['path'], (string) $b['path']));

        return array(
            'schema' => 'blocks-engine/figma-transformer/generated-svg-assets/v1',
            'threshold_bytes' => self::EXTERNAL_VECTOR_SVG_BYTES,
            'count' => count($assets),
            'bytes' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['bytes'] ?? 0), $assets)),
            'gzip_bytes' => $this->sumNullableAssetMetric($assets, 'gzip_bytes'),
            'path_element_count' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_element_count'] ?? 0), $assets)),
            'path_data_bytes' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_data_bytes'] ?? 0), $assets)),
            'largest_path_data_bytes' => empty($assets) ? 0 : max(array_map(static fn (array $asset): int => (int) ($asset['largest_path_data_bytes'] ?? 0), $assets)),
            'unique_path_data_count' => $this->uniqueAssetPathDataCount($assets),
            'duplicate_path_data_count' => $this->duplicateAssetPathDataCount($assets),
            'paths' => array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets)),
            'largest_assets' => array_slice($assets, 0, 10),
            'assets' => $assets,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function sumNullableAssetMetric(array $assets, string $key): ?int
    {
        $sum = 0;
        foreach ( $assets as $asset ) {
            if ( ! array_key_exists($key, $asset) || null === $asset[$key] ) {
                return null;
            }
            $sum += (int) $asset[$key];
        }

        return $sum;
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function uniqueAssetPathDataCount(array $assets): int
    {
        $hashes = array();
        foreach ( $assets as $asset ) {
            foreach ( is_array($asset['path_data_hashes'] ?? null) ? $asset['path_data_hashes'] : array() as $hash ) {
                if ( is_scalar($hash) && '' !== (string) $hash ) {
                    $hashes[(string) $hash] = true;
                }
            }
        }

        return count($hashes);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function duplicateAssetPathDataCount(array $assets): int
    {
        $pathDataCount = array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_data_count'] ?? 0), $assets));

        return max(0, $pathDataCount - $this->uniqueAssetPathDataCount($assets));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $image
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $components
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $maskEffectClipping
     */
    private function collectTransformDiagnostics(array $node, array &$image, array &$vectors, array &$layout, array &$components, array &$effects, array &$maskEffectClipping, string $html, string $css, ?array $parentNode = null): void
    {
        if ( $this->stickyLayoutCoordinator()->isSuppressedStickyGhost($node) ) {
            return;
        }

        ++$image['total_node_count'];

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();

        if ( null !== $parentNode ) {
            $offset = $this->largeAbsoluteOffsetDiagnostic($node, $parentNode);
            if ( null !== $offset ) {
                ++$layout['large_absolute_offset_count'];
                $layout['large_absolute_offset_nodes'][] = $offset;
            }
        }

        if ( null !== $parentNode && $this->isDecorativeFlexUnderlay($node, $parentNode) ) {
            $layout['decorative_underlays']['nodes'][] = $this->decorativeUnderlayDiagnostic($node, $parentNode);
        }

        $stackingOrder = $this->stackingOrderDiagnostic($node);
        if ( null !== $stackingOrder ) {
            ++$layout['stacking_order']['mixed_positioning_parent_count'];
            $layout['stacking_order']['absolute_child_count'] += (int) ($stackingOrder['absolute_child_count'] ?? 0);
            $layout['stacking_order']['flow_child_count'] += (int) ($stackingOrder['flow_child_count'] ?? 0);
            $layout['stacking_order']['sample_nodes'][] = $stackingOrder;
        }

        $this->collectComponentCoverageDiagnostics($node, $components, $html);
        $this->collectEffectCoverageDiagnostics($node, $effects, $maskEffectClipping, $html, $css);
        $this->collectMaskEffectClippingDiagnostics($node, $maskEffectClipping);

        $emptyContainer = $this->emptyVisibleContainerDiagnostic($node, $parentNode);
        if ( null !== $emptyContainer ) {
            $layout['empty_visible_containers'][] = $emptyContainer;
            $category = (string) ($emptyContainer['category'] ?? 'empty_visible_container');
            $layout['empty_visible_container_categories'][$category] = (int) ($layout['empty_visible_container_categories'][$category] ?? 0) + 1;
        }

        $landmarkCandidate = $this->imageHeavyLandmarkCandidate($node);
        if ( null !== $landmarkCandidate ) {
            $layout['image_heavy_landmark_candidates'][] = $landmarkCandidate;
        }

        $imagePaints = $this->nodeImagePaints($node);
        if ( ! empty($imagePaints) ) {
            $image['paint_refs'] += count($imagePaints);
        }

        $assetReferences = $this->explicitNodeAssetReferences($node);
        $hasAssetExpectation = ! empty($assetReferences) || ! empty($imagePaints);
        if ( $hasAssetExpectation ) {
            ++$image['node_refs'];
            if ( null !== $this->nodeAssetPath($node) ) {
                ++$image['resolved_assets'];
                ++$image['image_block_count'];
                $image['image_block_nodes'][] = array(
                    'node_id' => (string) ($node['id'] ?? ''),
                    'name'    => (string) ($node['name'] ?? ''),
                    'type'    => strtoupper((string) ($node['type'] ?? '')),
                );
            } else {
                $image['missing_assets'][] = array(
                    'node_id' => (string) ($node['id'] ?? ''),
                    'name'    => (string) ($node['name'] ?? ''),
                    'type'    => strtoupper((string) ($node['type'] ?? '')),
                    'refs'    => array_values(array_unique(array_merge($assetReferences, $this->imagePaintReferences($node)))),
                );
            }
        }

        $type = strtoupper((string) ($node['type'] ?? ''));
        $booleanComposedChildren = false;
        if ( $this->isUnsupportedVectorType($type) ) {
            ++$vectors['nodes'];
            $this->collectVectorChildCompositionDiagnostics($node, $vectors, $parentNode);
            $vectorSvg = $this->supportedVectorSvg($node, $type, $parentNode);
            if ( null !== $vectorSvg ) {
                ++$vectors['rendered_paths'];
                if ( $this->vectorPathsIncludeNetworkSource($node) ) {
                    ++$vectors['vector_network_decoded'];
                }
                if ( 'BOOLEAN_OPERATION' === $type && ! empty($this->nodeList($node)) ) {
                    ++$vectors['boolean_operations_composed'];
                    $booleanComposedChildren = true;
                }
            } elseif ( null !== $this->nodeAssetPath($node) ) {
                ++$vectors['rendered_asset_fallbacks'];
            } else {
                ++$vectors['placeholders'];
                $placeholder = $this->vectorPlaceholderDiagnostic($node, $type, $parentNode);
                $reason = (string) ($placeholder['reason'] ?? 'unknown');
                $vectors['placeholder_reasons'][$reason] = (int) ($vectors['placeholder_reasons'][$reason] ?? 0) + 1;
                $vectors['placeholder_nodes'][] = $placeholder;
            }
        }

        // A composed boolean operation folds its child geometry into one SVG, so
        // the children are not emitted separately; mirror that here to keep the
        // vector counts aligned with what is actually rendered.
        if ( $booleanComposedChildren ) {
            return;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                if ( $this->isFullyClippedDecorativeChild($child, $node) ) {
                    continue;
                }
                $this->collectTransformDiagnostics($child, $image, $vectors, $layout, $components, $effects, $maskEffectClipping, $html, $css, $node);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $components
     */
    private function collectComponentCoverageDiagnostics(array $node, array &$components, string $html): void
    {
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        $hasOverride = true === ($node['_figma_instance_override_applied'] ?? false);
        if ( '' !== $sourceId ) {
            ++$components['clone_source_node_count'];
            $sample = $this->nodeCoverageSample($node);
            $sample['source_node_id'] = $sourceId;
            $components['clone_nodes'][] = $sample;
            if ( $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')) ) {
                ++$components['emitted_clone_node_count'];
            } else {
                ++$components['missing_emitted_clone_node_count'];
                $components['missing_emitted_clone_nodes'][] = $sample;
            }
        }

        if ( $hasOverride || is_array($node['overrides'] ?? null) ) {
            ++$components['override_candidate_node_count'];
        }
        if ( $hasOverride ) {
            ++$components['override_applied_node_count'];
            $components['override_nodes'][] = $this->nodeCoverageSample($node);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $maskEffectClipping
     */
    private function collectEffectCoverageDiagnostics(array $node, array &$effects, array &$maskEffectClipping, string $html, string $css): void
    {
        $nodeEffects = is_array($node['figma_effects'] ?? null) ? $node['figma_effects'] : array();
        if ( empty($nodeEffects) ) {
            return;
        }

        ++$effects['source_effect_node_count'];
        ++$maskEffectClipping['effect_node_count'];
        foreach ( $nodeEffects as $effect ) {
            if ( ! is_array($effect) ) {
                continue;
            }
            $type = (string) ($effect['type'] ?? 'unknown');
            $effects['by_type'][$type] = (int) ($effects['by_type'][$type] ?? 0) + 1;
            foreach ( array('source_type', 'offset_x', 'offset_y', 'radius', 'spread', 'color', 'blend_mode', 'show_shadow_behind_node') as $field ) {
                if ( array_key_exists($field, $effect) ) {
                    $effects['field_coverage'][$field] = (int) ($effects['field_coverage'][$field] ?? 0) + 1;
                }
            }
        }

        $sample = $this->nodeCoverageSample($node);
        $sample['effect_types'] = array_values(array_map(
            static fn (array $effect): string => (string) ($effect['type'] ?? 'unknown'),
            array_filter($nodeEffects, 'is_array')
        ));
        $effects['effect_nodes'][] = $sample;

        $class = $this->nodeDiagnosticClass($node);
        $hasEffectCss = str_contains($css, '.' . $class . '{') && preg_match('/\.' . preg_quote($class, '/') . '\{[^}]*(?:box-shadow|text-shadow|filter|backdrop-filter):/s', $css);
        if ( $hasEffectCss ) {
            ++$effects['emitted_effect_node_count'];
            return;
        }

        ++$effects['missing_emitted_effect_node_count'];
        $effects['missing_emitted_effect_nodes'][] = $this->nodeCoverageSample($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $maskEffectClipping
     */
    private function collectMaskEffectClippingDiagnostics(array $node, array &$maskEffectClipping): void
    {
        $mask = is_array($node['figma_mask'] ?? null) ? $node['figma_mask'] : array();
        if ( ! empty($mask) ) {
            ++$maskEffectClipping['mask_metadata_node_count'];
            $maskSample = $this->nodeCoverageSample($node);
            foreach ( array('is_mask', 'type', 'frame_mask_disabled', 'is_clip') as $field ) {
                if ( array_key_exists($field, $mask) ) {
                    $maskSample[$field] = $mask[$field];
                }
            }
            if ( isset($mask['type']) && is_scalar($mask['type']) ) {
                $maskType = (string) $mask['type'];
                $maskEffectClipping['by_mask_type'][$maskType] = (int) ($maskEffectClipping['by_mask_type'][$maskType] ?? 0) + 1;
            }
            $maskEffectClipping['sample_nodes'][] = $maskSample;
        }
        if ( true === ($mask['is_mask'] ?? null) || true === ($node['isMask'] ?? null) || true === ($node['mask'] ?? null) ) {
            ++$maskEffectClipping['mask_node_count'];
        }
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( true === ($layout['clips_content'] ?? false) ) {
            ++$maskEffectClipping['clips_content_node_count'];
        }
        if ( ! empty($node['figma_effects']) && true === ($layout['clips_content'] ?? false) ) {
            ++$maskEffectClipping['clipped_effect_node_count'];
            $maskEffectClipping['sample_nodes'][] = $this->nodeCoverageSample($node);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $vectors
     */
    private function collectVectorChildCompositionDiagnostics(array $node, array &$vectors, ?array $parentNode): void
    {
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        $vectorChildren = array_filter($children, fn (array $child): bool => $this->isUnsupportedVectorType(strtoupper((string) ($child['type'] ?? ''))));
        if ( empty($vectorChildren) ) {
            return;
        }

        ++$vectors['child_composition']['vector_parent_node_count'];
        $vectors['child_composition']['vector_child_node_count'] += count($vectorChildren);
        if ( null !== $this->supportedVectorSvg($node, strtoupper((string) ($node['type'] ?? '')), $parentNode) ) {
            ++$vectors['child_composition']['composed_parent_node_count'];
            return;
        }

        ++$vectors['child_composition']['uncomposed_parent_node_count'];
        $vectors['child_composition']['uncomposed_vector_child_node_count'] += count($vectorChildren);
        $sample = $this->nodeCoverageSample($node);
        $sample['vector_child_count'] = count($vectorChildren);
        $vectors['child_composition']['sample_nodes'][] = $sample;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function stackingOrderDiagnostic(array $node): ?array
    {
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        if ( count($children) < 2 ) {
            return null;
        }
        $absolute = 0;
        $flow = 0;
        foreach ( $children as $child ) {
            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($layout['positioning'] ?? null) ) {
                ++$absolute;
            } else {
                ++$flow;
            }
        }
        if ( 0 === $absolute || 0 === $flow ) {
            return null;
        }

        return array_merge($this->nodeCoverageSample($node), array(
            'absolute_child_count' => $absolute,
            'flow_child_count' => $flow,
        ));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function nodeCoverageSample(array $node): array
    {
        return array_filter(array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $this->nodeDiagnosticClass($node),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * Whether a node's decoded vector geometry originates from a raw Figma
     * vectorNetwork blob, used to credit network-decode coverage distinctly
     * from ready-made path/command-blob geometry.
     *
     * @param array<string, mixed> $node
     */
    private function vectorPathsIncludeNetworkSource(array $node): bool
    {
        if ( ! is_array($node['figma_vector_paths'] ?? null) ) {
            return false;
        }

        foreach ( $node['figma_vector_paths'] as $path ) {
            if ( is_array($path) && str_starts_with((string) ($path['source'] ?? ''), 'vectorData.vectorNetworkBlob') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array<string, mixed>|null
     */
    private function largeAbsoluteOffsetDiagnostic(array $node, array $parentNode): ?array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( $this->isDecorativeFlexUnderlay($node, $parentNode) || ('absolute' !== ($layout['positioning'] ?? null) && ! $this->isFreeformContainer($parentNode)) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $left = $this->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->positionOffset($box, $parentBox, 'y', $parentNode);
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0;
        $parentWidth = isset($parentBox['width']) && is_numeric($parentBox['width']) ? (float) $parentBox['width'] : null;
        $parentHeight = isset($parentBox['height']) && is_numeric($parentBox['height']) ? (float) $parentBox['height'] : null;
        $offCanvas = (null !== $left && ($left < -100.0 || (null !== $parentWidth && $left > $parentWidth + 100.0) || $left + $width < -100.0))
            || (null !== $top && ($top < -100.0 || (null !== $parentHeight && $top > $parentHeight + 100.0) || $top + $height < -100.0));

        if ( ! $offCanvas ) {
            return null;
        }

        return array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $this->nodeDiagnosticClass($node),
            'parent_id' => (string) ($parentNode['id'] ?? ''),
            'left' => null === $left ? null : $this->reportNumericValue($left),
            'top' => null === $top ? null : $this->reportNumericValue($top),
            'width' => $this->reportNumericValue($width),
            'height' => $this->reportNumericValue($height),
            'parent_width' => null === $parentWidth ? null : $this->reportNumericValue($parentWidth),
            'parent_height' => null === $parentHeight ? null : $this->reportNumericValue($parentHeight),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>}
     */
    private function nodeDiagnosticIndex(array $nodes): array
    {
        $index = array('by_id' => array(), 'by_class' => array());
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->appendNodeDiagnosticIndex($node, $index);
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $index
     */
    private function appendNodeDiagnosticIndex(array $node, array &$index): void
    {
        $entry = array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $this->nodeDiagnosticClass($node),
            'empty_visible_container' => null !== $this->emptyVisibleContainerDiagnostic($node),
            'component_clone_geometry' => $this->hasComponentCloneGeometry($node),
        );
        if ( '' !== $entry['node_id'] ) {
            $index['by_id'][$entry['node_id']] = $entry;
        }
        if ( '' !== $entry['class'] ) {
            $index['by_class'][$entry['class']] = $entry;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->appendNodeDiagnosticIndex($child, $index);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeDiagnosticClass(array $node): string
    {
        return 'figma-node-' . $this->slug((string) ($node['id'] ?? '') . '-' . (string) ($node['name'] ?? ''));
    }

    /**
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $nodeIndex
     * @return array<int, array<string, mixed>>
     */
    private function cssAbsoluteOffsetDiagnostics(string $css, array $nodeIndex): array
    {
        $samples = array();
        if ( ! preg_match_all('/\.(figma-node-[A-Za-z0-9_-]+)\{([^}]*)\}/s', $css, $rules, PREG_SET_ORDER) ) {
            return $samples;
        }

        foreach ( $rules as $rule ) {
            $className = (string) ($rule[1] ?? '');
            $body = (string) ($rule[2] ?? '');
            $left = $this->cssPixelDeclarationValue($body, 'left');
            $top = $this->cssPixelDeclarationValue($body, 'top');
            if ( (null === $left || abs($left) < 1000.0) && (null === $top || abs($top) < 1000.0) ) {
                continue;
            }

            $node = is_array($nodeIndex['by_class'][$className] ?? null) ? $nodeIndex['by_class'][$className] : array();
            $sample = array_filter(array(
                'node_id' => (string) ($node['node_id'] ?? ''),
                'name' => (string) ($node['name'] ?? ''),
                'type' => (string) ($node['type'] ?? ''),
                'class' => $className,
                'left' => null === $left ? null : $this->reportNumericValue($left),
                'top' => null === $top ? null : $this->reportNumericValue($top),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
            $classification = $this->largeCssOffsetClassification($node);
            if ( '' !== $classification ) {
                $sample['classification'] = $classification;
            }
            $samples[] = $sample;
        }

        return array_values($samples);
    }

    private function cssPixelDeclarationValue(string $body, string $property): ?float
    {
        return preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . ':\s*(-?\d+(?:\.\d+)?)px(?:;|$)/', $body, $match)
            ? (float) $match[1]
            : null;
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $nodeIndex
     * @return array<int, array<string, mixed>>
     */
    private function visualOffCanvasDiagnostics(array $visualNodeMap, array $nodeIndex): array
    {
        $byId = array();
        foreach ( $visualNodeMap as $entry ) {
            if ( is_array($entry) && isset($entry['id']) && is_scalar($entry['id']) ) {
                $byId[(string) $entry['id']] = $entry;
            }
        }

        $samples = array();
        foreach ( $visualNodeMap as $entry ) {
            if ( ! is_array($entry) || ! isset($entry['parent_id']) || '' === (string) $entry['parent_id'] || ! is_array($entry['rect'] ?? null) ) {
                continue;
            }
            $parent = $byId[(string) $entry['parent_id']] ?? null;
            if ( ! is_array($parent) || ! is_array($parent['rect'] ?? null) ) {
                continue;
            }
            $rect = $entry['rect'];
            $parentRect = $parent['rect'];
            foreach ( array('x', 'y', 'width', 'height') as $key ) {
                if ( ! is_numeric($rect[$key] ?? null) || ! is_numeric($parentRect[$key] ?? null) ) {
                    continue 2;
                }
            }

            $offCanvas = (float) $rect['x'] < (float) $parentRect['x'] - 100.0
                || (float) $rect['x'] > (float) $parentRect['x'] + (float) $parentRect['width'] + 100.0
                || (float) $rect['x'] + (float) $rect['width'] < (float) $parentRect['x'] - 100.0
                || (float) $rect['y'] < (float) $parentRect['y'] - 100.0
                || (float) $rect['y'] > (float) $parentRect['y'] + (float) $parentRect['height'] + 100.0
                || (float) $rect['y'] + (float) $rect['height'] < (float) $parentRect['y'] - 100.0;
            if ( ! $offCanvas ) {
                continue;
            }

            $node = is_array($nodeIndex['by_id'][(string) ($entry['id'] ?? '')] ?? null) ? $nodeIndex['by_id'][(string) $entry['id']] : array();
            $sample = array_filter(array(
                'node_id' => (string) ($entry['id'] ?? ''),
                'name' => (string) ($entry['name'] ?? ($node['name'] ?? '')),
                'type' => (string) ($entry['type'] ?? ($node['type'] ?? '')),
                'class' => (string) ($node['class'] ?? ''),
                'parent_id' => (string) ($entry['parent_id'] ?? ''),
                'left' => $this->reportNumericValue((float) $rect['x'] - (float) $parentRect['x']),
                'top' => $this->reportNumericValue((float) $rect['y'] - (float) $parentRect['y']),
                'x' => $this->reportNumericValue((float) $rect['x']),
                'y' => $this->reportNumericValue((float) $rect['y']),
                'width' => $this->reportNumericValue((float) $rect['width']),
                'height' => $this->reportNumericValue((float) $rect['height']),
                'parent_width' => $this->reportNumericValue((float) $parentRect['width']),
                'parent_height' => $this->reportNumericValue((float) $parentRect['height']),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
            $classification = $this->visualOffCanvasClassification($entry, $parent);
            if ( '' !== $classification ) {
                $sample['classification'] = $classification;
            }
            $samples[] = $sample;
        }

        return array_values($samples);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function largeCssOffsetClassification(array $node): string
    {
        if ( true === ($node['component_clone_geometry'] ?? false) ) {
            return 'component_clone_geometry_leak';
        }

        if ( true === ($node['empty_visible_container'] ?? false) ) {
            return 'empty_visible_container';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $parent
     */
    private function visualOffCanvasClassification(array $entry, array $parent): string
    {
        $parentLayout = is_array($parent['layout'] ?? null) ? $parent['layout'] : array();
        $entryLayout = is_array($entry['layout'] ?? null) ? $entry['layout'] : array();
        if ( in_array((string) ($parentLayout['display'] ?? ''), array('flex', 'inline-flex'), true) && 'absolute' !== ($entryLayout['positioning'] ?? null) ) {
            return 'flex_flow_overflow';
        }

        if ( true === ($entry['component_clone_geometry'] ?? false) ) {
            return 'component_clone_geometry_leak';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasComponentCloneGeometry(array $node): bool
    {
        if ( true === ($node['_component_source_clone_geometry'] ?? false) ) {
            return true;
        }

        foreach ( array('box', 'figma_box') as $boxKey ) {
            $box = is_array($node[$boxKey] ?? null) ? $node[$boxKey] : array();
            if ( 'component_source_clone' === ($box['geometry_semantics'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $nodeIndex
     * @return array<string, mixed>
     */
    private function visualClipDiagnostics(array $visualNodeMap, array $nodeIndex): array
    {
        $samples = array();
        $sourceArea = 0.0;
        $visibleArea = 0.0;

        foreach ( $visualNodeMap as $entry ) {
            if ( ! is_array($entry) || ! is_array($entry['rect'] ?? null) || ! is_array($entry['visible_rect'] ?? null) ) {
                continue;
            }

            $rect = $entry['rect'];
            $visibleRect = $entry['visible_rect'];
            foreach ( array('width', 'height') as $key ) {
                if ( ! is_numeric($rect[$key] ?? null) || ! is_numeric($visibleRect[$key] ?? null) ) {
                    continue 2;
                }
            }

            $entryArea = max(0.0, (float) $rect['width']) * max(0.0, (float) $rect['height']);
            $entryVisibleArea = max(0.0, (float) $visibleRect['width']) * max(0.0, (float) $visibleRect['height']);
            if ( $entryArea <= 0.0 || $entryVisibleArea >= $entryArea ) {
                continue;
            }

            $sourceArea += $entryArea;
            $visibleArea += $entryVisibleArea;
            $node = is_array($nodeIndex['by_id'][(string) ($entry['id'] ?? '')] ?? null) ? $nodeIndex['by_id'][(string) $entry['id']] : array();
            $samples[] = array_filter(array(
                'node_id' => (string) ($entry['id'] ?? ''),
                'name' => (string) ($entry['name'] ?? ($node['name'] ?? '')),
                'type' => (string) ($entry['type'] ?? ($node['type'] ?? '')),
                'class' => (string) ($node['class'] ?? ''),
                'parent_id' => (string) ($entry['parent_id'] ?? ''),
                'source_area_px' => $this->reportNumericValue($entryArea),
                'visible_area_px' => $this->reportNumericValue($entryVisibleArea),
                'clipped_area_px' => $this->reportNumericValue($entryArea - $entryVisibleArea),
                'clipped_area_ratio' => round(($entryArea - $entryVisibleArea) / $entryArea, 3),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        usort($samples, static fn (array $a, array $b): int => ((float) ($b['clipped_area_px'] ?? 0.0) <=> (float) ($a['clipped_area_px'] ?? 0.0)) ?: strcmp((string) ($a['node_id'] ?? ''), (string) ($b['node_id'] ?? '')));
        $clippedArea = max(0.0, $sourceArea - $visibleArea);

        return array(
            'clipped_visual_node_count' => count($samples),
            'clipped_visual_area_px' => $this->reportNumericValue($clippedArea),
            'visible_visual_area_px' => $this->reportNumericValue($visibleArea),
            'source_visual_area_px' => $this->reportNumericValue($sourceArea),
            'clipped_visual_area_ratio' => $sourceArea > 0.0 ? round($clippedArea / $sourceArea, 3) : 0.0,
            'clipped_visual_nodes' => array_slice($samples, 0, 25),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function textCoverageDiagnostics(array $nodes, string $html): array
    {
        $coverage = array(
            'schema' => 'blocks-engine/figma-transformer/text-coverage/v1',
            'decoded_text_node_count' => 0,
            'emitted_text_node_count' => 0,
            'empty_decoded_text_node_count' => 0,
            'missing_emitted_text_node_count' => 0,
            'empty_decoded_text_nodes' => array(),
            'missing_emitted_text_nodes' => array(),
        );

        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $page = array(
                    'page_id' => (string) ($node['id'] ?? ''),
                    'page_name' => (string) ($node['name'] ?? ''),
                );
                $this->appendTextCoverageDiagnostics($node, $html, $coverage, $page, true);
            }
        }

        $coverage['empty_decoded_text_nodes'] = array_slice($coverage['empty_decoded_text_nodes'], 0, 25);
        $coverage['missing_emitted_text_nodes'] = array_slice($coverage['missing_emitted_text_nodes'], 0, 25);

        return $coverage;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $coverage
     * @param array{page_id: string, page_name: string} $page
     */
    private function appendTextCoverageDiagnostics(array $node, string $html, array &$coverage, array $page, bool $isRoot): void
    {
        if ( ! $isRoot && false === ($node['visible'] ?? null) ) {
            return;
        }

        if ( $this->isInputLike($node) && $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')) ) {
            return;
        }

        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $rawText = $this->rawDecodedText($node);
            if ( '' === trim($rawText) ) {
                ++$coverage['empty_decoded_text_node_count'];
                $coverage['empty_decoded_text_nodes'][] = $this->textCoverageNodeSample($node, $page, 0);
            } else {
                ++$coverage['decoded_text_node_count'];
                if ( $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')) ) {
                    ++$coverage['emitted_text_node_count'];
                } else {
                    ++$coverage['missing_emitted_text_node_count'];
                    $coverage['missing_emitted_text_nodes'][] = $this->textCoverageNodeSample($node, $page, mb_strlen($rawText));
                }
            }
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->appendTextCoverageDiagnostics($child, $html, $coverage, $page, false);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function rawDecodedText(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $content = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $content .= (string) $segment['characters'];
                }
            }
            if ( '' !== $content ) {
                return $content;
            }
        }

        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }

        return (string) ($node['characters'] ?? $node['text'] ?? '');
    }

    private function htmlContainsNodeId(string $html, string $nodeId): bool
    {
        return '' !== $nodeId && str_contains($html, 'data-figma-node-id="' . $this->sanitizeAttribute($nodeId) . '"');
    }

    /**
     * @param array<string, mixed> $node
     * @param array{page_id: string, page_name: string} $page
     * @return array<string, mixed>
     */
    private function textCoverageNodeSample(array $node, array $page, int $characterCount): array
    {
        return array_filter(array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $this->nodeDiagnosticClass($node),
            'page_id' => $page['page_id'],
            'page_name' => $page['page_name'],
            'character_count' => $characterCount,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function emptyVisibleContainerDiagnostic(array $node, ?array $parentNode = null): ?array
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true) || false === ($node['visible'] ?? true) ) {
            return null;
        }
        if ( ! empty($this->nodeList($node)) || '' !== trim($this->textContent($node)) || ! empty($this->nodeImagePaints($node)) || ! empty($this->explicitNodeAssetReferences($node)) ) {
            return null;
        }
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0;
        if ( $width <= 0.0 || $height <= 0.0 ) {
            return null;
        }

        $category = $this->emptyVisibleContainerCategory($node, $type, $width, $height, $parentNode);

        return array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => $type,
            'class' => $this->nodeDiagnosticClass($node),
            'width' => $this->reportNumericValue($width),
            'height' => $this->reportNumericValue($height),
            'category' => $category,
            'blocks_parity' => ! $this->isNonBlockingEmptyVisibleContainerCategory($category),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function emptyVisibleContainerCategory(array $node, string $type, float $width, float $height, ?array $parentNode = null): string
    {
        $name = trim((string) ($node['name'] ?? ''));
        if ( $height <= 1.0 && preg_match('/^[\x{2013}\x{2014}-]+$/u', $name) ) {
            return 'decorative_zero_height_separator';
        }

        if ( $this->isFormControlChrome($node, $parentNode, $width, $height) ) {
            return 'form_control_chrome';
        }

        if ( 'INSTANCE' === $type ) {
            return 'missing_instance_descendants';
        }

        return 'empty_visible_container';
    }

    private function isNonBlockingEmptyVisibleContainerCategory(string $category): bool
    {
        return in_array($category, array('decorative_zero_height_separator', 'form_control_chrome'), true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isFormControlChrome(array $node, ?array $parentNode, float $width, float $height): bool
    {
        if ( null === $parentNode || $width < 10.0 || $height < 10.0 || $width > 40.0 || $height > 40.0 || abs($width - $height) > 2.0 ) {
            return false;
        }

        if ( empty($this->strokeStyles($node)) ) {
            return false;
        }

        $layout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( ! in_array((string) ($layout['flex_direction'] ?? ''), array('row', 'row-reverse'), true) && 'HORIZONTAL' !== ($layout['mode'] ?? null) ) {
            return false;
        }

        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || $nodeId === (string) ($sibling['id'] ?? '') ) {
                continue;
            }

            if ( '' !== trim($this->subtreePlainText($sibling)) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function imageHeavyLandmarkCandidate(array $node): ?array
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        $role = str_contains($name, 'header') ? 'header' : (str_contains($name, 'footer') ? 'footer' : null);
        if ( null === $role ) {
            return null;
        }

        $summary = $this->subtreeVisualSummary($node);
        if ( $summary['image_nodes'] < 3 || $summary['image_nodes'] < max(1, $summary['text_nodes'] * 2) ) {
            return null;
        }

        return array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'role' => $role,
            'image_nodes' => $summary['image_nodes'],
            'text_nodes' => $summary['text_nodes'],
            'total_nodes' => $summary['total_nodes'],
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array{image_nodes: int, text_nodes: int, total_nodes: int}
     */
    private function subtreeVisualSummary(array $node): array
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        $summary = array(
            'image_nodes' => null !== $this->nodeAssetPath($node) || ! empty($this->nodeImagePaints($node)) ? 1 : 0,
            'text_nodes' => 'TEXT' === $type ? 1 : 0,
            'total_nodes' => 1,
        );

        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childSummary = $this->subtreeVisualSummary($child);
            $summary['image_nodes'] += $childSummary['image_nodes'];
            $summary['text_nodes'] += $childSummary['text_nodes'];
            $summary['total_nodes'] += $childSummary['total_nodes'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array<string, mixed>
     */
    private function decorativeUnderlayDiagnostic(array $node, array $parentNode): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();

        return array(
            'node_id'       => (string) ($node['id'] ?? ''),
            'name'          => (string) ($node['name'] ?? ''),
            'parent_id'     => (string) ($parentNode['id'] ?? ''),
            'parent_name'   => (string) ($parentNode['name'] ?? ''),
            'width'         => $this->reportNumericValue($box['width'] ?? null),
            'height'        => $this->reportNumericValue($box['height'] ?? null),
            'parent_width'  => $this->reportNumericValue($parentBox['width'] ?? null),
            'parent_height' => $this->reportNumericValue($parentBox['height'] ?? null),
        );
    }

    private function reportNumericValue(mixed $value): mixed
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function explicitNodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $references[] = (string) $node[$key];
            }
        }
        if ( is_array($node['image'] ?? null) ) {
            foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
                if ( isset($node['image'][$key]) && is_scalar($node['image'][$key]) && '' !== (string) $node['image'][$key] ) {
                    $references[] = (string) $node['image'][$key];
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function imagePaintReferences(array $node): array
    {
        $references = array();
        foreach ( $this->nodeImagePaints($node) as $paint ) {
            foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
                if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                    $references[] = (string) $paint[$key];
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int>
     */
    private function diagnosticCodeCounts(array $diagnostics): array
    {
        $counts = array();
        foreach ( $diagnostics as $diagnostic ) {
            $code = is_array($diagnostic) ? (string) ($diagnostic['code'] ?? '') : '';
            if ( '' === $code ) {
                continue;
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isClippableDecorativeVisualNode(array $node): bool
    {
        return $this->layoutIntentClassifier()->isClippableDecorativeVisualNode($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isFullyClippedDecorativeChild(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( true !== ($parentLayout['clips_content'] ?? false) || ! $this->isClippableDecorativeVisualNode($node) ) {
            return false;
        }

        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($parentBox['width'], $parentBox['height'], $box['width'], $box['height']) || ! is_numeric($parentBox['width']) || ! is_numeric($parentBox['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) ) {
            return false;
        }

        $left = $this->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->positionOffset($box, $parentBox, 'y', $parentNode);
        if ( null === $left || null === $top ) {
            return false;
        }

        $parentRect = array('x' => 0.0, 'y' => 0.0, 'width' => (float) $parentBox['width'], 'height' => (float) $parentBox['height']);
        $childRect = array('x' => $left, 'y' => $top, 'width' => (float) $box['width'], 'height' => (float) $box['height']);

        return null === $this->rectIntersection($parentRect, $childRect);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $rect
     * @param array{x: float, y: float, width: float, height: float} $clipRect
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function rectIntersection(array $rect, array $clipRect): ?array
    {
        $left = max($rect['x'], $clipRect['x']);
        $top = max($rect['y'], $clipRect['y']);
        $right = min($rect['x'] + $rect['width'], $clipRect['x'] + $clipRect['width']);
        $bottom = min($rect['y'] + $rect['height'], $clipRect['y'] + $clipRect['height']);
        if ( $right <= $left || $bottom <= $top ) {
            return null;
        }

        return array('x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function styleDeclarations(array $node, string $type, ?array $parentNode): array
    {
        $styles = array();

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $zeroHeightVectorFallbackHeight = $this->zeroHeightVectorFallbackHeight($node, $type);
        foreach ( array('width', 'height') as $dimension ) {
            $sizingKey = 'width' === $dimension ? 'sizing_horizontal' : 'sizing_vertical';
            $sizing = strtoupper((string) ($layout[$sizingKey] ?? ''));
            if ( 'width' === $dimension && $this->isFluidRootWidth($box, $parentNode) ) {
                // Page root: centered fluid container. width:100% lets it shrink
                // below the design width without forcing horizontal scroll, while
                // max-width pins the intrinsic frame width so rendering stays
                // pixel-faithful at and above the native canvas size.
                $styles[] = 'width:100%';
                $styles[] = 'max-width:' . $this->number((float) $box['width']) . 'px';
                $styles[] = 'margin-left:auto';
                $styles[] = 'margin-right:auto';
            } elseif ( 'HUG' === $sizing ) {
                $derivedTextSize = 'TEXT' === $type ? $this->derivedTextLayoutSize($node, $dimension) : null;
                if ( null !== $derivedTextSize ) {
                    if ( 'height' === $dimension && $this->textShouldAvoidTinyFixedHeight($node, $derivedTextSize) && ! $this->textShouldUseMeasuredFlexHeight($node, $parentNode) ) {
                        continue;
                    }
                    $styles[] = $dimension . ':' . $this->number($derivedTextSize) . 'px';
                } elseif ( 'flex' === ($layout['display'] ?? null) && isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
                    $intrinsicMainAxisSize = $this->flexHugMainAxisIntrinsicSizeStyle($node, $dimension);
                    $styles[] = $dimension . ':' . (null === $intrinsicMainAxisSize ? $this->number((float) $box[$dimension]) . 'px' : $intrinsicMainAxisSize);
                } else {
                    $styles[] = $dimension . ':fit-content';
                }
            } elseif ( 'FILL' === $sizing ) {
                $styles[] = $dimension . ':100%';
            } elseif ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
                $property = $dimension;
                $value = 'height' === $dimension && null !== $zeroHeightVectorFallbackHeight ? $zeroHeightVectorFallbackHeight : (float) $box[$dimension];
                if ( 'height' === $dimension && 'TEXT' === $type && $this->textShouldAvoidTinyFixedHeight($node, $value) && ! $this->textShouldUseMeasuredFlexHeight($node, $parentNode) ) {
                    continue;
                }
                $styles[] = $property . ':' . $this->number($value) . 'px';
            }
        }

        $absoluteChildReserveHeight = $this->absoluteChildReserveHeight($node);
        if ( null !== $absoluteChildReserveHeight && ! $this->stylesDeclareProperty($styles, 'min-height') ) {
            $layoutMinHeight = isset($layout['min_height']) && is_numeric($layout['min_height']) ? (float) $layout['min_height'] : null;
            $styles[] = 'min-height:' . $this->number(null === $layoutMinHeight ? $absoluteChildReserveHeight : max($layoutMinHeight, $absoluteChildReserveHeight)) . 'px';
        }

        // Auto Layout min/max constraints (Kiwi minSize/maxSize). Skip a property
        // the width/height pass already emitted (e.g. the fluid root max-width).
        foreach ( array(
            'min_width'  => 'min-width',
            'max_width'  => 'max-width',
            'min_height' => 'min-height',
            'max_height' => 'max-height',
        ) as $layoutKey => $property ) {
            if ( isset($layout[$layoutKey]) && is_numeric($layout[$layoutKey]) && ! $this->stylesDeclareProperty($styles, $property) ) {
                $styles[] = $property . ':' . $this->number((float) $layout[$layoutKey]) . 'px';
            }
        }

        if ( $this->effectOverflowPolicy()->shouldHideOverflow($node, $this->stickyLayoutCoordinator()->containsStickyPrimary($node)) ) {
            $styles[] = 'overflow:hidden';
        }

        $isDecorativeFlexUnderlay = null !== $parentNode && $this->isDecorativeFlexUnderlay($node, $parentNode);
        $willPositionAbsolute = (null !== $parentNode && $this->isFreeformContainer($parentNode)) || 'absolute' === ($layout['positioning'] ?? null) || $isDecorativeFlexUnderlay;
        if ( ! $willPositionAbsolute && ($this->hasAbsoluteChild($node) || $this->hasDecorativeFlexUnderlayChild($node) || $this->isFreeformContainer($node)) ) {
            $styles[] = 'position:relative';
        }

        if ( $isDecorativeFlexUnderlay ) {
            $styles[] = 'position:absolute';
            foreach ( $this->cssPositioningResolver()->styles($box, $layout, $parentNode, $node) as $style ) {
                $styles[] = $style;
            }
            $styles[] = 'z-index:0';
            $styles[] = 'pointer-events:none';
        } elseif ( null !== $parentNode && $this->isFreeformContainer($parentNode) ) {
            $styles[] = 'position:absolute';
            foreach ( $this->cssPositioningResolver()->styles($box, $layout, $parentNode, $node) as $style ) {
                $styles[] = $style;
            }
        } elseif ( 'absolute' === ($layout['positioning'] ?? null) ) {
            $styles[] = 'position:absolute';
            foreach ( $this->cssPositioningResolver()->styles($box, $layout, $parentNode, $node) as $style ) {
                $styles[] = $style;
            }
        }

        if ( null !== $parentNode && ! $willPositionAbsolute && $this->hasDecorativeFlexUnderlayChild($parentNode) ) {
            $styles[] = 'position:relative';
            $styles[] = 'z-index:1';
        }

        if ( isset($layout['z_index']) && is_numeric($layout['z_index']) && ! $this->stylesDeclareProperty($styles, 'z-index') ) {
            $styles[] = 'z-index:' . (string) (int) $layout['z_index'];
        }

        if ( 'TEXT' !== $type && ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE'), true) ) {
            $background = $this->backgroundColor($node);
            if ( null !== $background ) {
                $styles[] = 'background:' . $background;
            }
        }

        $box = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        if ( isset($box['opacity']) && is_numeric($box['opacity']) ) {
            $styles[] = 'opacity:' . $this->number((float) $box['opacity']);
        }

        if ( isset($box['blend_mode']) && is_scalar($box['blend_mode']) ) {
            $blendMode = $this->blendModeCss((string) $box['blend_mode']);
            if ( null !== $blendMode ) {
                $styles[] = 'mix-blend-mode:' . $blendMode;
            }
        }

        $transform = $this->isNearZeroHeightContainer($node, $type) || $this->hasAbsoluteVisualBounds($node) ? null : $this->transformStyle($box);
        if ( null !== $transform ) {
            $styles[] = 'transform:' . $transform;
            if ( $this->hasExplicitTransformMatrix($box) ) {
                $styles[] = 'transform-origin:0 0';
            }
        }

        foreach ( $this->radiusStyles($box) as $style ) {
            $styles[] = $style;
        }

        if ( ! $this->rendersStrokeInsideInlineSvg($node, $type, $parentNode) ) {
            foreach ( $this->strokeStyles($node) as $style ) {
                $styles[] = $style;
            }
        }

        $assetPaths = $this->nodeAssetPaths($node);
        if ( ! empty($assetPaths) ) {
            $urlList = implode(',', array_map(static fn (string $p): string => 'url("' . $p . '")', $assetPaths));
            $styles[] = 'background-image:' . $urlList;
            $blendModes = $this->imageBackgroundBlendModes($node);
            if ( ! empty($blendModes) ) {
                $styles[] = 'background-blend-mode:' . implode(',', $blendModes);
            }
            foreach ( $this->imageBackgroundStyles($node) as $style ) {
                $styles[] = $style;
            }
        }

        if ( 'TEXT' === $type ) {
            foreach ( $this->textStyles($node) as $style ) {
                $styles[] = $style;
            }
            if ( $this->textShouldUseMeasuredFlexHeight($node, $parentNode) ) {
                $styles[] = 'overflow:visible';
            }
        }

        foreach ( $this->effectStyles($node, $type) as $style ) {
            $styles[] = $style;
        }

        foreach ( array(
            'display'         => 'display',
            'flex_direction'  => 'flex-direction',
            'justify_content' => 'justify-content',
            'align_items'     => 'align-items',
            'flex_wrap'       => 'flex-wrap',
        ) as $source => $property ) {
            if ( isset($layout[$source]) && is_scalar($layout[$source]) && '' !== (string) $layout[$source] ) {
                $styles[] = $property . ':' . (string) $layout[$source];
            }
        }
        if ( 'wrap' === ($layout['flex_wrap'] ?? null) ) {
            $styles[] = 'align-content:flex-start';
        }

        if ( isset($layout['padding']) && is_array($layout['padding']) ) {
            foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                if ( isset($layout['padding'][$edge]) && is_numeric($layout['padding'][$edge]) ) {
                    $styles[] = 'padding-' . $edge . ':' . $this->number($this->cssPaddingValue($node, $edge)) . 'px';
                }
            }
        }

        $justifyContent = (string) ($layout['justify_content'] ?? '');
        $usesDistributedMainAxis = in_array($justifyContent, array('space-between', 'space-around', 'space-evenly'), true);
        if ( ! $usesDistributedMainAxis && isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ) {
            $mainGap = $this->number((float) $layout['item_spacing']);
            if ( 'wrap' === ($layout['flex_wrap'] ?? null)
                && isset($layout['counter_axis_spacing'])
                && is_numeric($layout['counter_axis_spacing'])
                && (float) $layout['counter_axis_spacing'] !== (float) $layout['item_spacing'] ) {
                // CSS `gap` shorthand is `row-gap column-gap`. In a wrapping flex
                // row the main-axis item spacing is the column gap while the
                // counter-axis spacing (the gap between wrapped rows) is the row
                // gap; a wrapping column is the inverse.
                $counterGap = $this->number((float) $layout['counter_axis_spacing']);
                $isColumn = 'column' === ($layout['flex_direction'] ?? null);
                $rowGap = $isColumn ? $mainGap : $counterGap;
                $columnGap = $isColumn ? $counterGap : $mainGap;
                $styles[] = 'gap:' . $rowGap . 'px ' . $columnGap . 'px';
            } else {
                $styles[] = 'gap:' . $mainGap . 'px';
            }
        }

        if ( ! $isDecorativeFlexUnderlay ) {
            foreach ( $this->flexItemStyles($layout, $parentNode) as $style ) {
                $styles[] = $style;
            }
        }

        return $this->mergeBoxShadowDeclarations(array_values(array_unique($styles)));
    }

    /**
     * @param array<int, string> $styles
     * @return array<int, string>
     */
    private function mergeBoxShadowDeclarations(array $styles): array
    {
        $merged = array();
        $boxShadows = array();
        $boxShadowIndex = null;

        foreach ( $styles as $style ) {
            if ( str_starts_with($style, 'box-shadow:') ) {
                $boxShadows[] = substr($style, strlen('box-shadow:'));
                if ( null === $boxShadowIndex ) {
                    $boxShadowIndex = count($merged);
                    $merged[] = $style;
                }
                continue;
            }

            $merged[] = $style;
        }

        if ( null !== $boxShadowIndex && count($boxShadows) > 1 ) {
            $merged[$boxShadowIndex] = 'box-shadow:' . implode(',', $boxShadows);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function derivedTextLayoutSize(array $node, string $dimension): ?float
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $size = is_array($derivedLayout['size'] ?? null) ? $derivedLayout['size'] : array();
        if ( isset($size[$dimension]) && is_numeric($size[$dimension]) && 0.0 <= (float) $size[$dimension] ) {
            return (float) $size[$dimension];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function cssPaddingValue(array $node, string $edge): float
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        $value = isset($padding[$edge]) && is_numeric($padding[$edge]) ? (float) $padding[$edge] : 0.0;
        $axis = in_array($edge, array('left', 'right'), true) ? 'horizontal' : 'vertical';
        $dimension = 'horizontal' === $axis ? 'width' : 'height';
        $sizingKey = 'horizontal' === $axis ? 'sizing_horizontal' : 'sizing_vertical';
        if ( in_array(strtoupper((string) ($layout[$sizingKey] ?? '')), array('HUG', 'FILL'), true) ) {
            return $value;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return $value;
        }

        $start = 'horizontal' === $axis ? 'left' : 'top';
        $end = 'horizontal' === $axis ? 'right' : 'bottom';
        $startValue = isset($padding[$start]) && is_numeric($padding[$start]) ? (float) $padding[$start] : 0.0;
        $endValue = isset($padding[$end]) && is_numeric($padding[$end]) ? (float) $padding[$end] : 0.0;
        $sum = $startValue + $endValue;
        $available = max(0.0, (float) $box[$dimension]);
        if ( $sum <= 0.0 || $sum <= $available ) {
            return $value;
        }

        return $value * ($available / $sum);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function flexHugMainAxisIntrinsicSizeStyle(array $node, string $dimension): ?string
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $isRow = 'row' === ($layout['flex_direction'] ?? null);
        $mainAxis = $isRow ? 'width' : 'height';
        if ( $dimension !== $mainAxis || 'wrap' === ($layout['flex_wrap'] ?? null) || ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        $children = $this->nodeList($node);
        if ( empty($children) ) {
            return null;
        }

        $childCount = 0;
        $childMainSpan = 0.0;
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($childLayout['positioning'] ?? null) || $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( ! isset($childBox[$mainAxis]) || ! is_numeric($childBox[$mainAxis]) ) {
                return null;
            }

            $childMainSpan += (float) $childBox[$mainAxis];
            $childCount++;
        }

        if ( 0 === $childCount ) {
            return null;
        }

        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        $paddingStart = $isRow ? 'left' : 'top';
        $paddingEnd = $isRow ? 'right' : 'bottom';
        $paddingSpan = 0.0;
        foreach ( array($paddingStart, $paddingEnd) as $edge ) {
            if ( isset($padding[$edge]) && is_numeric($padding[$edge]) ) {
                $paddingSpan += (float) $padding[$edge];
            }
        }

        $gap = isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ? (float) $layout['item_spacing'] : 0.0;
        $intrinsicMainSpan = $childMainSpan + $paddingSpan + max(0, $childCount - 1) * $gap;

        return $intrinsicMainSpan > (float) $box[$dimension] + 1.0 ? 'max-content' : null;
    }

    /**
     * @param array<string, mixed> $box
     */
    private function isFluidRootWidth(array $box, ?array $parentNode): bool
    {
        return null === $parentNode
            && isset($box['width'])
            && is_numeric($box['width'])
            && (float) $box['width'] >= self::FLUID_ROOT_MIN_WIDTH;
    }

    /**
     * @param array<int, string> $styles
     */
    private function stylesDeclareProperty(array $styles, string $property): bool
    {
        foreach ( $styles as $style ) {
            if ( is_string($style) && str_starts_with($style, $property . ':') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function localPositionStyles(array $box): array
    {
        $styles = array();
        if ( isset($box['x']) && is_numeric($box['x']) ) {
            $styles[] = 'left:' . $this->number((float) $box['x']) . 'px';
        }
        if ( isset($box['y']) && is_numeric($box['y']) ) {
            $styles[] = 'top:' . $this->number((float) $box['y']) . 'px';
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFreeformContainer(array $node): bool
    {
        return $this->layoutIntentClassifier()->isFreeformContainer($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function absoluteChildReserveHeight(array $node): ?float
    {
        $children = $this->nodeList($node);
        if ( empty($children) || (! $this->isFreeformContainer($node) && ! $this->hasAbsoluteChild($node) && ! $this->hasDecorativeFlexUnderlayChild($node)) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentHeight = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        $maxBottom = null;
        $contributingChildren = 0;
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( ! $this->isFreeformContainer($node) && 'absolute' !== ($layout['positioning'] ?? null) && ! $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( ! isset($childBox['height']) || ! is_numeric($childBox['height']) ) {
                continue;
            }

            $top = $this->positionOffset($childBox, $box, 'y');
            if ( null === $top ) {
                continue;
            }
            if ( $top < -0.5 ) {
                return null;
            }

            $bottom = $top + (float) $childBox['height'];
            if ( null !== $parentHeight && $bottom > $parentHeight + 0.5 ) {
                return null;
            }
            $maxBottom = null === $maxBottom ? $bottom : max($maxBottom, $bottom);
            $contributingChildren++;
        }

        if ( $contributingChildren <= 1 || null === $maxBottom || $maxBottom <= 0.0 ) {
            return null;
        }
        if ( null !== $parentHeight && abs($parentHeight - $maxBottom) > 0.5 ) {
            return null;
        }

        return $maxBottom;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isNearZeroHeightContainer(array $node, string $type): bool
    {
        if ( ! in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE'), true) || empty($this->nodeList($node)) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box['height']) && is_numeric($box['height']) && 0.5 >= abs((float) $box['height']);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function relativeOffset(array $box, array $parentBox, string $dimension): ?float
    {
        return $this->layoutIntentClassifier()->relativeOffset($box, $parentBox, $dimension);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function positionOffset(array $box, array $parentBox, string $dimension, ?array $parentNode = null): ?float
    {
        return $this->layoutIntentClassifier()->positionOffset($box, $parentBox, $dimension, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasAbsoluteChild(array $node): bool
    {
        return $this->layoutIntentClassifier()->hasAbsoluteChild($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        return $this->layoutIntentClassifier()->hasDecorativeFlexUnderlayChild($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        return $this->layoutIntentClassifier()->isDecorativeFlexUnderlay($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $box
     */
    private function transformStyle(array $box): ?string
    {
        if ( isset($box['transform']) && is_array($box['transform']) ) {
            $matrix = $this->cssMatrix($box['transform']);
            if ( null !== $matrix ) {
                return $matrix;
            }
        }

        if ( isset($box['rotation']) && is_numeric($box['rotation']) ) {
            return 'rotate(' . $this->number((float) $box['rotation']) . 'deg)';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasAbsoluteVisualBounds(array $node): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return 'absolute' === ($box['coordinate_space'] ?? null);
    }

    /**
     * @param array<string, mixed> $box
     */
    private function hasExplicitTransformMatrix(array $box): bool
    {
        return isset($box['transform']) && is_array($box['transform']);
    }

    /**
     * @param array<int, mixed> $transform
     */
    private function cssMatrix(array $transform): ?string
    {
        $values = $this->cssTransformMatrixValues($transform);
        if ( null === $values ) {
            return null;
        }

        return 'matrix(' . implode(',', array_map(fn (mixed $value): string => $this->number((float) $value), $values)) . ')';
    }

    /**
     * @param array<int|string, mixed>|null $transform
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private function cssTransformMatrixValues(?array $transform): ?array
    {
        if ( null === $transform ) {
            return null;
        }

        if ( isset($transform['m00'], $transform['m01'], $transform['m02'], $transform['m10'], $transform['m11'], $transform['m12']) ) {
            if ( 0.00001 > abs((float) $transform['m00'] - 1.0) && 0.00001 > abs((float) $transform['m01']) && 0.00001 > abs((float) $transform['m10']) && 0.00001 > abs((float) $transform['m11'] - 1.0) ) {
                return null;
            }
            $values = array($transform['m00'], $transform['m10'], $transform['m01'], $transform['m11'], 0, 0);
        } elseif ( 2 === count($transform) && is_array($transform[0] ?? null) && is_array($transform[1] ?? null) ) {
            $values = array($transform[0][0] ?? null, $transform[1][0] ?? null, $transform[0][1] ?? null, $transform[1][1] ?? null, $transform[0][2] ?? null, $transform[1][2] ?? null);
        } else {
            return null;
        }

        foreach ( $values as $value ) {
            if ( ! is_numeric($value) ) {
                return null;
            }
        }

        return array_map(static fn (mixed $value): float => (float) $value, $values);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<int, string>
     */
    private function flexItemStyles(array $layout, ?array $parentNode): array
    {
        $styles = array();
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $isFlexChild = in_array((string) ($parentLayout['display'] ?? ''), array('flex', 'inline-flex'), true);

        if ( $this->layoutIntentClassifier()->fillsParentFlexMainAxis($layout, $parentNode) ) {
            $styles[] = 'flex-grow:1';
            $styles[] = 'flex-shrink:1';
        } elseif ( isset($layout['grow']) && is_numeric($layout['grow']) ) {
            $styles[] = 'flex-grow:' . $this->number((float) $layout['grow']);
        } elseif ( $isFlexChild ) {
            $styles[] = 'flex-shrink:0';
        }

        if ( isset($layout['align']) && 'STRETCH' === $layout['align'] ) {
            $styles[] = 'align-self:stretch';
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textContent(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $content = '';
            foreach ( $segments as $segment ) {
                if ( ! is_array($segment) ) {
                    continue;
                }

                $segmentText = (string) ($segment['characters'] ?? '');
                if ( '' === $segmentText ) {
                    continue;
                }

                $content .= $this->segmentRunHtml($segmentText, is_array($segment['style'] ?? null) ? $segment['style'] : null);
            }

            if ( '' !== $content ) {
                return $content;
            }
        }

        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            $characters = (string) $text['characters'];
            if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
                return '';
            }

            return $this->sanitizeText($this->derivedLineBreakText($characters, $text));
        }

        $characters = (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
            return '';
        }

        return $this->sanitizeText($characters);
    }

    /**
     * Renders a single styled text run, wrapping it in a minimal `<span style>`
     * only when the run carries overriding style declarations. Mirrors the inline
     * segment rendering in {@see textContent} so per-character override spans
     * (color/weight/etc.) emit identically whether the text node is a single
     * element or split into per-paragraph boxes.
     *
     * @param array<string, mixed>|null $style
     */
    private function segmentRunHtml(string $characters, ?array $style): string
    {
        if ( '' === $characters ) {
            return '';
        }

        $segmentStyles = is_array($style) ? $this->textStyleDeclarations($style) : array();
        if ( empty($segmentStyles) ) {
            return $this->sanitizeText($characters);
        }

        return '<span style="' . $this->sanitizeAttribute(implode(';', $segmentStyles)) . '">' . $this->sanitizeText($characters) . '</span>';
    }

    /**
     * Splits a text node into per-paragraph buckets of styled runs.
     *
     * Figma encodes a hard paragraph break (the Enter key, the boundary
     * `paragraphSpacing` applies between) as a `\n` in the node's characters.
     * Soft line wraps are not present in the source characters — they are
     * recovered separately as derived baselines — so this split keys only on the
     * real `\n` separators and never treats a wrapped line as a paragraph.
     *
     * Each bucket is an ordered list of `['characters' => string, 'style' =>
     * ?array]` runs. When a styled run straddles a `\n` it is divided at the
     * break and the same style is carried into both paragraphs, so per-character
     * override spans land in the correct paragraph. Leading/trailing empty
     * paragraphs (from a stray boundary `\n`) are dropped; interior blank
     * paragraphs are preserved.
     *
     * @param array<string, mixed> $node
     * @return array<int, array<int, array{characters: string, style: array<string, mixed>|null}>>
     */
    private function paragraphBuckets(array $node): array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();

        $runs = array();
        foreach ( $segments as $segment ) {
            if ( ! is_array($segment) ) {
                continue;
            }
            $segmentText = (string) ($segment['characters'] ?? '');
            if ( '' === $segmentText ) {
                continue;
            }
            $runs[] = array(
                'characters' => $segmentText,
                'style'      => is_array($segment['style'] ?? null) ? $segment['style'] : null,
            );
        }

        if ( empty($runs) ) {
            $characters = isset($text['characters']) && is_scalar($text['characters'])
                ? (string) $text['characters']
                : (string) ($node['characters'] ?? $node['text'] ?? '');
            if ( '' === $characters || $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
                return array();
            }
            $runs[] = array('characters' => $characters, 'style' => null);
        }

        $paragraphs = array(array());
        foreach ( $runs as $run ) {
            $parts = explode("\n", (string) $run['characters']);
            foreach ( $parts as $index => $part ) {
                if ( $index > 0 ) {
                    $paragraphs[] = array();
                }
                if ( '' !== $part ) {
                    $paragraphs[count($paragraphs) - 1][] = array(
                        'characters' => $part,
                        'style'      => $run['style'],
                    );
                }
            }
        }

        // Drop empty paragraphs at the head and tail (a stray boundary newline),
        // while keeping interior blank paragraphs that carry a real blank line.
        while ( ! empty($paragraphs) && empty($paragraphs[0]) ) {
            array_shift($paragraphs);
        }
        while ( ! empty($paragraphs) && empty($paragraphs[count($paragraphs) - 1]) ) {
            array_pop($paragraphs);
        }

        return array_values($paragraphs);
    }

    /**
     * Whether a text node carries real paragraph spacing that can be rendered by
     * splitting it into separate per-paragraph boxes.
     *
     * Requires a positive `paragraphSpacing` and at least two real paragraphs
     * (`\n`-separated). Glyph-rendered text has no paragraph boxes to carry a
     * margin, so it is excluded and reported via {@see paragraphSpacingDiagnostic}.
     *
     * @param array<string, mixed> $node
     */
    private function shouldSplitParagraphs(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( ! isset($style['paragraph_spacing']) || ! is_numeric($style['paragraph_spacing']) || 0.0 >= (float) $style['paragraph_spacing'] ) {
            return false;
        }

        if ( null !== $this->textGlyphSvg($node) ) {
            return false;
        }

        return count($this->paragraphBuckets($node)) >= 2;
    }

    /**
     * Renders a multi-paragraph text node as one block element per paragraph so
     * Figma `paragraphSpacing` lands as a real `margin-bottom` between paragraphs.
     *
     * Each paragraph is a block-level `<span>` (valid inside the node's `<p>` /
     * heading container) and carries the spacing as `margin-bottom` on every
     * paragraph except the last. Inline override spans are preserved within each
     * paragraph via {@see segmentRunHtml}. Returns null when the node is not a
     * splittable multi-paragraph node, so the caller falls back to {@see
     * textContent}.
     *
     * @param array<string, mixed> $node
     */
    private function multiParagraphTextContent(array $node): ?string
    {
        if ( ! $this->shouldSplitParagraphs($node) ) {
            return null;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        $spacing = (float) $style['paragraph_spacing'];

        $paragraphs = $this->paragraphBuckets($node);
        $last = count($paragraphs) - 1;

        $html = '';
        foreach ( $paragraphs as $index => $runs ) {
            $inner = '';
            foreach ( $runs as $run ) {
                $inner .= $this->segmentRunHtml((string) $run['characters'], $run['style']);
            }

            $styles = array('display:block');
            if ( $index < $last ) {
                $styles[] = 'margin-bottom:' . $this->number($spacing) . 'px';
            }

            $html .= '<span style="' . $this->sanitizeAttribute(implode(';', $styles)) . '">' . $inner . '</span>';
        }

        return '' === $html ? null : $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isUnresolvedComponentPlaceholderText(array $node, string $characters): bool
    {
        $placeholder = strtolower(trim($characters));
        if ( ! in_array($placeholder, array('button label'), true) ) {
            return false;
        }

        $id = (string) ($node['id'] ?? '');
        return str_contains($id, '/') || isset($node['figma_component_source_id']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textGlyphSvg(array $node): ?string
    {
        if ( ! $this->renderTextGlyphPaths ) {
            return null;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $glyphPaths = is_array($derivedLayout['glyph_paths'] ?? null) ? $derivedLayout['glyph_paths'] : array();
        if ( empty($glyphPaths) ) {
            return null;
        }

        $label = isset($text['characters']) && is_scalar($text['characters']) ? (string) $text['characters'] : (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( ! $this->textAllowsGlyphRendering($label, $text) ) {
            return null;
        }

        $size = is_array($derivedLayout['size'] ?? null) ? $derivedLayout['size'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($size['width']) && is_numeric($size['width']) ? (float) $size['width'] : ( isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0 );
        $height = isset($size['height']) && is_numeric($size['height']) ? (float) $size['height'] : ( isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0 );
        if ( 0.0 >= $width || 0.0 >= $height ) {
            return null;
        }

        $paths = '';
        $cursors = array();
        foreach ( $glyphPaths as $glyphPath ) {
            if ( ! is_array($glyphPath) ) {
                continue;
            }

            $fontSize = isset($glyphPath['fontSize']) && is_numeric($glyphPath['fontSize']) ? (float) $glyphPath['fontSize'] : $this->textGlyphFallbackFontSize($text);
            $baseline = $this->textGlyphBaseline($glyphPath, $derivedLayout);
            $baselineKey = (string) $baseline['index'];
            if ( ! isset($cursors[$baselineKey]) ) {
                $cursors[$baselineKey] = $baseline['x'];
            }
            $x = isset($glyphPath['position_x']) && is_numeric($glyphPath['position_x']) ? (float) $glyphPath['position_x'] : ( isset($glyphPath['x']) && is_numeric($glyphPath['x']) ? (float) $glyphPath['x'] : (float) $cursors[$baselineKey] );
            $y = isset($glyphPath['position_y']) && is_numeric($glyphPath['position_y']) ? (float) $glyphPath['position_y'] : ( isset($glyphPath['y']) && is_numeric($glyphPath['y']) ? (float) $glyphPath['y'] : $baseline['y'] );
            $transform = 'translate(' . $this->number($x) . ' ' . $this->number($y) . ')';
            if ( 0.0 < $fontSize ) {
                $transform .= ' scale(' . $this->number($fontSize) . ' -' . $this->number($fontSize) . ')';
            }
            if ( isset($glyphPath['advance']) && is_numeric($glyphPath['advance']) ) {
                $cursors[$baselineKey] += (float) $glyphPath['advance'] * ( 0.0 < $fontSize ? $fontSize : 1.0 );
            }
            if ( ! isset($glyphPath['data']) || ! is_scalar($glyphPath['data']) ) {
                continue;
            }
            if ( isset($glyphPath['character']) && is_scalar($glyphPath['character']) && '' !== (string) $glyphPath['character'] && ctype_space((string) $glyphPath['character']) ) {
                continue;
            }

            $attributes = ' d="' . $this->sanitizeAttribute((string) $glyphPath['data']) . '" fill="currentColor" transform="' . $transform . '"';
            $paths .= '<path' . $attributes . '></path>';
        }

        if ( '' === $paths ) {
            return null;
        }

        return '<svg class="figma-text-glyphs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $this->number($width) . ' ' . $this->number($height) . '" width="100%" height="100%" role="img" aria-label="' . $this->sanitizeAttribute($label) . '" data-figma-text-glyphs="true">' . $paths . '</svg>';
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textAllowsGlyphRendering(string $characters, array $text): bool
    {
        if ( $this->textNeedsDomSymbolFallback($characters) ) {
            return false;
        }

        if ( mb_strlen($characters) > 80 ) {
            return false;
        }

        if ( mb_strlen($characters) > 45 && 1 === preg_match('/[.!?。！？]$/u', trim($characters)) ) {
            return false;
        }

        if ( str_contains($characters, "\n") && ! $this->textLooksLikeDisplayText($text) ) {
            return false;
        }

        if ( ! empty($text['segments'] ?? array()) ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textLooksLikeDisplayText(array $text): bool
    {
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( isset($style['font_weight']) && is_numeric($style['font_weight']) && 700 <= (float) $style['font_weight'] ) {
            return true;
        }

        if ( isset($style['font_size']) && is_numeric($style['font_size']) && 30 <= (float) $style['font_size'] ) {
            return true;
        }

        $derivedLineHeight = $this->textDerivedBaselineLineHeight($text);
        return null !== $derivedLineHeight && 36 <= $derivedLineHeight;
    }

    private function textNeedsDomSymbolFallback(string $characters): bool
    {
        return 1 === preg_match('/[✔✖✕✓✗•▪■□☑]/u', $characters);
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textGlyphFallbackFontSize(array $text): float
    {
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        return isset($style['font_size']) && is_numeric($style['font_size']) ? (float) $style['font_size'] : 0.0;
    }

    /**
     * @param array<string, mixed> $glyphPath
     * @param array<string, mixed> $derivedLayout
     * @return array{index: int, x: float, y: float}
     */
    private function textGlyphBaseline(array $glyphPath, array $derivedLayout): array
    {
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        $character = isset($glyphPath['firstCharacter']) && is_numeric($glyphPath['firstCharacter']) ? (float) $glyphPath['firstCharacter'] : null;
        foreach ( $baselines as $index => $baseline ) {
            if ( ! is_array($baseline) ) {
                continue;
            }
            $x = isset($baseline['position_x']) && is_numeric($baseline['position_x']) ? (float) $baseline['position_x'] : 0.0;
            $y = isset($baseline['position_y']) && is_numeric($baseline['position_y']) ? (float) $baseline['position_y'] : ( isset($baseline['lineAscent']) && is_numeric($baseline['lineAscent']) ? (float) $baseline['lineAscent'] : 0.0 );
            if ( null === $character || ! isset($baseline['firstCharacter'], $baseline['endCharacter']) || ! is_numeric($baseline['firstCharacter']) || ! is_numeric($baseline['endCharacter']) ) {
                return array('index' => (int) $index, 'x' => $x, 'y' => $y);
            }
            if ( $character >= (float) $baseline['firstCharacter'] && $character < (float) $baseline['endCharacter'] ) {
                return array('index' => (int) $index, 'x' => $x, 'y' => $y);
            }
        }

        return array('index' => 0, 'x' => 0.0, 'y' => 0.0);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function textStyles(array $node): array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( ! isset($style['color']) ) {
            $paints = is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array();
            $color = $this->firstSolidPaint($paints);
            if ( null !== $color ) {
                $style['css_color'] = $color;
            }
        }

        $styles = $this->textStyleDeclarations($style);
        $derivedLineHeight = $this->textDerivedBaselineLineHeight($text);
        if ( null !== $derivedLineHeight && 0.0 < $derivedLineHeight ) {
            $styles = array_values(array_filter(
                $styles,
                static fn (string $style): bool => ! str_starts_with($style, 'line-height:')
            ));
            $styles[] = 'line-height:' . $this->number($derivedLineHeight) . 'px';
        }
        if ( ( $this->textHasLineBreaks($node) || $this->textHasDerivedLineBreaks($node) ) && ! $this->shouldSplitParagraphs($node) ) {
            $styles[] = 'white-space:pre-line';
        }

        return $styles;
    }

    /**
     * Reports a Figma `paragraphSpacing` value that genuinely cannot be applied.
     *
     * Multi-paragraph text is normally split into per-paragraph boxes that carry
     * the spacing as `margin-bottom` ({@see multiParagraphTextContent}), so no
     * diagnostic is emitted in that case. The value is only surfaced as an `info`
     * diagnostic when the node has multiple real paragraphs but cannot be split —
     * for example glyph-rendered text, which has no paragraph boxes to carry a
     * margin. Single-paragraph nodes (including soft-wrap-only text) are ignored
     * because paragraph spacing has no paragraph boundary to apply between.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function paragraphSpacingDiagnostic(array $node): ?array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( ! isset($style['paragraph_spacing']) || ! is_numeric($style['paragraph_spacing']) || 0.0 >= (float) $style['paragraph_spacing'] ) {
            return null;
        }

        // Spacing is actually applied as per-paragraph margins — nothing to report.
        if ( $this->shouldSplitParagraphs($node) ) {
            return null;
        }

        // Only a node with multiple real paragraphs that could not be split is a
        // genuine "not applied" case. Single-paragraph text has no boundary.
        if ( count($this->paragraphBuckets($node)) < 2 ) {
            return null;
        }

        return array(
            'severity' => 'info',
            'code'     => 'paragraph_spacing_not_applied',
            'message'  => 'Figma paragraphSpacing could not be applied: this multi-paragraph text node cannot be split into per-paragraph boxes (for example glyph-rendered text); the value is reported but not emitted as CSS.',
            'context'  => array(
                'node_id'           => (string) ($node['id'] ?? ''),
                'paragraph_spacing' => (float) $style['paragraph_spacing'],
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textHasLineBreaks(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        foreach ( $segments as $segment ) {
            if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) && str_contains((string) $segment['characters'], "\n") ) {
                return true;
            }
        }

        foreach ( array($text['characters'] ?? null, $node['characters'] ?? null, $node['text'] ?? null) as $value ) {
            if ( is_scalar($value) && str_contains((string) $value, "\n") ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $text
     */
    private function derivedLineBreakText(string $characters, array $text): string
    {
        if ( str_contains($characters, "\n") ) {
            return $characters;
        }

        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        if ( 2 > count($baselines) ) {
            return $characters;
        }

        $chars = preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($chars) || empty($chars) ) {
            return $characters;
        }

        $lines = array();
        foreach ( $baselines as $baseline ) {
            if ( ! is_array($baseline) || ! isset($baseline['firstCharacter'], $baseline['endCharacter']) || ! is_numeric($baseline['firstCharacter']) || ! is_numeric($baseline['endCharacter']) ) {
                return $characters;
            }
            $start = max(0, (int) $baseline['firstCharacter']);
            $end = min(count($chars), (int) $baseline['endCharacter']);
            if ( $end <= $start ) {
                continue;
            }
            $lines[] = implode('', array_slice($chars, $start, $end - $start));
        }

        return empty($lines) ? $characters : implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textHasDerivedLineBreaks(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        return isset($derivedLayout['baseline_count']) && is_numeric($derivedLayout['baseline_count']) && 1 < (int) $derivedLayout['baseline_count'];
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textDerivedBaselineLineHeight(array $text): ?float
    {
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        if ( empty($baselines) ) {
            return null;
        }

        $baselineDeltaLineHeight = $this->textMedianPositiveBaselinePositionDelta($baselines);
        if ( null !== $baselineDeltaLineHeight ) {
            return $baselineDeltaLineHeight;
        }

        $lineHeights = array();
        foreach ( $baselines as $baseline ) {
            if ( is_array($baseline) && isset($baseline['lineHeight']) && is_numeric($baseline['lineHeight']) && 0.0 < (float) $baseline['lineHeight'] ) {
                $lineHeights[] = (float) $baseline['lineHeight'];
            }
        }
        if ( ! empty($lineHeights) ) {
            sort($lineHeights);
            return $lineHeights[(int) floor(( count($lineHeights) - 1 ) / 2)];
        }

        return null;
    }

    /**
     * @param array<int, mixed> $baselines
     */
    private function textMedianPositiveBaselinePositionDelta(array $baselines): ?float
    {
        $positions = array();
        foreach ( $baselines as $baseline ) {
            if ( is_array($baseline) && isset($baseline['position_y']) && is_numeric($baseline['position_y']) ) {
                $positions[] = (float) $baseline['position_y'];
            }
        }
        if ( 2 > count($positions) ) {
            return null;
        }
        sort($positions);

        $deltas = array();
        for ( $i = 1; $i < count($positions); $i++ ) {
            $delta = $positions[$i] - $positions[$i - 1];
            if ( 0.001 < $delta && 10000.0 > $delta ) {
                $deltas[] = $delta;
            }
        }
        if ( empty($deltas) ) {
            return null;
        }

        sort($deltas);
        return $deltas[(int) floor(( count($deltas) - 1 ) / 2)];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textShouldAvoidTinyFixedHeight(array $node, float $height): bool
    {
        if ( 0.0 >= $height ) {
            return false;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( '' === trim($this->nodePlainText($node)) || $this->textHasLineBreaks($node) || $this->textHasDerivedLineBreaks($node) ) {
            return false;
        }

        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? array_values(array_filter($derivedLayout['baselines'], 'is_array')) : array();
        if ( 1 !== count($baselines) ) {
            return false;
        }

        $baseline = $baselines[0];
        if ( ! isset($baseline['lineHeight'], $baseline['lineY']) || ! is_numeric($baseline['lineHeight']) || ! is_numeric($baseline['lineY']) ) {
            return false;
        }

        $lineHeight = (float) $baseline['lineHeight'];
        $lineY = (float) $baseline['lineY'];

        return 0.0 > $lineY && $lineHeight > $height + 0.5;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function textShouldUseMeasuredFlexHeight(array $node, ?array $parentNode): bool
    {
        if ( null === $parentNode || 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( 'flex' !== ($parentLayout['display'] ?? null) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box['height']) && is_numeric($box['height']) && $this->textShouldAvoidTinyFixedHeight($node, (float) $box['height']);
    }

    /**
     * @param array<string, mixed> $style
     * @return array<int, string>
     */
    private function textStyleDeclarations(array $style): array
    {
        $styles = array();

        if ( isset($style['font_family']) && is_scalar($style['font_family']) ) {
            $styles[] = 'font-family:' . $this->fontResolver()->fallbackStack((string) $style['font_family']);
        }

        if ( isset($style['font_size']) && is_numeric($style['font_size']) ) {
            $styles[] = 'font-size:' . $this->number((float) $style['font_size']) . 'px';
        }

        if ( isset($style['font_weight']) && is_numeric($style['font_weight']) ) {
            $styles[] = 'font-weight:' . $this->number((float) $style['font_weight']);
        }

        if ( is_array($style['font_variation_settings'] ?? null) ) {
            $settings = array();
            foreach ( $style['font_variation_settings'] as $axis => $value ) {
                if ( is_string($axis) && 1 === preg_match('/^[A-Za-z0-9 ]{4}$/', $axis) && is_numeric($value) ) {
                    $settings[] = '"' . $axis . '" ' . $this->number((float) $value);
                }
            }
            if ( ! empty($settings) ) {
                $styles[] = 'font-variation-settings:' . implode(',', $settings);
            }
        }

        if ( is_array($style['font_feature_settings'] ?? null) ) {
            $settings = array();
            foreach ( $style['font_feature_settings'] as $feature => $enabled ) {
                if ( is_string($feature) && 1 === preg_match('/^[A-Za-z0-9 ]{4}$/', $feature) && is_numeric($enabled) ) {
                    $settings[] = '"' . $feature . '" ' . ((int) $enabled);
                }
            }
            if ( ! empty($settings) ) {
                $styles[] = 'font-feature-settings:' . implode(',', $settings);
            }
        }

        if ( isset($style['line_height_px']) && is_numeric($style['line_height_px']) && 0.0 < (float) $style['line_height_px'] ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_px']) . 'px';
        } elseif ( isset($style['line_height_raw']) && is_numeric($style['line_height_raw']) && 0.0 < (float) $style['line_height_raw'] ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_raw']);
        } elseif ( isset($style['line_height_percent']) && is_numeric($style['line_height_percent']) && 0.0 < (float) $style['line_height_percent'] ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_percent']) . '%';
        }

        if ( isset($style['letter_spacing']) && is_numeric($style['letter_spacing']) ) {
            $styles[] = 'letter-spacing:' . $this->number((float) $style['letter_spacing']) . 'px';
        } elseif ( isset($style['letter_spacing_em']) && is_numeric($style['letter_spacing_em']) ) {
            $styles[] = 'letter-spacing:' . $this->number((float) $style['letter_spacing_em']) . 'em';
        }

        // Figma `paragraphIndent` → CSS first-line indent. A zero indent is the
        // default, so it is left implicit.
        if ( isset($style['paragraph_indent']) && is_numeric($style['paragraph_indent']) && 0.0 !== (float) $style['paragraph_indent'] ) {
            $styles[] = 'text-indent:' . $this->number((float) $style['paragraph_indent']) . 'px';
        }

        $color = $this->color($style['color'] ?? null);
        if ( null !== $color ) {
            $styles[] = 'color:' . $color;
        } elseif ( isset($style['css_color']) && is_scalar($style['css_color']) ) {
            $styles[] = 'color:' . (string) $style['css_color'];
        }

        if ( isset($style['text_align_horizontal']) && is_scalar($style['text_align_horizontal']) ) {
            $align = strtolower((string) $style['text_align_horizontal']);
            $align = 'justified' === $align ? 'justify' : $align;
            if ( in_array($align, array('left', 'center', 'right', 'justify'), true) ) {
                $styles[] = 'text-align:' . $align;
            }
        }

        if ( isset($style['text_align_vertical']) && is_scalar($style['text_align_vertical']) ) {
            $align = strtolower((string) $style['text_align_vertical']);
            if ( in_array($align, array('top', 'middle', 'bottom'), true) ) {
                $styles[] = 'vertical-align:' . $align;
            }
        }

        $decorations = array();
        if ( isset($style['text_decoration']) && is_scalar($style['text_decoration']) ) {
            $decoration = strtolower((string) $style['text_decoration']);
            if ( in_array($decoration, array('underline', 'line-through'), true) ) {
                $decorations[] = $decoration;
            }
        }
        if ( true === ($style['underline'] ?? false) ) {
            $decorations[] = 'underline';
        }
        if ( true === ($style['strikethrough'] ?? false) ) {
            $decorations[] = 'line-through';
        }
        if ( ! empty($decorations) ) {
            $styles[] = 'text-decoration:' . implode(' ', array_values(array_unique($decorations)));
        }

        if ( isset($style['text_transform']) && is_scalar($style['text_transform']) ) {
            $transform = strtolower((string) $style['text_transform']);
            if ( in_array($transform, array('uppercase', 'lowercase', 'capitalize'), true) ) {
                $styles[] = 'text-transform:' . $transform;
            }
        }

        if ( isset($style['font_variant']) && is_scalar($style['font_variant']) ) {
            $variant = strtolower((string) $style['font_variant']);
            if ( 'small-caps' === $variant ) {
                $styles[] = 'font-variant:' . $variant;
            }
        }

        if ( isset($style['max_lines']) && is_numeric($style['max_lines']) && 0 < (int) $style['max_lines'] ) {
            $styles[] = '-webkit-line-clamp:' . ((int) $style['max_lines']);
            $styles[] = 'display:-webkit-box';
            $styles[] = '-webkit-box-orient:vertical';
            $styles[] = 'overflow:hidden';
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function radiusStyles(array $box): array
    {
        return $this->styleDeclarationBuilder()->radiusStyles($box);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function strokeStyles(array $node): array
    {
        return $this->styleDeclarationBuilder()->strokeStyles($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function rendersStrokeInsideInlineSvg(array $node, string $type, ?array $parentNode): bool
    {
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return false;
        }

        if ( empty($this->strokeStyles($node)) ) {
            return false;
        }

        return null !== $this->supportedVectorSvg($node, $type, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function effectStyles(array $node, string $type): array
    {
        return $this->styleDeclarationBuilder()->effectStyles($node, $type);
    }

    /**
     * @param mixed $assets
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssets(mixed $assets, array &$diagnostics): array
    {
        $this->assetsById = array();
        if ( ! is_array($assets) ) {
            return array();
        }

        $files = array();
        foreach ( $assets as $key => $asset ) {
            if ( ! is_array($asset) ) {
                continue;
            }

            $id = (string) ($asset['id'] ?? $key);
            $content = $asset['content'] ?? $asset['data'] ?? null;
            $source = (string) ($asset['url'] ?? $asset['src'] ?? '');
            $mimeType = (string) ($asset['mime_type'] ?? $asset['mimeType'] ?? 'application/octet-stream');
            $decodedAsset = $this->decodeInlineAssetContent($asset, $content, $mimeType);
            $content = $decodedAsset['content'];
            $mimeType = $decodedAsset['mime_type'];

            if ( null === $content ) {
                if ( preg_match('/^https?:\/\//', $source) ) {
                    $diagnostics[] = array(
                        'severity' => 'warning',
                        'code'     => 'external_asset_omitted',
                        'message'  => 'External asset URL omitted from static output.',
                        'asset_id' => $id,
                    );
                }
                continue;
            }

            $path = 'assets/' . $this->slug((string) ($asset['name'] ?? $id)) . '.' . $this->extensionForMimeType($mimeType);
            $file = array(
                'path'      => $path,
                'role'      => 'asset',
                'mime_type' => $mimeType,
                'content'   => (string) $content,
                'source_id' => $id,
            );

            $files[] = $file;
            foreach ( $this->assetAliases($asset, $id) as $alias ) {
                $this->assetsById[$alias] = $file;
            }
        }

        usort(
            $files,
            static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path'])
        );

        return $files;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array{content: mixed, mime_type: string}
     */
    private function decodeInlineAssetContent(array $asset, mixed $content, string $mimeType): array
    {
        if ( null !== $content ) {
            return array('content' => $content, 'mime_type' => $mimeType);
        }

        foreach ( array('dataUrl', 'dataURL', 'data_url') as $key ) {
            if ( ! isset($asset[$key]) || ! is_scalar($asset[$key]) ) {
                continue;
            }

            $dataUrl = (string) $asset[$key];
            if ( 1 !== preg_match('/^data:([^;,]+)?(;base64)?,(.*)$/s', $dataUrl, $matches) ) {
                continue;
            }

            $data = rawurldecode($matches[3]);
            if ( ';base64' === ($matches[2] ?? '') ) {
                $decoded = base64_decode($data, true);
                if ( false === $decoded ) {
                    continue;
                }
                $data = $decoded;
            }

            $dataUrlMimeType = (string) ($matches[1] ?? '');
            return array(
                'content'   => $data,
                'mime_type' => '' !== $dataUrlMimeType ? $dataUrlMimeType : $mimeType,
            );
        }

        foreach ( array('content_base64', 'contentBase64', 'base64') as $key ) {
            if ( ! isset($asset[$key]) || ! is_scalar($asset[$key]) ) {
                continue;
            }

            $decoded = base64_decode((string) $asset[$key], true);
            if ( false !== $decoded ) {
                return array('content' => $decoded, 'mime_type' => $mimeType);
            }
        }

        return array('content' => null, 'mime_type' => $mimeType);
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<int, array<string, mixed>>
     */
    private function assetReport(array $assetFiles): array
    {
        $assets = array();
        foreach ( $assetFiles as $file ) {
            $content = (string) ($file['content'] ?? '');
            $asset = array(
                'id'        => (string) ($file['source_id'] ?? ''),
                'path'      => (string) $file['path'],
                'mime_type' => (string) $file['mime_type'],
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            );
            if ( 'image/svg+xml' === ($file['mime_type'] ?? null) && str_starts_with((string) ($file['source_id'] ?? ''), 'generated-vector-') ) {
                $asset += $this->svgAssetMetrics($content);
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @return array<string, mixed>
     */
    private function svgAssetMetrics(string $content): array
    {
        $pathElementCount = preg_match_all('/<path\b[^>]*>/i', $content, $pathMatches);
        $pathDataValues = array();
        foreach ( $pathMatches[0] ?? array() as $pathElement ) {
            if ( preg_match('/\bd\s*=\s*(["\'])(.*?)\1/is', (string) $pathElement, $pathDataMatch) ) {
                $pathDataValues[] = html_entity_decode((string) $pathDataMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pathDataBytes = array_map(static fn (string $pathData): int => strlen($pathData), $pathDataValues);
        $pathDataHashes = array_map(static fn (string $pathData): string => hash('sha256', $pathData), $pathDataValues);
        $uniquePathDataHashes = array_values(array_unique($pathDataHashes));

        return array(
            'gzip_bytes' => function_exists('gzencode') ? strlen((string) gzencode($content, 9)) : null,
            'path_element_count' => false === $pathElementCount ? 0 : $pathElementCount,
            'path_data_count' => count($pathDataValues),
            'path_data_bytes' => array_sum($pathDataBytes),
            'largest_path_data_bytes' => empty($pathDataBytes) ? 0 : max($pathDataBytes),
            'unique_path_data_count' => count($uniquePathDataHashes),
            'duplicate_path_data_count' => max(0, count($pathDataValues) - count($uniquePathDataHashes)),
            'path_data_hashes' => $uniquePathDataHashes,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeAssetPath(array $node): ?string
    {
        foreach ( $this->nodeAssetReferences($node) as $assetId ) {
            if ( isset($this->assetsById[$assetId]) ) {
                $path = (string) $this->assetsById[$assetId]['path'];
                $this->usedAssetPaths[$path] = true;
                return $path;
            }
        }

        return null;
    }

    /**
     * Return all image-fill asset paths for a node ordered top→bottom (Figma's
     * topmost paint first), matching CSS background-image layer stacking order.
     * Figma stores fills bottom→top in the array, so fills are reversed before
     * resolution. Paints with `visible === false` are skipped. Every resolved
     * path is marked used so its blob is emitted.
     *
     * When a node carries no fill-based image paints the method falls back to
     * the legacy node-level reference (same as {@see nodeAssetPath()}) so that
     * simple `asset_id` nodes continue to work unchanged.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function nodeAssetPaths(array $node): array
    {
        $paths = array();

        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            $paintCollections = array();
            if ( is_array($node[$paintKey] ?? null) ) {
                $paintCollections[] = $node[$paintKey];
            }
            if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                $paintCollections[] = $node['figma_paints'][$paintKey];
            }

            foreach ( $paintCollections as $paints ) {
                // Figma stores fills bottom→top; reverse so topmost is first
                // (CSS background-image: first url = topmost layer).
                $orderedPaints = array_reverse(array_values($paints));
                foreach ( $orderedPaints as $paint ) {
                    if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
                        continue;
                    }
                    // Honour Figma visibility flag.
                    if ( false === ($paint['visible'] ?? true) ) {
                        continue;
                    }

                    foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
                        if ( ! isset($paint[$key]) || ! is_scalar($paint[$key]) || '' === (string) $paint[$key] ) {
                            continue;
                        }
                        $assetId = (string) $paint[$key];
                        if ( isset($this->assetsById[$assetId]) ) {
                            $p = (string) $this->assetsById[$assetId]['path'];
                            $this->usedAssetPaths[$p] = true;
                            $paths[] = $p;
                            break;
                        }
                        $slugged = $this->slug($assetId);
                        if ( isset($this->assetsById[$slugged]) ) {
                            $p = (string) $this->assetsById[$slugged]['path'];
                            $this->usedAssetPaths[$p] = true;
                            $paths[] = $p;
                            break;
                        }
                    }
                }
            }
        }

        if ( ! empty($paths) ) {
            return array_values(array_unique($paths));
        }

        // Fallback: node-level asset reference (e.g. explicit `asset_id` key
        // not expressed as a fill paint).
        $fallbackPath = $this->nodeAssetPath($node);
        return null !== $fallbackPath ? array($fallbackPath) : array();
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<int, array<string, mixed>>
     */
    private function referencedAssetFiles(array $assetFiles): array
    {
        if ( empty($this->usedAssetPaths) ) {
            return array();
        }

        return array_values(array_filter(
            $assetFiles,
            fn (array $file): bool => isset($this->usedAssetPaths[(string) ($file['path'] ?? '')])
        ));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function imageBackgroundStyles(array $node): array
    {
        $scaleMode = $this->nodeImageScaleMode($node);
        $transformStyles = $this->imagePaintTransformStyles($node, $scaleMode);
        if ( ! empty($transformStyles) ) {
            return $transformStyles;
        }

        if ( 'STRETCH' === $scaleMode ) {
            return array('background-size:100% 100%', 'background-repeat:no-repeat', 'background-position:center');
        }

        if ( 'TILE' === $scaleMode ) {
            return array('background-repeat:repeat', 'background-position:center');
        }

        return array('background-size:cover', 'background-position:center');
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function imageBackgroundBlendModes(array $node): array
    {
        $blendModes = array();
        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            $paintCollections = array();
            if ( is_array($node[$paintKey] ?? null) ) {
                $paintCollections[] = $node[$paintKey];
            }
            if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                $paintCollections[] = $node['figma_paints'][$paintKey];
            }

            foreach ( $paintCollections as $paints ) {
                foreach ( array_reverse(array_values($paints)) as $paint ) {
                    if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) || false === ($paint['visible'] ?? true) ) {
                        continue;
                    }

                    $blendMode = null;
                    if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                        $blendMode = $this->blendModeCss((string) $paint['blendMode']);
                    }

                    $blendModes[] = $blendMode ?? 'normal';
                }
            }
        }

        return in_array(true, array_map(static fn (string $mode): bool => 'normal' !== $mode, $blendModes), true) ? $blendModes : array();
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function imagePaintTransformStyles(array $node, string $scaleMode): array
    {
        if ( 'STRETCH' !== $scaleMode ) {
            return array();
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : (is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array());
        $width = $box['width'] ?? $node['width'] ?? null;
        $height = $box['height'] ?? $node['height'] ?? null;
        if ( ! is_numeric($width) || ! is_numeric($height) || 0 >= (float) $width || 0 >= (float) $height ) {
            return array();
        }

        foreach ( $this->nodeImagePaints($node) as $paint ) {
            $matrix = $this->imagePaintTransformMatrix($paint);
            if ( null === $matrix || $this->isIdentityImageTransform($matrix) ) {
                continue;
            }

            if ( 0.00001 < abs($matrix['m01']) || 0.00001 < abs($matrix['m10']) || 0 >= $matrix['m00'] || 0 >= $matrix['m11'] ) {
                continue;
            }

            $backgroundWidth = (float) $width / $matrix['m00'];
            $backgroundHeight = (float) $height / $matrix['m11'];
            $backgroundX = -1 * $matrix['m02'] * $backgroundWidth;
            $backgroundY = -1 * $matrix['m12'] * $backgroundHeight;

            return array(
                'background-size:' . $this->number($backgroundWidth) . 'px ' . $this->number($backgroundHeight) . 'px',
                'background-repeat:no-repeat',
                'background-position:' . $this->number($backgroundX) . 'px ' . $this->number($backgroundY) . 'px',
            );
        }

        return array();
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{m00: float, m01: float, m02: float, m10: float, m11: float, m12: float}|null
     */
    private function imagePaintTransformMatrix(array $paint): ?array
    {
        $transform = $paint['transform'] ?? null;
        if ( ! is_array($transform) ) {
            return null;
        }

        if ( isset($transform['m00'], $transform['m01'], $transform['m02'], $transform['m10'], $transform['m11'], $transform['m12']) ) {
            $values = array(
                'm00' => $transform['m00'],
                'm01' => $transform['m01'],
                'm02' => $transform['m02'],
                'm10' => $transform['m10'],
                'm11' => $transform['m11'],
                'm12' => $transform['m12'],
            );
        } elseif ( is_array($transform[0] ?? null) && is_array($transform[1] ?? null) ) {
            $values = array(
                'm00' => $transform[0][0] ?? null,
                'm01' => $transform[0][1] ?? null,
                'm02' => $transform[0][2] ?? null,
                'm10' => $transform[1][0] ?? null,
                'm11' => $transform[1][1] ?? null,
                'm12' => $transform[1][2] ?? null,
            );
        } else {
            return null;
        }

        foreach ( $values as $value ) {
            if ( ! is_numeric($value) ) {
                return null;
            }
        }

        return array_map(static fn (mixed $value): float => (float) $value, $values);
    }

    /**
     * @param array{m00: float, m01: float, m02: float, m10: float, m11: float, m12: float} $matrix
     */
    private function isIdentityImageTransform(array $matrix): bool
    {
        return 0.00001 > abs($matrix['m00'] - 1.0)
            && 0.00001 > abs($matrix['m01'])
            && 0.00001 > abs($matrix['m02'])
            && 0.00001 > abs($matrix['m10'])
            && 0.00001 > abs($matrix['m11'] - 1.0)
            && 0.00001 > abs($matrix['m12']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeImageScaleMode(array $node): string
    {
        foreach ( $this->nodeImagePaints($node) as $paint ) {
            foreach ( array('imageScaleMode', 'scaleMode') as $key ) {
                if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                    return strtoupper((string) $paint[$key]);
                }
            }
        }

        return 'FILL';
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function nodeImagePaints(array $node): array
    {
        $imagePaints = array();
        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            $paintCollections = array();
            if ( is_array($node[$paintKey] ?? null) ) {
                $paintCollections[] = $node[$paintKey];
            }
            if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                $paintCollections[] = $node['figma_paints'][$paintKey];
            }

            foreach ( $paintCollections as $paints ) {
                foreach ( $paints as $paint ) {
                    if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                        $imagePaints[] = $paint;
                    }
                }
            }
        }

        return $imagePaints;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<int, string>
     */
    private function assetAliases(array $asset, string $id): array
    {
        $aliases = array($id);
        foreach ( array('hash', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref', 'source_id', 'node_id', 'nodeId', 'name', 'fileName', 'filename') as $key ) {
            if ( isset($asset[$key]) && is_scalar($asset[$key]) ) {
                $aliases[] = (string) $asset[$key];
            }
        }

        foreach ( $aliases as $alias ) {
            $aliases[] = $this->slug($alias);
        }

        if ( isset($asset['path']) && is_scalar($asset['path']) ) {
            $path = (string) $asset['path'];
            $aliases[] = $path;
            $aliases[] = basename($path);
            $aliases[] = pathinfo($path, PATHINFO_FILENAME);
        }

        return array_values(array_unique(array_filter($aliases, static fn (string $alias): bool => '' !== $alias)));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function nodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref', 'id', 'name') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $references[] = (string) $node[$key];
            }
        }

        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            $paintCollections = array();
            if ( is_array($node[$paintKey] ?? null) ) {
                $paintCollections[] = $node[$paintKey];
            }
            if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                $paintCollections[] = $node['figma_paints'][$paintKey];
            }

            foreach ( $paintCollections as $paints ) {
                foreach ( $paints as $paint ) {
                    if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
                        continue;
                    }

                    foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
                        if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                            $references[] = (string) $paint[$key];
                        }
                    }
                }
            }
        }

        foreach ( $references as $reference ) {
            $references[] = $this->slug($reference);
        }

        return array_values(array_unique($references));
    }

    private function isUnsupportedVectorType(string $type): bool
    {
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function supportedVectorSvg(array $node, string $type, ?array $parentNode = null): ?string
    {
        return $this->vectorSvgRenderer()->supportedVectorSvg($node, $type, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function vectorSvgMarkup(string $svg, array $node, string $type): string
    {
        $hash = hash('sha256', $svg);
        if ( strlen($svg) <= self::EXTERNAL_VECTOR_SVG_BYTES && ! isset($this->generatedVectorSvgPathsByHash[$hash]) ) {
            return $svg;
        }

        $path = $this->generatedVectorSvgPathsByHash[$hash] ?? null;
        if ( null === $path ) {
            $path = 'assets/vector-' . substr($hash, 0, 16) . '.svg';
            $this->generatedVectorSvgPathsByHash[$hash] = $path;
            $this->generatedAssetFiles[$path] = array(
                'path'      => $path,
                'role'      => 'asset',
                'mime_type' => 'image/svg+xml',
                'content'   => $svg,
                'source_id' => 'generated-vector-' . substr($hash, 0, 16),
            );
        }

        $label = (string) ($node['name'] ?? $type);
        return '<img class="figma-vector-asset" src="' . $this->sanitizeAttribute($path) . '" alt="' . $this->sanitizeAttribute($label) . '" data-figma-vector="true">';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function zeroHeightVectorFallbackHeight(array $node, string $type): ?float
    {
        return $this->vectorSvgRenderer()->zeroHeightVectorFallbackHeight($node, $type);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function vectorPlaceholderDiagnostic(array $node, string $type, ?array $parentNode = null): array
    {
        return $this->vectorSvgRenderer()->vectorPlaceholderDiagnostic($node, $type, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function backgroundColor(array $node): ?string
    {
        $paints = is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array();
        $paint = $this->firstBackgroundPaint($paints);
        if ( null !== $paint ) {
            return $paint;
        }

        $paints = is_array($node['figma_paints']['background'] ?? null) ? $node['figma_paints']['background'] : array();
        $paint = $this->firstBackgroundPaint($paints);
        if ( null !== $paint ) {
            return $paint;
        }

        return $this->color($node['background'] ?? $node['backgroundColor'] ?? $node['fill'] ?? $node['fills'][0]['color'] ?? null);
    }

    /**
     * @param array<int, mixed> $paints
     */
    private function firstBackgroundPaint(array $paints): ?string
    {
        $paint = $this->firstCssPaint($paints);
        return is_array($paint) ? $paint['css'] : null;
    }

    /**
     * @param array<int, mixed> $paints
     * @return array{css: string, gradient: bool}|null
     */
    private function firstCssPaint(array $paints): ?array
    {
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) ) {
                continue;
            }

            if ( 'SOLID' === ($paint['type'] ?? null) ) {
                $color = $this->color($paint['color'] ?? null, $paint['opacity'] ?? null);
                if ( null !== $color ) {
                    return array('css' => $color, 'gradient' => false);
                }
            }

            if ( in_array(($paint['type'] ?? null), array('GRADIENT_LINEAR', 'GRADIENT_RADIAL', 'GRADIENT_ANGULAR'), true) ) {
                $gradient = $this->gradientPaint($paint);
                if ( null !== $gradient ) {
                    return array('css' => $gradient, 'gradient' => true);
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $paints
     */
    private function firstSolidPaint(array $paints): ?string
    {
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) || 'SOLID' !== ($paint['type'] ?? null) ) {
                continue;
            }

            $color = $this->color($paint['color'] ?? null, $paint['opacity'] ?? null);
            if ( null !== $color ) {
                return $color;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $paint
     */
    private function gradientPaint(array $paint): ?string
    {
        $stops = is_array($paint['stops'] ?? null) ? $paint['stops'] : array();
        if ( empty($stops) ) {
            return null;
        }

        $cssStops = array();
        foreach ( $stops as $stop ) {
            if ( ! is_array($stop) || ! isset($stop['position']) || ! is_numeric($stop['position']) ) {
                continue;
            }

            $opacity = $paint['opacity'] ?? null;
            $color = $stop['color'] ?? null;
            if ( is_numeric($opacity) && is_array($color) && isset($color['a']) && is_numeric($color['a']) ) {
                $opacity = (float) $opacity * (float) $color['a'];
            }

            $cssColor = $this->color($color, $opacity);
            if ( null === $cssColor ) {
                continue;
            }

            $cssStops[] = $cssColor . ' ' . $this->number((float) $stop['position'] * 100) . '%';
        }

        if ( empty($cssStops) ) {
            return null;
        }

        if ( 'GRADIENT_RADIAL' === ($paint['type'] ?? null) ) {
            // Radial center/radius are encoded in the gradientTransform too, but
            // recovering them faithfully is more involved; emit a centered circle
            // as the supported baseline.
            return 'radial-gradient(circle,' . implode(',', $cssStops) . ')';
        }

        if ( 'GRADIENT_ANGULAR' === ($paint['type'] ?? null) ) {
            $geometry = $this->angularGradientGeometry($paint);

            return 'conic-gradient(from ' . $this->number($geometry['from']) . 'deg at '
                . $this->number($geometry['cx']) . '% ' . $this->number($geometry['cy']) . '%,'
                . implode(',', $cssStops) . ')';
        }

        return 'linear-gradient(' . $this->number($this->linearGradientAngle($paint)) . 'deg,' . implode(',', $cssStops) . ')';
    }

    /**
     * Computes the CSS conic-gradient geometry (start angle + center) for a
     * Figma angular paint from its gradientTransform matrix.
     *
     * Figma evaluates an angular (conic) gradient in the same canonical space
     * the linear/radial paths use: the 2x3 gradientTransform maps the shape's
     * normalized bounding-box space (0..1, y-down) into the gradient's canonical
     * space, and the angular parameter is t = atan2(v - 0.5, u - 0.5) / 2pi
     * around the canonical center (0.5, 0.5). So t = 0 (the gradient's first
     * stop / seam) points along the canonical +u axis -- the very same handle
     * direction the linear path treats as start->end. Mapping +u back through
     * the INVERSE matrix yields (d/det, -c/det), the t=0 radial direction in the
     * shape's own y-down space.
     *
     * CSS conic-gradient `from` angles share the linear-gradient clock: 0deg
     * points up, 90deg right, 180deg down, 270deg left, sweeping clockwise. For
     * a y-down direction (dx, dy) the matching angle is atan2(dx, -dy), so the
     * seam direction reuses the exact linearGradientAngle convention. The center
     * is the canonical point (0.5, 0.5) mapped back through the inverse affine,
     * expressed as percentages of the shape box.
     *
     * Returns `from 0deg at 50% 50%` (seam at top, centered) when no usable
     * transform is present, so geometry-less angular paints stay deterministic.
     *
     * @param array<string, mixed> $paint
     * @return array{from: float, cx: float, cy: float}
     */
    private function angularGradientGeometry(array $paint): array
    {
        $default = array('from' => 0.0, 'cx' => 50.0, 'cy' => 50.0);

        $matrix = $paint['gradientTransform'] ?? null;
        if ( ! is_array($matrix) || ! is_array($matrix[0] ?? null) || ! is_array($matrix[1] ?? null) ) {
            return $default;
        }

        $a = $this->numericOrNull($matrix[0][0] ?? null);
        $b = $this->numericOrNull($matrix[0][1] ?? null);
        $tx = $this->numericOrNull($matrix[0][2] ?? null);
        $c = $this->numericOrNull($matrix[1][0] ?? null);
        $d = $this->numericOrNull($matrix[1][1] ?? null);
        $ty = $this->numericOrNull($matrix[1][2] ?? null);
        if ( null === $a || null === $b || null === $tx || null === $c || null === $d || null === $ty ) {
            return $default;
        }

        $det = $a * $d - $b * $c;
        if ( abs($det) < 1e-9 ) {
            return $default;
        }

        // Canonical +u axis mapped to the shape's y-down space: the t=0 seam
        // direction. Identical first column of the inverse linear part the
        // linear path uses, so the seam angle matches linearGradientAngle.
        $dx = $d / $det;
        $dy = -$c / $det;
        $from = 0.0;
        if ( abs($dx) >= 1e-9 || abs($dy) >= 1e-9 ) {
            $from = fmod(rad2deg(atan2($dx, -$dy)), 360.0);
            if ( $from < 0.0 ) {
                $from += 360.0;
            }
        }

        // Canonical center (0.5, 0.5) mapped back through the inverse affine
        // gives the conic center in the shape's normalized space.
        $cx = ($d * (0.5 - $tx) - $b * (0.5 - $ty)) / $det;
        $cy = ($a * (0.5 - $ty) - $c * (0.5 - $tx)) / $det;

        return array(
            'from' => $from,
            'cx'   => $cx * 100.0,
            'cy'   => $cy * 100.0,
        );
    }

    /**
     * Computes the CSS linear-gradient angle (degrees) for a Figma linear paint
     * from its gradientTransform matrix.
     *
     * Figma encodes gradient direction with a 2x3 affine matrix that maps the
     * shape's normalized bounding-box space (0..1, y-down) into the gradient's
     * canonical parameter space, where the gradient runs along x from 0 (start)
     * to 1 (end) at any y. To recover the start->end direction in the shape's own
     * space we map the canonical points (0, 0.5) and (1, 0.5) back through the
     * INVERSE matrix. Their difference equals the first column of the inverse
     * linear part, (d/det, -c/det), where the linear part is [[a, b], [c, d]] and
     * det = a*d - b*c.
     *
     * CSS linear-gradient angles run clockwise from "to top": 0deg points up,
     * 90deg right, 180deg down, 270deg left. For a direction vector (dx, dy) in
     * y-down space the matching angle is atan2(dx, -dy), normalized to [0, 360).
     * A left-to-right vector (1, 0) yields 90deg; a top-to-bottom vector (0, 1)
     * yields 180deg.
     *
     * Returns 180.0 (top-to-bottom) when no usable transform is present, so paints
     * without geometry keep the historical default.
     *
     * @param array<string, mixed> $paint
     */
    private function linearGradientAngle(array $paint): float
    {
        $matrix = $paint['gradientTransform'] ?? null;
        if ( ! is_array($matrix) || ! is_array($matrix[0] ?? null) || ! is_array($matrix[1] ?? null) ) {
            return 180.0;
        }

        $a = $this->numericOrNull($matrix[0][0] ?? null);
        $b = $this->numericOrNull($matrix[0][1] ?? null);
        $c = $this->numericOrNull($matrix[1][0] ?? null);
        $d = $this->numericOrNull($matrix[1][1] ?? null);
        if ( null === $a || null === $b || null === $c || null === $d ) {
            return 180.0;
        }

        $det = $a * $d - $b * $c;
        if ( abs($det) < 1e-9 ) {
            return 180.0;
        }

        // First column of the inverse linear part: the start->end direction in
        // the shape's normalized (y-down) coordinate space.
        $dx = $d / $det;
        $dy = -$c / $det;
        if ( abs($dx) < 1e-9 && abs($dy) < 1e-9 ) {
            return 180.0;
        }

        $angle = fmod(rad2deg(atan2($dx, -$dy)), 360.0);
        if ( $angle < 0.0 ) {
            $angle += 360.0;
        }

        return $angle;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Map a Figma node-level blendMode enum to the equivalent CSS
     * `mix-blend-mode` keyword. Returns null for the default compositing
     * modes (NORMAL / PASS_THROUGH) and any unrecognized value so no CSS
     * is emitted in those cases.
     */
    private function blendModeCss(string $blendMode): ?string
    {
        return match ( strtoupper($blendMode) ) {
            'MULTIPLY' => 'multiply',
            'SCREEN' => 'screen',
            'OVERLAY' => 'overlay',
            'DARKEN' => 'darken',
            'LIGHTEN' => 'lighten',
            'COLOR_DODGE' => 'color-dodge',
            'COLOR_BURN' => 'color-burn',
            'HARD_LIGHT' => 'hard-light',
            'SOFT_LIGHT' => 'soft-light',
            'DIFFERENCE' => 'difference',
            'EXCLUSION' => 'exclusion',
            'HUE' => 'hue',
            'SATURATION' => 'saturation',
            'COLOR' => 'color',
            'LUMINOSITY' => 'luminosity',
            default => null,
        };
    }

    private function color(mixed $value, mixed $opacity = null): ?string
    {
        if ( is_string($value) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) ) {
            return strtolower($value);
        }

        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->colorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->colorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->colorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $alpha = $opacity;
        if ( null === $alpha && isset($value['a']) ) {
            $alpha = $value['a'];
        }

        if ( is_numeric($alpha) && (float) $alpha < 1 ) {
            return sprintf('rgba(%d,%d,%d,%s)', $red, $green, $blue, $this->number(max(0, (float) $alpha)));
        }

        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }

    private function colorChannel(mixed $value): ?int
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel <= 1 ) {
            $channel *= 255;
        }

        return max(0, min(255, (int) round($channel)));
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ( $mimeType ) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/svg+xml' => 'svg',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, mixed>
     */
    private function nodeList(array $container): array
    {
        if ( is_array($container['nodes'] ?? null) ) {
            return array_values($container['nodes']);
        }

        if ( is_array($container['children'] ?? null) ) {
            return array_values($container['children']);
        }

        return array();
    }

    /**
     * @param array<string, mixed> $scenegraph
     * @return array<string, array<string, mixed>>
     */
    private function nodeMap(array $scenegraph): array
    {
        $map = array();
        if ( is_array($scenegraph['node_map'] ?? null) ) {
            foreach ( $scenegraph['node_map'] as $id => $node ) {
                if ( is_array($node) ) {
                    $nodeId = (string) ($node['id'] ?? $id);
                    if ( '' !== $nodeId ) {
                        $map[$nodeId] = $node;
                    }
                }
            }
        }

        foreach ( $this->nodeList($scenegraph) as $node ) {
            if ( is_array($node) ) {
                $this->appendNodeMap($node, $map);
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $map
     */
    private function appendNodeMap(array $node, array &$map): void
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' !== $id ) {
            $map[$id] = $node;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->appendNodeMap($child, $map);
            }
        }
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @return array<int, mixed>
     */
    private function plannedPages(array $pagePlan): array
    {
        if ( is_array($pagePlan['pages'] ?? null) ) {
            return array_values($pagePlan['pages']);
        }

        return array_values($pagePlan);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function pagePath(array $page, string $name, int $index): string
    {
        if ( isset($page['path']) && is_scalar($page['path']) && '' !== trim((string) $page['path']) ) {
            $path = trim(str_replace('\\', '/', (string) $page['path']));
            $path = ltrim($path, '/');
            $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => '' !== $part && '.' !== $part && '..' !== $part));
            $path = implode('/', $parts);
            if ( '' !== $path && str_ends_with($path, '/') ) {
                $path .= 'index.html';
            }
            if ( '' !== $path ) {
                return str_contains(basename($path), '.') ? $path : rtrim($path, '/') . '/index.html';
            }
        }

        if ( true === ($page['entrypoint'] ?? false) || 0 === $index ) {
            return 'index.html';
        }

        return $this->slug($name) . '.html';
    }

    private function stylesheetHref(string $pagePath): string
    {
        $directory = trim(dirname($pagePath), '.');
        if ( '' === $directory || '/' === $directory ) {
            return 'style.css';
        }

        $depth = count(array_filter(explode('/', trim($directory, '/')), static fn (string $part): bool => '' !== $part));
        return str_repeat('../', $depth) . 'style.css';
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private function countNodes(array $nodes): int
    {
        $count = 0;
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            ++$count;
            $count += $this->countNodes($this->nodeList($node));
        }

        return $count;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');

        return '' === $slug ? 'node' : $slug;
    }

    /**
     * Derive a readable, authored-looking base class name from a node's name,
     * falling back to its role/type when the node is unnamed. Never embeds raw
     * Figma node ids, so shared classes read like `.hero-section` or `.card`.
     */
    private function sharedClassBaseName(string $name, string $type): string
    {
        $base = $this->slug($name);
        if ( 'node' === $base || '' === $base ) {
            $base = $this->slug($type);
            if ( 'node' === $base || '' === $base ) {
                $base = 'style';
            }
        }

        return $base;
    }

    /**
     * Collapse repeated per-node style rules into shared, readably-named CSS
     * classes — the way a hand-authored stylesheet reuses `.card` or `.button`.
     *
     * Per-node rules (`.figma-node-*{...}`) whose declaration body is identical
     * across two or more nodes are replaced by a single shared rule named after
     * the first such node (deterministically, in stylesheet order). The original
     * `figma-node-*` hooks remain on the elements for diagnostics; the shared
     * class is appended so computed styles are byte-for-byte identical.
     *
     * @param array<int, string> $cssRules
     * @return array{rules: array<int, string>, class_map: array<string, string>}
     */
    private function applySharedStyleClasses(array $cssRules, bool $hashReadableNames = false): array
    {
        $pattern = '/^\.(figma-node-[A-Za-z0-9_-]+)\{(.*)\}$/s';

        // Group per-node rules by declaration body, preserving first-seen order.
        $bodyToSelectors = array();
        $bodyFirstIndex  = array();
        foreach ( $cssRules as $index => $rule ) {
            if ( 1 === preg_match($pattern, $rule, $matches) ) {
                $body = $matches[2];
                $bodyToSelectors[$body][] = $matches[1];
                if ( ! isset($bodyFirstIndex[$body]) ) {
                    $bodyFirstIndex[$body] = $index;
                }
            }
        }

        // Mint a deterministic shared class name for every body used 2+ times.
        $sharedOrder = array();
        foreach ( $bodyToSelectors as $body => $selectors ) {
            if ( count($selectors) >= 2 ) {
                $sharedOrder[$body] = $bodyFirstIndex[$body];
            }
        }
        asort($sharedOrder);

        $reserved = array(
            'figma-root'         => true,
            'figma-link'         => true,
            'figma-text-glyphs'  => true,
            'figma-vector-asset' => true,
        );
        $usedNames        = array();
        $bodyToSharedClass = array();
        foreach ( array_keys($sharedOrder) as $body ) {
            $firstSelector = $bodyToSelectors[$body][0];
            $base = $this->nodeReadableNames[$firstSelector] ?? 'style';
            if ( $hashReadableNames ) {
                $base .= '-' . substr(sha1($body), 0, 8);
            }
            $name = $base;
            $suffix = 2;
            while ( isset($usedNames[$name]) || isset($reserved[$name]) ) {
                $name = $base . '-' . $suffix;
                ++$suffix;
            }
            $usedNames[$name]        = true;
            $bodyToSharedClass[$body] = $name;
        }

        // Rewrite the stylesheet: emit each shared rule once (in place of the
        // first per-node rule that produced it) and drop the duplicates.
        $rules        = array();
        $emittedShared = array();
        $classMap     = array();
        foreach ( $cssRules as $rule ) {
            if ( 1 === preg_match($pattern, $rule, $matches) ) {
                $selector = $matches[1];
                $body     = $matches[2];
                if ( isset($bodyToSharedClass[$body]) ) {
                    $shared            = $bodyToSharedClass[$body];
                    $classMap[$selector] = $shared;
                    if ( ! isset($emittedShared[$shared]) ) {
                        $rules[]              = '.' . $shared . '{' . $body . '}';
                        $emittedShared[$shared] = true;
                    }
                    continue;
                }
            }
            $rules[] = $rule;
        }

        return array('rules' => $rules, 'class_map' => $classMap);
    }

    /**
     * Append shared class names to the `figma-node-*` hooks already present in
     * an emitted HTML fragment.
     *
     * @param array<string, string> $classMap
     */
    private function applySharedClassMapToHtml(string $html, array $classMap): string
    {
        foreach ( $classMap as $selector => $shared ) {
            $html = str_replace(
                'class="' . $selector . '"',
                'class="' . $selector . ' ' . $shared . '"',
                $html
            );
        }

        return $html;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }

    private function cssString(string $value): string
    {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"';
    }

    private function sanitizeText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizeAttribute(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

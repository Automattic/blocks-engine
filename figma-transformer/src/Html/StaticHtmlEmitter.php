<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Emits static HTML artifacts from a normalized scenegraph.
 */
final class StaticHtmlEmitter
{
    private const MAX_RAW_SVG_PATH_DATA_BYTES = 20000;
    private const MAX_DECODED_FIGMA_SVG_PATH_DATA_BYTES = 4194304;
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
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $nodes = $this->nodeList($scenegraph);
        $diagnostics = array();
        $nodeStyleDiagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);

        $body = '';
        $cssRules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            '.figma-root{position:relative;min-width:100%;width:max-content}',
            'p,h1,h2,h3,h4,h5,h6{margin:0}',
            'img{display:block;max-width:100%;height:auto}',
            '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}',
        );
        if ( $this->renderTextGlyphPaths ) {
            $cssRules[] = '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}';
        }
        $fontCss = $this->fontCss($options);

        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $body .= $this->emitNode($node, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
        }

        $assetFiles = array_merge($this->referencedAssetFiles($assetFiles), array_values($this->generatedAssetFiles));

        $css = ('' !== $fontCss ? $fontCss . "\n" : '') . implode("\n", $cssRules) . "\n";
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

        $files = $this->withInlineCssFiles($files, $css);

        $visualNodeMap = $this->visualNodeMap($nodes);
        $fontFamilies = $this->fontFamilies($nodeStyleDiagnostics);
        $fontUsage = $this->fontUsage($nodeStyleDiagnostics);
        $transformDiagnostics = $this->transformDiagnostics($nodes, $assetFiles, $fontFamilies, $fontUsage, $fontCss, $css, $diagnostics);
        if ( '' === $fontCss ) {
            foreach ( $fontFamilies as $fontFamily ) {
                if ( $this->isWebSafeFontFamily($fontFamily) ) {
                    continue;
                }
                $diagnostics[] = array(
                    'severity' => 'info',
                    'code' => 'font_css_missing_for_source_font',
                    'message' => 'Source font family was emitted without supplied font CSS; browser font fallback may reduce visual parity.',
                    'context' => array('font_family' => $fontFamily),
                );
            }
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
                'font_css_supplied'            => '' !== $fontCss,
                'render_text_glyph_paths'      => $this->renderTextGlyphPaths,
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
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $diagnostics = array();
        $nodeStyleDiagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);
        $nodeMap = $this->nodeMap($scenegraph);

        $cssRules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            '.figma-root{position:relative;min-width:100%;width:max-content}',
            'p,h1,h2,h3,h4,h5,h6{margin:0}',
            'img{display:block;max-width:100%;height:auto}',
            '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}',
        );
        if ( $this->renderTextGlyphPaths ) {
            $cssRules[] = '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}';
        }
        $fontCss = $this->fontCss($options);
        $files = array();
        $pages = array();
        $renderedNodes = array();
        $seenPaths = array();
        $plannedPages = $this->plannedPages($pagePlan);

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

            $body = $this->emitNode($frameNode, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
            $files[] = array(
                'path'      => $path,
                'role'      => true === ($page['entrypoint'] ?? false) ? 'entrypoint' : 'document',
                'mime_type' => 'text/html',
                'content'   => $this->htmlDocument($this->sanitizeText($pageName), $this->stylesheetHref($path), $body),
            );
            $renderedNodes[] = $frameNode;
            $pages[] = array(
                'frame_id'   => $frameId,
                'name'       => $pageName,
                'path'       => $path,
                'entrypoint' => true === ($page['entrypoint'] ?? false),
                'node_count' => $this->countNodes(array($frameNode)),
            );
        }

        if ( empty($files) ) {
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
        $css = ('' !== $fontCss ? $fontCss . "\n" : '') . implode("\n", array_values(array_unique($cssRules))) . "\n";
        $files[] = array(
            'path'      => 'style.css',
            'role'      => 'stylesheet',
            'mime_type' => 'text/css',
            'content'   => $css,
        );

        foreach ( $assetFiles as $assetFile ) {
            $files[] = $assetFile;
        }

        $files = $this->withInlineCssFiles($files, $css);

        $visualNodeMap = $this->visualNodeMap($renderedNodes);
        $fontFamilies = $this->fontFamilies($nodeStyleDiagnostics);
        $fontUsage = $this->fontUsage($nodeStyleDiagnostics);
        $transformDiagnostics = $this->transformDiagnostics($renderedNodes, $assetFiles, $fontFamilies, $fontUsage, $fontCss, $css, $diagnostics);
        if ( '' === $fontCss ) {
            foreach ( $fontFamilies as $fontFamily ) {
                if ( $this->isWebSafeFontFamily($fontFamily) ) {
                    continue;
                }
                $diagnostics[] = array(
                    'severity' => 'info',
                    'code' => 'font_css_missing_for_source_font',
                    'message' => 'Source font family was emitted without supplied font CSS; browser font fallback may reduce visual parity.',
                    'context' => array('font_family' => $fontFamily),
                );
            }
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
                'font_css_supplied'            => '' !== $fontCss,
                'render_text_glyph_paths'      => $this->renderTextGlyphPaths,
                'transform_diagnostics'        => $transformDiagnostics,
            ),
            'metrics'       => array(
                'node_count'  => $this->countNodes($renderedNodes),
                'asset_count' => count($assetFiles),
                'page_count'  => count($pages),
            ),
        );
    }

    private function htmlDocument(string $title, string $stylesheetHref, string $body): string
    {
        return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . $title . "</title>\n<link rel=\"stylesheet\" href=\"" . $this->sanitizeAttribute($stylesheetHref) . "\">\n</head>\n<body>\n<main class=\"figma-root\" data-figma-root=\"true\">\n" . $body . "</main>\n</body>\n</html>\n";
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function withInlineCssFiles(array $files, string $css): array
    {
        if ( '' === $css ) {
            return $files;
        }

        foreach ( $files as $index => $file ) {
            if ( ! is_array($file) || 'text/html' !== ($file['mime_type'] ?? null) || ! isset($file['content']) || ! is_scalar($file['content']) ) {
                continue;
            }

            $file['content'] = $this->withInlineCss((string) $file['content'], $css);
            $files[$index] = $file;
        }

        return $files;
    }

    private function withInlineCss(string $html, string $css): string
    {
        if ( '' === $css || str_contains($html, '<style data-figma-transformer-css="true">') ) {
            return $html;
        }

        $style = '<style data-figma-transformer-css="true">' . str_replace('</style', '<\/style', $css) . '</style>';
        if ( str_contains($html, '</head>') ) {
            return str_replace('</head>', $style . "\n</head>", $html);
        }

        return $style . "\n" . $html;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>                 $cssRules
     * @param array<int, array<string, mixed>>   $diagnostics
     */
    private function emitNode(array $node, array &$cssRules, array &$diagnostics, array &$nodeStyleDiagnostics, int $depth, ?array $parentNode): string
    {
        $id = $this->sanitizeAttribute((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $attributeName = $this->sanitizeAttribute($name);
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $text = 'TEXT' === $type ? ( $this->textGlyphSvg($node) ?? $this->textContent($node) ) : $this->textContent($node);
        $tag = $this->tagName($type, $name, $depth);
        $className = 'figma-node-' . $this->slug($id . '-' . $name);
        $children = $this->nodeList($node);
        $content = $text;
        $vectorSvg = $this->supportedVectorSvg($node, $type, $parentNode);
        $assetPath = $this->nodeAssetPath($node);
        $hasVectorAssetFallback = $this->isUnsupportedVectorType($type) && null !== $assetPath;

        if ( ! ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) ) {
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
                'node_id'  => (string) ($node['id'] ?? ''),
                'type'     => $type,
            );

            $content = '';
        }

        $styles = $this->styleDeclarations($node, $type, $parentNode);
        if ( ! empty($styles) ) {
            $cssRules[] = '.' . $className . '{' . implode(';', $styles) . '}';
        }
        $nodeStyleDiagnostics[] = $this->nodeStyleDiagnostic($node, $type, $className, $tag, $styles, $parentNode);

        $attributes = sprintf(' class="%1$s" data-figma-node-id="%2$s" data-figma-node-name="%3$s"', $className, $id, $attributeName);
        if ( 'RECTANGLE' === $type && '' === $content ) {
            $attributes .= ' aria-hidden="true"';
        }
        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg && ! $hasVectorAssetFallback && ! $hasRenderableVectorFallback ) {
            $attributes .= ' data-figma-unsupported-vector="true" aria-hidden="true"';
        } elseif ( $hasVectorAssetFallback ) {
            $attributes .= ' role="img" aria-label="' . $this->sanitizeAttribute('' !== $name ? $name : $type) . '"';
        }

        return sprintf("<%1\$s%2\$s>%3\$s</%1\$s>\n", $tag, $attributes, $content);
    }

    private function tagName(string $type, string $name, int $depth): string
    {
        $lowerName = strtolower($name);

        if ( 'TEXT' === $type ) {
            if ( str_contains($lowerName, 'title') || str_contains($lowerName, 'heading') || str_contains($lowerName, 'headline') ) {
                return 0 === $depth ? 'h1' : 'h2';
            }

            return 'p';
        }

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

        if ( 'FRAME' === $type ) {
            return 'section';
        }

        return 'div';
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
            if ( is_scalar($family) && '' !== (string) $family ) {
                $families[] = trim((string) $family, '"');
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

            $family = trim((string) $expected['font_family'], " \t\n\r\0\x0B\"");
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

    private function isWebSafeFontFamily(string $family): bool
    {
        return in_array(strtolower($family), array('arial', 'georgia', 'helvetica', 'serif', 'sans-serif', 'times new roman', 'verdana'), true);
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
     * @param array<int, array<string, mixed>> $assetFiles
     * @param array<int, string> $fontFamilies
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function transformDiagnostics(array $nodes, array $assetFiles, array $fontFamilies, array $fontUsage, string $fontCss, string $css, array $diagnostics): array
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
            'nodes'                    => 0,
            'rendered_paths'           => 0,
            'rendered_asset_fallbacks' => 0,
            'placeholders'             => 0,
            'placeholder_nodes'        => array(),
        );
        $layout = array(
            'large_negative_left_count' => preg_match_all('/left:-[0-9]{3,}/', $css),
            'fixed_root_width_count'    => 0,
            'fixed_root_width_nodes'    => array(),
            'large_absolute_offset_count' => 0,
            'large_absolute_offset_nodes' => array(),
            'decorative_underlays'      => array(
                'count' => 0,
                'nodes' => array(),
            ),
            'image_heavy_landmark_candidates' => array(),
            'layout_mismatch_count' => 0,
            'layout_mismatch_status' => 'not_evaluated',
        );

        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->collectTransformDiagnostics($node, $image, $vectors, $layout);
            }
        }

        $image['missing_assets'] = array_values($image['missing_assets']);
        $image['image_block_nodes'] = array_values($image['image_block_nodes']);
        $vectors['placeholder_nodes'] = array_values($vectors['placeholder_nodes']);
        $layout['decorative_underlays']['nodes'] = array_values($layout['decorative_underlays']['nodes']);
        $layout['decorative_underlays']['count'] = count($layout['decorative_underlays']['nodes']);
        $layout['fixed_root_width_nodes'] = array_values($layout['fixed_root_width_nodes']);
        $layout['large_absolute_offset_nodes'] = array_values($layout['large_absolute_offset_nodes']);
        $layout['image_heavy_landmark_candidates'] = array_values($layout['image_heavy_landmark_candidates']);
        $generatedSvgAssets = $this->generatedSvgAssetDiagnostics($assetFiles);
        $assets = array(
            'emitted_files' => count($assetFiles),
            'paths'         => array_values(array_map(static fn (array $file): string => (string) ($file['path'] ?? ''), $assetFiles)),
        );
        $fonts = array(
            'families'      => $fontFamilies,
            'usage'         => $fontUsage,
            'count'         => count($fontFamilies),
            'css_supplied'  => '' !== $fontCss,
            'materialized'  => '' !== $fontCss,
            'missing_css'   => '' === $fontCss ? array_values(array_filter($fontFamilies, fn (string $family): bool => ! $this->isWebSafeFontFamily($family))) : array(),
        );

        return array(
            'schema' => 'blocks-engine/figma-transformer/transform-diagnostics/v1',
            'selection' => $this->selectionDiagnostics($nodes),
            'images' => $image,
            'vectors' => $vectors,
            'fonts' => $fonts,
            'assets' => $assets,
            'generated_svg_assets' => $generatedSvgAssets,
            'layout' => $layout,
            'artifact_quality' => $this->artifactQualityDiagnostics($image, $vectors, $fonts, $assets, $generatedSvgAssets, $layout),
            'diagnostic_codes' => $this->diagnosticCodeCounts($diagnostics),
        );
    }

    /**
     * @param array<string, mixed> $image
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $fonts
     * @param array<string, mixed> $assets
     * @param array<string, mixed> $generatedSvgAssets
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function artifactQualityDiagnostics(array $image, array $vectors, array $fonts, array $assets, array $generatedSvgAssets, array $layout): array
    {
        $signals = array();

        if ( ! empty($image['missing_assets']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'missing_render_assets',
                'count' => count($image['missing_assets']),
            );
        }
        if ( ! empty($vectors['placeholders']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'vector_placeholders',
                'count' => (int) $vectors['placeholders'],
            );
        }
        if ( ! empty($fonts['missing_css']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'font_css_missing',
                'count' => count($fonts['missing_css']),
                'font_usage' => $this->fontUsageForFamilies(is_array($fonts['usage'] ?? null) ? $fonts['usage'] : array(), $fonts['missing_css']),
            );
        }
        if ( ! empty($layout['large_negative_left_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'off_canvas_left_css',
                'count' => (int) $layout['large_negative_left_count'],
            );
        }
        if ( ! empty($layout['fixed_root_width_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'fixed_root_width',
                'count' => (int) $layout['fixed_root_width_count'],
                'sample_nodes' => array_slice(is_array($layout['fixed_root_width_nodes'] ?? null) ? $layout['fixed_root_width_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['large_absolute_offset_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'large_absolute_offsets',
                'count' => (int) $layout['large_absolute_offset_count'],
                'sample_nodes' => array_slice(is_array($layout['large_absolute_offset_nodes'] ?? null) ? $layout['large_absolute_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['image_heavy_landmark_candidates']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'image_heavy_landmark_candidate',
                'count' => count($layout['image_heavy_landmark_candidates']),
            );
        }
        $imageBlockCount = (int) ($image['image_block_count'] ?? 0);
        $totalNodeCount = max(0, (int) ($image['total_node_count'] ?? 0));
        $imageNodeDensity = $totalNodeCount > 0 ? $imageBlockCount / $totalNodeCount : 0.0;
        if ( $imageBlockCount >= 12 && ($imageNodeDensity >= 0.35 || ! empty($layout['image_heavy_landmark_candidates'])) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'excessive_image_blocks',
                'count' => $imageBlockCount,
                'threshold' => 12,
                'image_node_density' => round($imageNodeDensity, 3),
                'sample_nodes' => array_slice(is_array($image['image_block_nodes'] ?? null) ? $image['image_block_nodes'] : array(), 0, 10),
            );
        }
        if ( (int) ($vectors['rendered_asset_fallbacks'] ?? 0) >= 8 ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'excessive_vector_image_fallbacks',
                'count' => (int) $vectors['rendered_asset_fallbacks'],
            );
        }
        if ( (int) ($generatedSvgAssets['bytes'] ?? 0) > 1048576 ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'large_generated_svg_assets',
                'count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
            );
        }

        $failCodes = array('missing_render_assets', 'vector_placeholders');
        $failCount = count(array_filter($signals, static fn (array $signal): bool => in_array((string) ($signal['code'] ?? ''), $failCodes, true)));
        $warningCount = count(array_filter($signals, static fn (array $signal): bool => 'warning' === ($signal['severity'] ?? null)));
        $qualityStatus = $failCount > 0 ? 'fail' : (empty($signals) ? 'pass' : 'warn');

        return array(
            'schema' => 'blocks-engine/figma-transformer/artifact-quality/v1',
            'status' => $warningCount > 0 ? 'needs_review' : (empty($signals) ? 'clean' : 'info'),
            'quality_status' => $qualityStatus,
            'signals' => $signals,
            'summary' => array(
                'missing_asset_nodes' => count($image['missing_assets'] ?? array()),
                'vector_placeholders' => (int) ($vectors['placeholders'] ?? 0),
                'missing_font_css' => count($fonts['missing_css'] ?? array()),
                'emitted_asset_files' => (int) ($assets['emitted_files'] ?? 0),
                'image_block_count' => $imageBlockCount,
                'image_node_density' => round($imageNodeDensity, 3),
                'total_node_count' => $totalNodeCount,
                'vector_image_fallbacks' => (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
                'generated_svg_count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'generated_svg_bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
                'large_negative_left_count' => (int) ($layout['large_negative_left_count'] ?? 0),
                'fixed_root_width_count' => (int) ($layout['fixed_root_width_count'] ?? 0),
                'large_absolute_offset_count' => (int) ($layout['large_absolute_offset_count'] ?? 0),
                'image_heavy_landmark_candidates' => count($layout['image_heavy_landmark_candidates'] ?? array()),
                'layout_mismatch_count' => (int) ($layout['layout_mismatch_count'] ?? 0),
                'layout_mismatch_status' => (string) ($layout['layout_mismatch_status'] ?? 'not_evaluated'),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fontUsage
     * @param array<int, string> $families
     * @return array<int, array<string, mixed>>
     */
    private function fontUsageForFamilies(array $fontUsage, array $families): array
    {
        $wanted = array_fill_keys(array_map('strtolower', $families), true);
        return array_values(array_filter(
            $fontUsage,
            static fn (array $usage): bool => isset($wanted[strtolower((string) ($usage['family'] ?? ''))])
        ));
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
     */
    private function collectTransformDiagnostics(array $node, array &$image, array &$vectors, array &$layout, ?array $parentNode = null): void
    {
        ++$image['total_node_count'];

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $nodeLayout = is_array($node['layout'] ?? null) ? $node['layout'] : array();

        if ( null === $parentNode && isset($box['width']) && is_numeric($box['width']) && (float) $box['width'] >= 1024.0 && 'FILL' !== strtoupper((string) ($nodeLayout['sizing_horizontal'] ?? '')) ) {
            ++$layout['fixed_root_width_count'];
            $layout['fixed_root_width_nodes'][] = array(
                'node_id' => (string) ($node['id'] ?? ''),
                'name'    => (string) ($node['name'] ?? ''),
                'type'    => strtoupper((string) ($node['type'] ?? '')),
                'width'   => $this->reportNumericValue($box['width'] ?? null),
            );
        }

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
        if ( $this->isUnsupportedVectorType($type) ) {
            ++$vectors['nodes'];
            if ( null !== $this->supportedVectorSvg($node, $type, $parentNode) ) {
                ++$vectors['rendered_paths'];
            } elseif ( null !== $this->nodeAssetPath($node) ) {
                ++$vectors['rendered_asset_fallbacks'];
            } else {
                ++$vectors['placeholders'];
                $vectors['placeholder_nodes'][] = array(
                    'node_id' => (string) ($node['id'] ?? ''),
                    'name'    => (string) ($node['name'] ?? ''),
                    'type'    => $type,
                );
            }
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                if ( $this->isFullyClippedDecorativeChild($child, $node) ) {
                    continue;
                }
                $this->collectTransformDiagnostics($child, $image, $vectors, $layout, $node);
            }
        }
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
            'parent_id' => (string) ($parentNode['id'] ?? ''),
            'left' => null === $left ? null : $this->reportNumericValue($left),
            'top' => null === $top ? null : $this->reportNumericValue($top),
            'parent_width' => null === $parentWidth ? null : $this->reportNumericValue($parentWidth),
            'parent_height' => null === $parentHeight ? null : $this->reportNumericValue($parentHeight),
        );
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
        return ! $this->treeHasText($node) && ! $this->treeHasImageReference($node) && $this->treeIsVectorShapeOnly($node);
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
            if ( 'HUG' === $sizing ) {
                if ( 'flex' === ($layout['display'] ?? null) && isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
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
                $styles[] = $property . ':' . $this->number($value) . 'px';
            }
        }

        if ( true === ($layout['clips_content'] ?? false) ) {
            $styles[] = 'overflow:hidden';
        }

        $isDecorativeFlexUnderlay = null !== $parentNode && $this->isDecorativeFlexUnderlay($node, $parentNode);
        $willPositionAbsolute = (null !== $parentNode && $this->isFreeformContainer($parentNode)) || 'absolute' === ($layout['positioning'] ?? null) || $isDecorativeFlexUnderlay;
        if ( ! $willPositionAbsolute && ($this->hasAbsoluteChild($node) || $this->hasDecorativeFlexUnderlayChild($node) || $this->isFreeformContainer($node)) ) {
            $styles[] = 'position:relative';
        }

		if ( null !== $parentNode && $this->isFreeformContainer($parentNode) ) {
			$styles[] = 'position:absolute';
			foreach ( $this->absolutePositionStyles($box, $layout, $parentNode) as $style ) {
				$styles[] = $style;
			}
        } elseif ( $isDecorativeFlexUnderlay ) {
            $styles[] = 'position:absolute';
            foreach ( $this->absolutePositionStyles($box, $layout, $parentNode) as $style ) {
                $styles[] = $style;
            }
            $styles[] = 'z-index:0';
            $styles[] = 'pointer-events:none';
		} elseif ( 'absolute' === ($layout['positioning'] ?? null) ) {
            $styles[] = 'position:absolute';
            foreach ( $this->absolutePositionStyles($box, $layout, $parentNode) as $style ) {
                $styles[] = $style;
            }
        }

        if ( null !== $parentNode && ! $willPositionAbsolute && $this->hasDecorativeFlexUnderlayChild($parentNode) ) {
            $styles[] = 'position:relative';
            $styles[] = 'z-index:1';
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

        foreach ( $this->strokeStyles($node) as $style ) {
            $styles[] = $style;
        }

        $assetPath = $this->nodeAssetPath($node);
        if ( null !== $assetPath ) {
            $styles[] = 'background-image:url("' . $assetPath . '")';
            foreach ( $this->imageBackgroundStyles($node) as $style ) {
                $styles[] = $style;
            }
        }

        if ( 'TEXT' === $type ) {
            foreach ( $this->textStyles($node) as $style ) {
                $styles[] = $style;
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

        if ( isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ) {
            $styles[] = 'gap:' . $this->number((float) $layout['item_spacing']) . 'px';
        }

        if ( ! $isDecorativeFlexUnderlay ) {
            foreach ( $this->flexItemStyles($layout, $parentNode) as $style ) {
                $styles[] = $style;
            }
        }

        return array_values(array_unique($styles));
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
     * @param array<string, mixed> $layout
     * @return array<int, string>
     */
    private function absolutePositionStyles(array $box, array $layout, ?array $parentNode): array
    {
        $styles = array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $left = $this->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->positionOffset($box, $parentBox, 'y', $parentNode);
        $constraints = is_array($layout['constraints'] ?? null) ? $layout['constraints'] : array();

        if ( null !== $left ) {
            $styles[] = 'left:' . $this->number($left) . 'px';
        }
        if ( isset($constraints['horizontal'], $parentBox['width'], $box['width']) && 'LEFT_RIGHT' === $constraints['horizontal'] && null !== $left ) {
            $styles[] = 'right:' . $this->number((float) $parentBox['width'] - $left - (float) $box['width']) . 'px';
        }
        if ( null !== $top ) {
            $styles[] = 'top:' . $this->number($top) . 'px';
        }
        if ( isset($constraints['vertical'], $parentBox['height'], $box['height']) && 'TOP_BOTTOM' === $constraints['vertical'] && null !== $top ) {
            $styles[] = 'bottom:' . $this->number((float) $parentBox['height'] - $top - (float) $box['height']) . 'px';
        }

        return $styles;
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
        if ( true === ($node['layout']['freeform'] ?? false) ) {
            return true;
        }

        $children = $this->nodeList($node);
        if ( true === ($node['figma_component']['resolved'] ?? false) && ! empty($children) && empty($node['layout']['display'] ?? null) ) {
            return true;
        }

        if ( empty($node['layout']['display'] ?? null) && $this->hasPositionedSourceChild($node, $children) ) {
            return true;
        }

        if ( 1 !== count($children) || ! is_array($children[0]) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $childBox = is_array($children[0]['box'] ?? null) ? $children[0]['box'] : array();
        if ( ! isset($box['width'], $box['height'], $childBox['width'], $childBox['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) || ! is_numeric($childBox['width']) || ! is_numeric($childBox['height']) ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( ! empty($layout['display'] ?? null) ) {
            if ( 'flex' !== ($layout['display'] ?? null) ) {
                return false;
            }
            $mainAxis = 'row' === ($layout['flex_direction'] ?? null) ? 'width' : 'height';
            return (float) $childBox[$mainAxis] > (float) $box[$mainAxis];
        }

        return (float) $childBox['width'] > (float) $box['width'] || (float) $childBox['height'] > (float) $box['height'];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed> $children
     */
    private function hasPositionedSourceChild(array $node, array $children): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            $left = $this->positionOffset($childBox, $box, 'x', $node);
            $top = $this->positionOffset($childBox, $box, 'y', $node);
            if ( (null !== $left && abs($left) > 0.5) || (null !== $top && abs($top) > 0.5) ) {
                return true;
            }
        }

        return false;
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
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        $offset = (float) $box[$dimension];
        if ( isset($parentBox[$dimension]) && is_numeric($parentBox[$dimension]) ) {
            $offset -= (float) $parentBox[$dimension];
        }

        return $offset;
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function positionOffset(array $box, array $parentBox, string $dimension, ?array $parentNode = null): ?float
    {
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        if ( 'local' === ($box['coordinate_space'] ?? null) ) {
            return (float) $box[$dimension];
        }

        if ( null !== $parentNode && (! isset($parentBox[$dimension]) ? $this->shouldInferMissingParentOrigin($parentBox, $parentNode, $dimension) : $this->shouldInferRootCanvasOrigin($parentBox, $parentNode, $dimension)) ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
            if ( null !== $origin ) {
                return (float) $box[$dimension] - $origin;
            }
        }

        return $this->relativeOffset($box, $parentBox, $dimension);
    }

    /**
     * Figma plugin payloads can preserve root children in source canvas coordinates
     * while giving the selected root/page a normalized or unrelated absolute origin.
     *
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function shouldInferRootCanvasOrigin(array $parentBox, array $parentNode, string $dimension): bool
    {
        if ( ! isset($parentBox[$dimension]) || ! is_numeric($parentBox[$dimension]) ) {
            return false;
        }

        if ( ! empty($parentNode['_parent_id']) ) {
            return false;
        }

        $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
        if ( null === $origin ) {
            return false;
        }

        $parentOrigin = (float) $parentBox[$dimension];
        if ( 0.0 === $parentOrigin ) {
            return $origin < 0.0 || $this->hasRootCanvasOriginMismatch($parentBox, $parentNode);
        }

        return ($origin < 0.0 && ($parentOrigin - $origin) >= 100.0)
            || $this->hasRootCanvasOriginMismatch($parentBox, $parentNode);
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function shouldInferMissingParentOrigin(array $parentBox, array $parentNode, string $dimension): bool
    {
        $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
        if ( null === $origin ) {
            return false;
        }

        foreach ( array('x' => 'width', 'y' => 'height') as $originDimension => $sizeKey ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $originDimension);
            if ( null === $origin ) {
                continue;
            }

            $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
            if ( abs($origin) >= 1000.0 || (null !== $parentSize && $origin > $parentSize + 100.0) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Some decoded roots are normalized to 0 while direct children remain in Figma canvas coordinates.
     *
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function hasRootCanvasOriginMismatch(array $parentBox, array $parentNode): bool
    {
        foreach ( array('x', 'y') as $dimension ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
            if ( null === $origin || ! isset($parentBox[$dimension]) || ! is_numeric($parentBox[$dimension]) ) {
                continue;
            }

            $parentOrigin = (float) $parentBox[$dimension];
            $sizeKey = 'x' === $dimension ? 'width' : 'height';
            $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
            if ( abs($origin - $parentOrigin) >= 1000.0 || (null !== $parentSize && $origin > $parentOrigin + $parentSize + 100.0) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Infer a root origin for selected frames that carry only size while their children remain in canvas coordinates.
     *
     * @param array<string, mixed> $parentNode
     */
    private function inferredContainingBlockOrigin(array $parentNode, string $dimension): ?float
    {
        $preferredOrigin = null;
        $fallbackOrigin = null;
        foreach ( $this->nodeList($parentNode) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( 'local' === ($childBox['coordinate_space'] ?? null) || ! isset($childBox[$dimension]) || ! is_numeric($childBox[$dimension]) ) {
                continue;
            }

            $value = (float) $childBox[$dimension];
            $fallbackOrigin = null === $fallbackOrigin ? $value : min($fallbackOrigin, $value);
            if ( $this->isContainingBlockOriginCandidate($child) ) {
                $preferredOrigin = null === $preferredOrigin ? $value : min($preferredOrigin, $value);
            }
        }

        return $preferredOrigin ?? $fallbackOrigin;
    }

    /**
     * Prefer content-bearing children for canvas-origin inference so decorative vector underlays do not rebase content.
     *
     * @param array<string, mixed> $node
     */
    private function isContainingBlockOriginCandidate(array $node): bool
    {
        return $this->treeHasText($node) || $this->treeHasImageReference($node) || ! $this->treeIsVectorShapeOnly($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasAbsoluteChild(array $node): bool
    {
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && 'absolute' === ($child['layout']['positioning'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->isDecorativeFlexUnderlay($child, $node) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( ! in_array((string) ($parentLayout['display'] ?? ''), array('flex', 'inline-flex'), true) ) {
            return false;
        }

        if ( 'absolute' === ($node['layout']['positioning'] ?? null) || $this->treeHasText($node) || $this->treeHasImageReference($node) ) {
            return false;
        }

        if ( ! $this->treeIsVectorShapeOnly($node) || ! $this->parentHasTextOutsideNode($parentNode, $node) ) {
            return false;
        }

        return $this->isOversizedAgainstParent($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isOversizedAgainstParent(array $node, array $parentNode): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension], $parentBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($parentBox[$dimension]) || 0.0 >= (float) $parentBox[$dimension] ) {
                return false;
            }
        }

        if ( (float) $box['width'] < 300.0 && (float) $box['height'] < 300.0 ) {
            return false;
        }

        $widthRatio = (float) $box['width'] / (float) $parentBox['width'];
        $heightRatio = (float) $box['height'] / (float) $parentBox['height'];
        $areaRatio = ((float) $box['width'] * (float) $box['height']) / ((float) $parentBox['width'] * (float) $parentBox['height']);

        return 0.75 <= $widthRatio || 0.75 <= $heightRatio || 0.45 <= $areaRatio;
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $node
     */
    private function parentHasTextOutsideNode(array $parentNode, array $node): bool
    {
        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || (string) ($sibling['id'] ?? '') === $nodeId ) {
                continue;
            }
            if ( $this->treeHasText($sibling) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeHasText(array $node): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return '' !== trim(strip_tags($this->textContent($node)));
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->treeHasText($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeHasImageReference(array $node): bool
    {
        if ( null !== $this->nodeAssetPath($node) || ! empty($this->explicitNodeAssetReferences($node)) || ! empty($this->nodeImagePaints($node)) ) {
            return true;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->treeHasImageReference($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeIsVectorShapeOnly(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        $children = $this->nodeList($node);
        if ( empty($children) ) {
            return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON', 'RECTANGLE'), true);
        }

        if ( ! in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'BOOLEAN_OPERATION'), true) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->treeIsVectorShapeOnly($child) ) {
                return false;
            }
        }

        return true;
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

        if ( 'FILL' === ($layout['sizing_horizontal'] ?? null) || 'FILL' === ($layout['sizing_vertical'] ?? null) ) {
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

                $segmentStyles = is_array($segment['style'] ?? null) ? $this->textStyleDeclarations($segment['style']) : array();
                if ( empty($segmentStyles) ) {
                    $content .= $this->sanitizeText($segmentText);
                    continue;
                }

                $content .= '<span style="' . $this->sanitizeAttribute(implode(';', $segmentStyles)) . '">' . $this->sanitizeText($segmentText) . '</span>';
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
        if ( $this->textHasLineBreaks($node) || $this->textHasDerivedLineBreaks($node) ) {
            $styles[] = 'white-space:pre-line';
        }

        return $styles;
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
        if ( 2 > count($baselines) ) {
            return null;
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

        $positions = array();
        foreach ( $baselines as $baseline ) {
            if ( is_array($baseline) && isset($baseline['position_y']) && is_numeric($baseline['position_y']) ) {
                $positions[] = (float) $baseline['position_y'];
            }
        }
        sort($positions);

        $deltas = array();
        for ( $i = 1; $i < count($positions); $i++ ) {
            $delta = $positions[$i] - $positions[$i - 1];
            if ( 0.0 < $delta ) {
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
     * @param array<string, mixed> $style
     * @return array<int, string>
     */
    private function textStyleDeclarations(array $style): array
    {
        $styles = array();

        if ( isset($style['font_family']) && is_scalar($style['font_family']) ) {
            $styles[] = 'font-family:' . $this->cssString((string) $style['font_family']);
        }

        if ( isset($style['font_size']) && is_numeric($style['font_size']) ) {
            $styles[] = 'font-size:' . $this->number((float) $style['font_size']) . 'px';
        }

        if ( isset($style['font_weight']) && is_numeric($style['font_weight']) ) {
            $styles[] = 'font-weight:' . $this->number((float) $style['font_weight']);
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

        return $styles;
    }

    /**
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function radiusStyles(array $box): array
    {
        if ( isset($box['corner_radius']) && is_numeric($box['corner_radius']) ) {
            return array('border-radius:' . $this->number((float) $box['corner_radius']) . 'px');
        }

        $styles = array();
        foreach ( array(
            'top_left_radius' => 'border-top-left-radius',
            'top_right_radius' => 'border-top-right-radius',
            'bottom_right_radius' => 'border-bottom-right-radius',
            'bottom_left_radius' => 'border-bottom-left-radius',
        ) as $sourceKey => $property ) {
            if ( isset($box[$sourceKey]) && is_numeric($box[$sourceKey]) ) {
                $styles[] = $property . ':' . $this->number((float) $box[$sourceKey]) . 'px';
            }
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function strokeStyles(array $node): array
    {
        $paints = is_array($node['figma_paints']['strokes'] ?? null) ? $node['figma_paints']['strokes'] : array();
        $stroke = $this->firstCssPaint($paints);
        if ( null === $stroke ) {
            return array();
        }

        $width = 1;
        if ( isset($node['strokeWeight']) && is_numeric($node['strokeWeight']) ) {
            $width = (float) $node['strokeWeight'];
        }

        if ( true === $stroke['gradient'] ) {
            return array(
                'border:' . $this->number((float) $width) . 'px solid transparent',
                'border-image:' . $stroke['css'] . ' 1',
            );
        }

        if ( 'OUTSIDE' === strtoupper((string) ($node['strokeAlign'] ?? '')) ) {
            return array('box-shadow:0 0 0 ' . $this->number((float) $width) . 'px ' . $stroke['css']);
        }

        return array('border:' . $this->number((float) $width) . 'px solid ' . $stroke['css']);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function effectStyles(array $node, string $type): array
    {
        $effects = is_array($node['figma_effects'] ?? null) ? $node['figma_effects'] : array();
        $boxShadows = array();
        $textShadows = array();
        $filters = array();
        $backdropFilters = array();

        foreach ( $effects as $effect ) {
            if ( ! is_array($effect) ) {
                continue;
            }

            $effectType = (string) ($effect['type'] ?? '');
            if ( in_array($effectType, array('drop_shadow', 'inner_shadow'), true) ) {
                $shadow = $this->shadowValue($effect, 'inner_shadow' === $effectType);
                if ( null === $shadow ) {
                    continue;
                }
                if ( 'TEXT' === $type && 'drop_shadow' === $effectType ) {
                    $textShadows[] = $shadow;
                } else {
                    $boxShadows[] = $shadow;
                }
                continue;
            }

            if ( 'layer_blur' === $effectType && isset($effect['radius']) && is_numeric($effect['radius']) ) {
                $filters[] = 'blur(' . $this->number((float) $effect['radius']) . 'px)';
            } elseif ( 'background_blur' === $effectType && isset($effect['radius']) && is_numeric($effect['radius']) ) {
                $backdropFilters[] = 'blur(' . $this->number((float) $effect['radius']) . 'px)';
            }
        }

        $styles = array();
        if ( ! empty($boxShadows) ) {
            $styles[] = 'box-shadow:' . implode(',', $boxShadows);
        }
        if ( ! empty($textShadows) ) {
            $styles[] = 'text-shadow:' . implode(',', $textShadows);
        }
        if ( ! empty($filters) ) {
            $styles[] = 'filter:' . implode(' ', $filters);
        }
        if ( ! empty($backdropFilters) ) {
            $styles[] = 'backdrop-filter:' . implode(' ', $backdropFilters);
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $effect
     */
    private function shadowValue(array $effect, bool $inset): ?string
    {
        $color = $this->color($effect['color'] ?? null);
        if ( null === $color ) {
            $color = 'rgba(0,0,0,0.25)';
        }

        return ( $inset ? 'inset ' : '' )
            . $this->number((float) ($effect['offset_x'] ?? 0)) . 'px '
            . $this->number((float) ($effect['offset_y'] ?? 0)) . 'px '
            . $this->number((float) ($effect['radius'] ?? 0)) . 'px '
            . $this->number((float) ($effect['spread'] ?? 0)) . 'px '
            . $color;
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
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? max(0.0, (float) $box['width']) : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? max(0.0, (float) $box['height']) : 0.0;
        $zeroHeightVectorFallbackHeight = $this->zeroHeightVectorFallbackHeight($node, $type);
        if ( $width <= 0 || ( $height <= 0 && null === $zeroHeightVectorFallbackHeight ) ) {
            return null;
        }
        $renderHeight = $height <= 0 && null !== $zeroHeightVectorFallbackHeight ? $zeroHeightVectorFallbackHeight : $height;

        $elements = $this->vectorPathElements($node);
        if ( empty($elements) && $height <= 0 && null !== $zeroHeightVectorFallbackHeight ) {
            $elements = $this->zeroHeightVectorElements($node, $type, $width, $renderHeight);
        }
        if ( empty($elements) ) {
            $elements = $this->primitiveVectorElements($node, $type, $width, $renderHeight, $parentNode);
        }
        if ( empty($elements) ) {
            return null;
        }

        $viewBox = array('x' => 0.0, 'y' => 0.0, 'width' => $width, 'height' => $renderHeight);
        $pathBounds = $this->vectorPathBounds($node);
        if ( null !== $pathBounds && ( $pathBounds['width'] > $width + 0.001 || $pathBounds['height'] > $height + 0.001 || $pathBounds['x'] < -0.001 || $pathBounds['y'] < -0.001 ) ) {
            $viewBox = $pathBounds;
        } elseif ( null !== $pathBounds && $this->vectorMayClipStrokeAtViewBoxEdge($node) && $this->vectorPathTouchesViewBoxEdge($pathBounds, $viewBox) ) {
            $padding = 0.5;
            $viewBox = array(
                'x' => $viewBox['x'] - $padding,
                'y' => $viewBox['y'] - $padding,
                'width' => $viewBox['width'] + ( $padding * 2 ),
                'height' => $viewBox['height'] + ( $padding * 2 ),
            );
        }

        $attributes = array(
            'xmlns="http://www.w3.org/2000/svg"',
            'viewBox="' . $this->number($viewBox['x']) . ' ' . $this->number($viewBox['y']) . ' ' . $this->number($viewBox['width']) . ' ' . $this->number($viewBox['height']) . '"',
            'width="100%"',
            'height="100%"',
            'role="img"',
            'aria-label="' . $this->sanitizeAttribute((string) ($node['name'] ?? $type)) . '"',
            'data-figma-vector="true"',
        );

        $body = implode('', $elements);
        $scale = is_array($node['figma_vector_scale'] ?? null) ? $node['figma_vector_scale'] : array();
        $scaleX = isset($scale['x']) && is_numeric($scale['x']) ? (float) $scale['x'] : 1.0;
        $scaleY = isset($scale['y']) && is_numeric($scale['y']) ? (float) $scale['y'] : 1.0;
        if ( abs($scaleX - 1.0) >= 0.0001 || abs($scaleY - 1.0) >= 0.0001 ) {
            $body = '<g transform="scale(' . $this->number($scaleX) . ' ' . $this->number($scaleY) . ')">' . $body . '</g>';
        }

        return '<svg ' . implode(' ', $attributes) . '>' . $body . '</svg>';
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
     * @param array{x: float, y: float, width: float, height: float} $pathBounds
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function vectorPathTouchesViewBoxEdge(array $pathBounds, array $viewBox): bool
    {
        $epsilon = 0.001;
        return abs($pathBounds['x'] - $viewBox['x']) <= $epsilon
            || abs($pathBounds['y'] - $viewBox['y']) <= $epsilon
            || abs(($pathBounds['x'] + $pathBounds['width']) - ($viewBox['x'] + $viewBox['width'])) <= $epsilon
            || abs(($pathBounds['y'] + $pathBounds['height']) - ($viewBox['y'] + $viewBox['height'])) <= $epsilon;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function vectorMayClipStrokeAtViewBoxEdge(array $node): bool
    {
        return $this->hasSvgStroke($this->svgPaintAttributes($node));
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function vectorPathBounds(array $node): ?array
    {
        $paths = array();
        if ( is_array($node['figma_vector_paths'] ?? null) ) {
            $paths = array_merge($paths, $node['figma_vector_paths']);
        }
        foreach ( array('vectorPaths', 'paths') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $paths = array_merge($paths, $node[$key]);
            }
        }
        foreach ( array('pathData', 'path', 'd') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $paths[] = array('data' => (string) $node[$key]);
            }
        }

        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;
        foreach ( $paths as $rawPath ) {
            $path = is_array($rawPath) ? (string) ($rawPath['data'] ?? $rawPath['pathData'] ?? $rawPath['path'] ?? $rawPath['d'] ?? '') : (string) $rawPath;
            $path = $this->safeSvgPathData($path, $this->svgPathDataByteLimit($rawPath));
            if ( null === $path || ! preg_match_all('/-?\d+(?:\.\d+)?(?:e[+-]?\d+)?/i', $path, $matches) ) {
                continue;
            }
            $numbers = array_map('floatval', $matches[0]);
            for ( $i = 0; $i + 1 < count($numbers); $i += 2 ) {
                $x = $numbers[$i];
                $y = $numbers[$i + 1];
                $minX = null === $minX ? $x : min($minX, $x);
                $minY = null === $minY ? $y : min($minY, $y);
                $maxX = null === $maxX ? $x : max($maxX, $x);
                $maxY = null === $maxY ? $y : max($maxY, $y);
            }
        }

        if ( null === $minX || null === $minY || null === $maxX || null === $maxY || $maxX <= $minX || $maxY <= $minY ) {
            return null;
        }

        return array('x' => $minX, 'y' => $minY, 'width' => $maxX - $minX, 'height' => $maxY - $minY);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function vectorPathElements(array $node): array
    {
        $rawPaths = array();
        if ( is_array($node['figma_vector_paths'] ?? null) ) {
            $rawPaths = array_merge($rawPaths, $node['figma_vector_paths']);
        }
        foreach ( array('vectorPaths', 'paths') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $rawPaths = array_merge($rawPaths, $node[$key]);
            }
        }
        foreach ( array('pathData', 'path', 'd') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $rawPaths[] = array('data' => (string) $node[$key]);
            }
        }

        $elements = array();
        foreach ( $rawPaths as $rawPath ) {
            $path = is_array($rawPath) ? (string) ($rawPath['data'] ?? $rawPath['pathData'] ?? $rawPath['path'] ?? $rawPath['d'] ?? '') : (string) $rawPath;
            $path = $this->safeSvgPathData($path, $this->svgPathDataByteLimit($rawPath));
            if ( null === $path ) {
                continue;
            }

            $paint = $this->svgPaintAttributes($node);
            if ( is_array($rawPath) && isset($rawPath['windingRule']) && is_scalar($rawPath['windingRule']) ) {
                $rule = strtolower((string) $rawPath['windingRule']);
                if ( in_array($rule, array('evenodd', 'nonzero'), true) ) {
                    $paint[] = 'fill-rule="' . $rule . '"';
                }
            }

            $elements[] = '<path d="' . $this->sanitizeAttribute($path) . '" ' . implode(' ', $paint) . '/>';
        }

        return $elements;
    }

    /**
     * @return array<int, string>
     */
    private function primitiveVectorElements(array $node, string $type, float $width, float $height, ?array $parentNode = null): array
    {
        $paint = $this->svgPaintAttributes($node);
        if ( 'LINE' === $type ) {
            if ( ! $this->hasSvgStroke($paint) ) {
                $paint[] = 'stroke="currentColor"';
                $paint[] = 'stroke-width="1"';
            }

            return array('<line x1="0" y1="0" x2="' . $this->number($width) . '" y2="' . $this->number($height) . '" ' . implode(' ', $paint) . '/>');
        }
        if ( 'ELLIPSE' === $type ) {
            return array('<ellipse cx="' . $this->number($width / 2) . '" cy="' . $this->number($height / 2) . '" rx="' . $this->number($width / 2) . '" ry="' . $this->number($height / 2) . '" ' . implode(' ', $paint) . '/>');
        }
        if ( 'STAR' === $type ) {
            $path = $this->primitiveStarPath($width, $height);
            return array('<path d="' . $this->sanitizeAttribute($path) . '" ' . implode(' ', $paint) . '/>');
        }
        if ( in_array($type, array('POLYGON', 'REGULAR_POLYGON'), true) ) {
            $path = $this->primitivePolygonPath($width, $height, $this->polygonPointCount($node));
            return array('<path d="' . $this->sanitizeAttribute($path) . '" ' . implode(' ', $paint) . '/>');
        }
        if ( in_array($type, array('VECTOR', 'BOOLEAN_OPERATION'), true) ) {
            if ( 'BOOLEAN_OPERATION' === $type && ! empty($this->nodeList($node)) ) {
                return array();
            }
            if ( 'fill="none"' === $paint[0] && ! $this->hasSvgStroke($paint) ) {
                if ( $this->hasExplicitVectorSource($node) ) {
                    return array();
                }
                $paint = $this->inheritedVectorPaintAttributes($parentNode);
                if ( empty($paint) ) {
                    return array();
                }
            }

            return array('<rect x="0" y="0" width="' . $this->number($width) . '" height="' . $this->number($height) . '" ' . implode(' ', $paint) . '/>');
        }
        return array();
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function zeroHeightVectorElements(array $node, string $type, float $width, float $height): array
    {
        $paint = $this->svgPaintAttributes($node);
        if ( $this->hasSvgStroke($paint) ) {
            $paint = array_values(array_filter($paint, static fn (string $attribute): bool => ! str_starts_with($attribute, 'fill=')));
            return array('<line x1="0" y1="' . $this->number($height / 2) . '" x2="' . $this->number($width) . '" y2="' . $this->number($height / 2) . '" ' . implode(' ', $paint) . '/>');
        }

        if ( 'LINE' === $type ) {
            return array('<line x1="0" y1="' . $this->number($height / 2) . '" x2="' . $this->number($width) . '" y2="' . $this->number($height / 2) . '" fill="none" stroke="currentColor" stroke-width="' . $this->number($height) . '"/>');
        }

        if ( $this->hasSvgFill($paint) ) {
            return array('<rect x="0" y="0" width="' . $this->number($width) . '" height="' . $this->number($height) . '" ' . implode(' ', $paint) . '/>');
        }

        return array();
    }

    /**
     * @param array<string, mixed> $node
     */
    private function zeroHeightVectorFallbackHeight(array $node, string $type): ?float
    {
        if ( ! in_array($type, array('LINE', 'VECTOR'), true) ) {
            return null;
        }
        if ( 'VECTOR' === $type && ! $this->hasExplicitVectorSource($node) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0;
        if ( $width <= 0.0 || $height > 0.0 ) {
            return null;
        }

        $paint = $this->svgPaintAttributes($node);
        if ( 'LINE' === $type || $this->hasSvgStroke($paint) || $this->hasSvgFill($paint) ) {
            return max(1.0, $this->strokeWeight($node));
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function inheritedVectorPaintAttributes(?array $parentNode): array
    {
        if ( null === $parentNode ) {
            return array();
        }

        $fill = $this->backgroundColor($parentNode);
        return null === $fill ? array() : array('fill="' . $this->sanitizeAttribute($fill) . '"');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasExplicitVectorSource(array $node): bool
    {
        foreach ( array('figma_vector_paths', 'vectorPaths', 'paths', 'fillGeometry', 'strokeGeometry') as $key ) {
            if ( ! empty($node[$key]) ) {
                return true;
            }
        }

        foreach ( array('pathData', 'path', 'd') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== trim((string) $node[$key]) ) {
                return true;
            }
        }

        return isset($node['vectorData']) && is_array($node['vectorData']) && ! empty($node['vectorData']);
    }

    private function primitiveStarPath(float $width, float $height): string
    {
        $cx = $width / 2;
        $cy = $height / 2;
        $outer = max(0.0, min($width, $height) / 2);
        $inner = $outer * 0.5;
        $parts = array();
        for ( $index = 0; $index < 10; $index++ ) {
            $angle = -M_PI / 2 + ( $index * M_PI / 5 );
            $radius = 0 === $index % 2 ? $outer : $inner;
            $x = $cx + cos($angle) * $radius;
            $y = $cy + sin($angle) * $radius;
            $parts[] = ( 0 === $index ? 'M ' : 'L ' ) . $this->number($x) . ' ' . $this->number($y);
        }

        return implode(' ', $parts) . ' Z';
    }

    private function primitivePolygonPath(float $width, float $height, int $points): string
    {
        $points = max(3, $points);
        $cx = $width / 2;
        $cy = $height / 2;
        $radius = max(0.0, min($width, $height) / 2);
        $parts = array();
        for ( $index = 0; $index < $points; $index++ ) {
            $angle = -M_PI / 2 + ( $index * 2 * M_PI / $points );
            $x = $cx + cos($angle) * $radius;
            $y = $cy + sin($angle) * $radius;
            $parts[] = ( 0 === $index ? 'M ' : 'L ' ) . $this->number($x) . ' ' . $this->number($y);
        }

        return implode(' ', $parts) . ' Z';
    }

    private function polygonPointCount(array $node): int
    {
        foreach ( array('pointCount', 'point_count', 'sides', 'side_count') as $key ) {
            if ( isset($node[$key]) && is_numeric($node[$key]) ) {
                return max(3, (int) $node[$key]);
            }
        }

        return 3;
    }

    private function safeSvgPathData(string $path, int $maxBytes = self::MAX_RAW_SVG_PATH_DATA_BYTES): ?string
    {
        $path = trim(preg_replace('/\s+/', ' ', $path) ?? '');
        if ( '' === $path || strlen($path) > $maxBytes ) {
            return null;
        }

        if ( ! preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9,\.\-+\s]+$/', $path) ) {
            return null;
        }

        return $this->canonicalSvgPathData($path);
    }

    private function canonicalSvgPathData(string $path): ?string
    {
        preg_match_all('/[MmZzLlHhVvCcSsQqTtAa]|[-+]?(?:\d*\.\d+|\d+\.?)(?:e[-+]?\d+)?/i', $path, $matches);
        $tokens = $matches[0] ?? array();
        if ( empty($tokens) ) {
            return null;
        }

        $canonical = '';
        $previousTokenType = '';
        $previousCommand = '';
        foreach ( $tokens as $token ) {
            if ( 1 === strlen($token) && preg_match('/^[MmZzLlHhVvCcSsQqTtAa]$/', $token) ) {
                if ( $token !== $previousCommand || in_array($token, array('M', 'm', 'Z', 'z'), true) ) {
                    $canonical .= $token;
                    $previousTokenType = 'command';
                }
                $previousCommand = $token;
                continue;
            }

            $number = $this->number((float) $token);
            if ( 'number' === $previousTokenType && ! str_starts_with($number, '-') ) {
                $canonical .= ' ';
            }
            $canonical .= $number;
            $previousTokenType = 'number';
        }

        return $canonical;
    }

    private function svgPathDataByteLimit(mixed $rawPath): int
    {
        if ( is_array($rawPath) && isset($rawPath['source']) && is_scalar($rawPath['source']) ) {
            $source = (string) $rawPath['source'];
            if ( str_starts_with($source, 'fillGeometry') || str_starts_with($source, 'strokeGeometry') || 'vectorData.vectorNetworkBlob' === $source ) {
                return self::MAX_DECODED_FIGMA_SVG_PATH_DATA_BYTES;
            }
        }

        return self::MAX_RAW_SVG_PATH_DATA_BYTES;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function svgPaintAttributes(array $node): array
    {
        $paints = is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array();
        $fill = $this->firstSolidPaint($paints);
        $paints = is_array($node['figma_paints']['strokes'] ?? null) ? $node['figma_paints']['strokes'] : array();
        $stroke = $this->firstSolidPaint($paints);

        $attributes = array('fill="' . ( null === $fill ? 'none' : $this->sanitizeAttribute($fill) ) . '"');
        if ( null !== $stroke ) {
            $attributes[] = 'stroke="' . $this->sanitizeAttribute($stroke) . '"';
            $attributes[] = 'stroke-width="' . $this->number($this->strokeWeight($node)) . '"';
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function strokeWeight(array $node): float
    {
        return isset($node['strokeWeight']) && is_numeric($node['strokeWeight']) ? max(0.0, (float) $node['strokeWeight']) : 1.0;
    }

    /**
     * @param array<int, string> $attributes
     */
    private function hasSvgStroke(array $attributes): bool
    {
        foreach ( $attributes as $attribute ) {
            if ( str_starts_with($attribute, 'stroke=') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $attributes
     */
    private function hasSvgFill(array $attributes): bool
    {
        foreach ( $attributes as $attribute ) {
            if ( str_starts_with($attribute, 'fill=') && 'fill="none"' !== $attribute ) {
                return true;
            }
        }

        return false;
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

            if ( in_array(($paint['type'] ?? null), array('GRADIENT_LINEAR', 'GRADIENT_RADIAL'), true) ) {
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
            return 'radial-gradient(circle,' . implode(',', $cssStops) . ')';
        }

        return 'linear-gradient(180deg,' . implode(',', $cssStops) . ')';
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

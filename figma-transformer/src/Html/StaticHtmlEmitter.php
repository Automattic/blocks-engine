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
            'body{margin:0;overflow-x:auto}',
            '.figma-root{position:relative;width:max-content;min-width:100%;overflow-x:visible}',
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

        $visualNodeMap = $this->visualNodeMap($nodes);
        $fontFamilies = $this->fontFamilies($nodeStyleDiagnostics);
        $transformDiagnostics = $this->transformDiagnostics($nodes, $assetFiles, $fontFamilies, $fontCss, $css, $diagnostics);
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
                'font_usage'                   => $this->fontUsage($nodeStyleDiagnostics),
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
            'body{margin:0;overflow-x:auto}',
            '.figma-root{position:relative;width:max-content;min-width:100%;overflow-x:visible}',
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

        $visualNodeMap = $this->visualNodeMap($renderedNodes);
        $fontFamilies = $this->fontFamilies($nodeStyleDiagnostics);
        $transformDiagnostics = $this->transformDiagnostics($renderedNodes, $assetFiles, $fontFamilies, $fontCss, $css, $diagnostics);
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
                'font_usage'                   => $this->fontUsage($nodeStyleDiagnostics),
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
        $vectorSvg = $this->supportedVectorSvg($node, $type);
        $assetPath = $this->nodeAssetPath($node);
        $hasVectorAssetFallback = $this->isUnsupportedVectorType($type) && null !== $assetPath;

        if ( ! ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) ) {
            foreach ( $children as $child ) {
                if ( is_array($child) ) {
                    $content .= $this->emitNode($child, $cssRules, $diagnostics, $nodeStyleDiagnostics, $depth + 1, $node);
                }
            }
        }

        if ( null !== $vectorSvg ) {
            $content = $this->vectorSvgMarkup($vectorSvg, $node, $type) . $content;
        }

        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg && ! $hasVectorAssetFallback ) {
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
        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg && ! $hasVectorAssetFallback ) {
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
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( ! isset($style['color']) ) {
            $paints = is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array();
            $color = $this->firstSolidPaint($paints);
            if ( null !== $color ) {
                $style['css_color'] = $color;
            }
        }

        $declarations = $this->styleDeclarationMap($this->textStyleDeclarations($style));
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
     * @return array<int, array{family:string,weights:array<int,int>}>
     */
    private function fontUsage(array $nodeStyleDiagnostics): array
    {
        $weightsByFamily = array();
        foreach ( $nodeStyleDiagnostics as $diagnostic ) {
            $expected = is_array($diagnostic['expected'] ?? null) ? $diagnostic['expected'] : array();
            if ( ! isset($expected['font_family']) || ! is_scalar($expected['font_family']) ) {
                continue;
            }

            $family = trim((string) $expected['font_family'], " \t\n\r\0\x0B\"");
            if ( '' === $family ) {
                continue;
            }

            $weight = isset($expected['font_weight']) && is_numeric($expected['font_weight']) ? (int) $expected['font_weight'] : 400;
            $weightsByFamily[$family][] = $weight;
        }

        ksort($weightsByFamily);
        $usage = array();
        foreach ( $weightsByFamily as $family => $weights ) {
            $weights = array_values(array_unique($weights));
            sort($weights);
            $usage[] = array('family' => $family, 'weights' => $weights);
        }

        return $usage;
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
        $map = array();
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->appendVisualNodeMap($node, $map, 0.0, 0.0, null);
            }
        }

        return $map;
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
    private function transformDiagnostics(array $nodes, array $assetFiles, array $fontFamilies, string $fontCss, string $css, array $diagnostics): array
    {
        $image = array(
            'paint_refs'      => 0,
            'node_refs'       => 0,
            'resolved_assets' => 0,
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
            'decorative_underlays'      => array(
                'count' => 0,
                'nodes' => array(),
            ),
        );

        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->collectTransformDiagnostics($node, $image, $vectors, $layout);
            }
        }

        $image['missing_assets'] = array_values($image['missing_assets']);
        $vectors['placeholder_nodes'] = array_values($vectors['placeholder_nodes']);
        $layout['decorative_underlays']['nodes'] = array_values($layout['decorative_underlays']['nodes']);
        $layout['decorative_underlays']['count'] = count($layout['decorative_underlays']['nodes']);

        return array(
            'schema' => 'blocks-engine/figma-transformer/transform-diagnostics/v1',
            'images' => $image,
            'vectors' => $vectors,
            'fonts' => array(
                'families'      => $fontFamilies,
                'count'         => count($fontFamilies),
                'css_supplied'  => '' !== $fontCss,
                'materialized'  => '' !== $fontCss,
                'missing_css'   => '' === $fontCss ? array_values(array_filter($fontFamilies, fn (string $family): bool => ! $this->isWebSafeFontFamily($family))) : array(),
            ),
            'assets' => array(
                'emitted_files' => count($assetFiles),
                'paths'         => array_values(array_map(static fn (array $file): string => (string) ($file['path'] ?? ''), $assetFiles)),
            ),
            'generated_svg_assets' => $this->generatedSvgAssetDiagnostics($assetFiles),
            'layout' => $layout,
            'diagnostic_codes' => $this->diagnosticCodeCounts($diagnostics),
        );
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
            $assets[] = array(
                'id'        => $sourceId,
                'path'      => (string) ($file['path'] ?? ''),
                'mime_type' => 'image/svg+xml',
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            );
        }

        usort($assets, static fn (array $a, array $b): int => ((int) $b['bytes'] <=> (int) $a['bytes']) ?: strcmp((string) $a['path'], (string) $b['path']));

        return array(
            'schema' => 'blocks-engine/figma-transformer/generated-svg-assets/v1',
            'threshold_bytes' => self::EXTERNAL_VECTOR_SVG_BYTES,
            'count' => count($assets),
            'bytes' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['bytes'] ?? 0), $assets)),
            'paths' => array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets)),
            'largest_assets' => array_slice($assets, 0, 10),
            'assets' => $assets,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $image
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $layout
     */
    private function collectTransformDiagnostics(array $node, array &$image, array &$vectors, array &$layout, ?array $parentNode = null): void
    {
        if ( null !== $parentNode && $this->isDecorativeFlexUnderlay($node, $parentNode) ) {
            $layout['decorative_underlays']['nodes'][] = $this->decorativeUnderlayDiagnostic($node, $parentNode);
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
            if ( null !== $this->supportedVectorSvg($node, $type) ) {
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
                $this->collectTransformDiagnostics($child, $image, $vectors, $layout, $node);
            }
        }
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
     * @param array<int, array<string, mixed>> $map
     */
    private function appendVisualNodeMap(array $node, array &$map, float $x, float $y, ?array $parentNode): void
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();

        if ( null !== $parentNode && $this->isFreeformContainer($parentNode) ) {
            $x += $this->positionOffset($box, $parentBox, 'x', $parentNode) ?? 0.0;
            $y += $this->positionOffset($box, $parentBox, 'y', $parentNode) ?? 0.0;
        } elseif ( null !== $parentNode && 'absolute' === ($layout['positioning'] ?? null) ) {
            $x += $this->positionOffset($box, $parentBox, 'x', $parentNode) ?? 0.0;
            $y += $this->positionOffset($box, $parentBox, 'y', $parentNode) ?? 0.0;
        }

        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        if ( null !== $width && null !== $height ) {
            $imagePaint = $this->firstImagePaint($node);
            $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
            $map[] = array(
                'id' => (string) ($node['id'] ?? ''),
                'parent_id' => null !== $parentNode ? (string) ($parentNode['id'] ?? '') : '',
                'name' => (string) ($node['name'] ?? ''),
                'type' => strtoupper((string) ($node['type'] ?? '')),
                'rect' => array(
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height,
                ),
                'layout' => array(
                    'display' => $layout['display'] ?? null,
                    'flex_direction' => $layout['flex_direction'] ?? null,
                    'positioning' => $layout['positioning'] ?? null,
                    'coordinate_space' => $box['coordinate_space'] ?? null,
                ),
                'image' => null === $imagePaint ? null : $this->visualImageMetadata($imagePaint),
                'text' => empty($text) ? null : $this->visualTextMetadata($text),
            );
        }

        $children = $this->nodeList($node);
        if ( empty($children) ) {
            return;
        }

        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        $childX = $x + ( isset($padding['left']) && is_numeric($padding['left']) ? (float) $padding['left'] : 0.0 );
        $childY = $y + ( isset($padding['top']) && is_numeric($padding['top']) ? (float) $padding['top'] : 0.0 );
        $gap = isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ? (float) $layout['item_spacing'] : 0.0;
        $cursorX = $childX;
        $cursorY = $childY;
        $isRow = 'row' === ($layout['flex_direction'] ?? null);

        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( $this->isFreeformContainer($node) || 'absolute' === ($childLayout['positioning'] ?? null) || $this->isDecorativeFlexUnderlay($child, $node) ) {
                $this->appendVisualNodeMap($child, $map, $x, $y, $node);
                continue;
            }

            $this->appendVisualNodeMap($child, $map, $cursorX, $cursorY, $node);
            if ( $isRow ) {
                $cursorX += ( isset($childBox['width']) && is_numeric($childBox['width']) ? (float) $childBox['width'] : 0.0 ) + $gap;
            } else {
                $cursorY += ( isset($childBox['height']) && is_numeric($childBox['height']) ? (float) $childBox['height'] : 0.0 ) + $gap;
            }
        }
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
        foreach ( array('width', 'height') as $dimension ) {
            $sizingKey = 'width' === $dimension ? 'sizing_horizontal' : 'sizing_vertical';
            $sizing = strtoupper((string) ($layout[$sizingKey] ?? ''));
            if ( 'HUG' === $sizing ) {
                $styles[] = $dimension . ':fit-content';
            } elseif ( 'FILL' === $sizing ) {
                $styles[] = $dimension . ':100%';
            } elseif ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
                $property = null === $parentNode && 'height' === $dimension && 'flex' === ($layout['display'] ?? null) ? 'min-height' : $dimension;
                $styles[] = $property . ':' . $this->number((float) $box[$dimension]) . 'px';
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

        $transform = $this->transformStyle($box);
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

        if ( isset($layout['padding']) && is_array($layout['padding']) ) {
            foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                if ( isset($layout['padding'][$edge]) && is_numeric($layout['padding'][$edge]) ) {
                    $styles[] = 'padding-' . $edge . ':' . $this->number((float) $layout['padding'][$edge]) . 'px';
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

        if ( 1 !== count($children) || ! is_array($children[0]) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $childBox = is_array($children[0]['box'] ?? null) ? $children[0]['box'] : array();
        if ( ! isset($box['width'], $box['height'], $childBox['width'], $childBox['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) || ! is_numeric($childBox['width']) || ! is_numeric($childBox['height']) ) {
            return false;
        }

        return (float) $childBox['width'] > (float) $box['width'] || (float) $childBox['height'] > (float) $box['height'];
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

        if ( null !== $parentNode && (! isset($parentBox[$dimension]) || $this->shouldInferZeroRootOrigin($parentBox, $parentNode, $dimension)) ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
            if ( null !== $origin ) {
                return (float) $box[$dimension] - $origin;
            }
        }

        return $this->relativeOffset($box, $parentBox, $dimension);
    }

    /**
     * Figma plugin payloads can normalize a selected frame origin to 0 while
     * preserving child coordinates in the original canvas space.
     *
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function shouldInferZeroRootOrigin(array $parentBox, array $parentNode, string $dimension): bool
    {
        if ( ! isset($parentBox[$dimension]) || ! is_numeric($parentBox[$dimension]) || 0.0 !== (float) $parentBox[$dimension] ) {
            return false;
        }

        if ( ! empty($parentNode['_parent_id']) ) {
            return false;
        }

        $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);

        return null !== $origin && $origin < 0.0;
    }

    /**
     * Infer a root origin for selected frames that carry only size while their children remain in canvas coordinates.
     *
     * @param array<string, mixed> $parentNode
     */
    private function inferredContainingBlockOrigin(array $parentNode, string $dimension): ?float
    {
        $origin = null;
        foreach ( $this->nodeList($parentNode) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( 'local' === ($childBox['coordinate_space'] ?? null) || ! isset($childBox[$dimension]) || ! is_numeric($childBox[$dimension]) ) {
                continue;
            }

            $value = (float) $childBox[$dimension];
            $origin = null === $origin ? $value : min($origin, $value);
        }

        return $origin;
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

        return 'matrix(' . implode(',', array_map(fn (mixed $value): string => $this->number((float) $value), $values)) . ')';
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
            $assets[] = array(
                'id'        => (string) ($file['source_id'] ?? ''),
                'path'      => (string) $file['path'],
                'mime_type' => (string) $file['mime_type'],
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            );
        }

        return $assets;
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
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function firstImagePaint(array $node): ?array
    {
        foreach ( $this->nodeImagePaints($node) as $paint ) {
            return $paint;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $paint
     * @return array<string, mixed>
     */
    private function visualImageMetadata(array $paint): array
    {
        $transform = $this->imagePaintTransformMatrix($paint);
        $metadata = array(
            'scale_mode' => strtoupper((string) ($paint['imageScaleMode'] ?? $paint['scaleMode'] ?? 'FILL')),
            'has_transform' => null !== $transform && ! $this->isIdentityImageTransform($transform),
            'color_managed' => true === ($paint['imageShouldColorManage'] ?? false),
        );

        foreach ( array('ref', 'imageHash', 'imageName', 'originalImageWidth', 'originalImageHeight', 'scale', 'rotation') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) ) {
                $metadata[$key] = $paint[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $text
     * @return array<string, mixed>
     */
    private function visualTextMetadata(array $text): array
    {
        $metadata = array(
            'character_count' => isset($text['characters']) && is_scalar($text['characters']) ? strlen((string) $text['characters']) : 0,
            'segment_count' => is_array($text['segments'] ?? null) ? count($text['segments']) : 0,
        );

        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        if ( ! empty($derivedLayout) ) {
            $metadata['derived_layout'] = $derivedLayout;
            $metadata['has_derived_layout'] = true;
            $metadata['baseline_count'] = $derivedLayout['baseline_count'] ?? 0;
            $metadata['glyph_count'] = $derivedLayout['glyph_count'] ?? 0;
            $metadata['glyph_path_count'] = is_array($derivedLayout['glyph_paths'] ?? null) ? count($derivedLayout['glyph_paths']) : 0;
            $characters = isset($text['characters']) && is_scalar($text['characters']) ? (string) $text['characters'] : '';
            $metadata['glyph_rendering'] = $this->renderTextGlyphPaths && ! empty($derivedLayout['glyph_paths']) && $this->textAllowsGlyphRendering($characters, $text) ? 'svg_paths' : 'dom_text';
        } else {
            $metadata['has_derived_layout'] = false;
            $metadata['glyph_rendering'] = 'dom_text';
        }

        return $metadata;
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
    private function supportedVectorSvg(array $node, string $type): ?string
    {
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? max(0.0, (float) $box['width']) : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? max(0.0, (float) $box['height']) : 0.0;
        if ( $width <= 0 || $height <= 0 ) {
            return null;
        }

        $elements = $this->vectorPathElements($node);
        if ( empty($elements) ) {
            $elements = $this->primitiveVectorElements($node, $type, $width, $height);
        }
        if ( empty($elements) ) {
            return null;
        }

        $viewBox = array('x' => 0.0, 'y' => 0.0, 'width' => $width, 'height' => $height);
        $pathBounds = $this->vectorPathBounds($node);
        if ( null !== $pathBounds && ( $pathBounds['width'] > $width + 0.001 || $pathBounds['height'] > $height + 0.001 || $pathBounds['x'] < -0.001 || $pathBounds['y'] < -0.001 ) ) {
            $viewBox = $pathBounds;
        } elseif ( null !== $pathBounds && $this->vectorPathTouchesViewBoxEdge($pathBounds, $viewBox) ) {
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
    private function primitiveVectorElements(array $node, string $type, float $width, float $height): array
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
        return array();
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

        return preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9,\.\-+\s]+$/', $path) ? $path : null;
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

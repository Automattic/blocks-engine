<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Emits static HTML artifacts from a normalized scenegraph.
 */
final class StaticHtmlEmitter
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $assetsById = array();

    /**
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $options Transformation options.
     * @return array<string, mixed>
     */
    public function emit(array $scenegraph, array $options = array()): array
    {
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $nodes = $this->nodeList($scenegraph);
        $diagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);

        $body = '';
        $cssRules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            'img{display:block;max-width:100%;height:auto}',
        );

        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $body .= $this->emitNode($node, $cssRules, $diagnostics, 0, null);
        }

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
                'content'   => implode("\n", $cssRules) . "\n",
            ),
        );

        foreach ( $assetFiles as $assetFile ) {
            $files[] = $assetFile;
        }

        return array(
            'status'        => 'success',
            'diagnostics'   => $diagnostics,
            'files'         => $files,
            'assets'        => $this->assetReport($assetFiles),
            'source_report' => array(
                'name'       => $title,
                'node_count' => $this->countNodes($nodes),
                'schema'     => $scenegraph['schema'] ?? null,
            ),
            'metrics'       => array(
                'node_count'  => $this->countNodes($nodes),
                'asset_count' => count($assetFiles),
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>                 $cssRules
     * @param array<int, array<string, mixed>>   $diagnostics
     */
    private function emitNode(array $node, array &$cssRules, array &$diagnostics, int $depth, ?array $parentNode): string
    {
        $id = $this->sanitizeAttribute((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $attributeName = $this->sanitizeAttribute($name);
        $text = $this->textContent($node);
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $tag = $this->tagName($type, $name, $depth);
        $className = 'figma-node-' . $this->slug($id . '-' . $name);
        $children = $this->nodeList($node);
        $content = $text;

        foreach ( $children as $child ) {
            if ( is_array($child) ) {
                $content .= $this->emitNode($child, $cssRules, $diagnostics, $depth + 1, $node);
            }
        }

        $vectorSvg = $this->supportedVectorSvg($node, $type);
        if ( null !== $vectorSvg ) {
            $content = $vectorSvg . $content;
        }

        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg ) {
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'unsupported_vector_node_placeholder',
                'message'  => 'Unsupported vector-like Figma node emitted as a static placeholder.',
                'node_id'  => (string) ($node['id'] ?? ''),
                'type'     => $type,
            );

            if ( '' === $content ) {
                $content = '<span class="figma-unsupported-vector-placeholder">Unsupported Figma ' . $this->sanitizeText($type) . '</span>';
            }
        }

        $styles = $this->styleDeclarations($node, $type, $parentNode);
        if ( ! empty($styles) ) {
            $cssRules[] = '.' . $className . '{' . implode(';', $styles) . '}';
        }

        $attributes = sprintf(' class="%1$s" data-figma-node-id="%2$s" data-figma-node-name="%3$s"', $className, $id, $attributeName);
        if ( 'RECTANGLE' === $type && '' === $content ) {
            $attributes .= ' aria-hidden="true"';
        }
        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg ) {
            $attributes .= ' data-figma-unsupported-vector="true" role="img" aria-label="Unsupported Figma ' . $this->sanitizeAttribute($type) . ' node"';
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
                $styles[] = $dimension . ':' . $this->number((float) $box[$dimension]) . 'px';
            }
        }

        if ( true === ($layout['clips_content'] ?? false) ) {
            $styles[] = 'overflow:hidden';
        }

        if ( $this->hasAbsoluteChild($node) ) {
            $styles[] = 'position:relative';
        }

        if ( 'absolute' === ($layout['positioning'] ?? null) ) {
            $styles[] = 'position:absolute';
            foreach ( $this->absolutePositionStyles($box, $layout, $parentNode) as $style ) {
                $styles[] = $style;
            }
        }

        if ( 'TEXT' !== $type && ! in_array($type, array('VECTOR', 'LINE', 'ELLIPSE'), true) ) {
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
            $styles[] = 'background-size:cover';
            $styles[] = 'background-position:center';
        }

        if ( 'TEXT' === $type ) {
            foreach ( $this->textStyles($node) as $style ) {
                $styles[] = $style;
            }
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

        foreach ( $this->flexItemStyles($layout) as $style ) {
            $styles[] = $style;
        }

        return $styles;
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
        $left = $this->relativeOffset($box, $parentBox, 'x');
        $top = $this->relativeOffset($box, $parentBox, 'y');
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
     * @param array<int, mixed> $transform
     */
    private function cssMatrix(array $transform): ?string
    {
        if ( 2 !== count($transform) || ! is_array($transform[0] ?? null) || ! is_array($transform[1] ?? null) ) {
            return null;
        }

        $values = array($transform[0][0] ?? null, $transform[1][0] ?? null, $transform[0][1] ?? null, $transform[1][1] ?? null, $transform[0][2] ?? null, $transform[1][2] ?? null);
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
    private function flexItemStyles(array $layout): array
    {
        $styles = array();

        if ( 'FILL' === ($layout['sizing_horizontal'] ?? null) || 'FILL' === ($layout['sizing_vertical'] ?? null) ) {
            $styles[] = 'flex-grow:1';
            $styles[] = 'flex-shrink:1';
        } elseif ( isset($layout['grow']) && is_numeric($layout['grow']) ) {
            $styles[] = 'flex-grow:' . $this->number((float) $layout['grow']);
        }

        if ( isset($layout['align']) && 'STRETCH' === $layout['align'] ) {
            $styles[] = 'align-self:stretch';
        }

        $usesSourceOrder = 'absolute' === ($layout['positioning'] ?? null)
            || 'FILL' === ($layout['sizing_horizontal'] ?? null)
            || 'FILL' === ($layout['sizing_vertical'] ?? null)
            || isset($layout['grow'])
            || isset($layout['align']);

        if ( $usesSourceOrder && isset($layout['source_order']) && is_numeric($layout['source_order']) ) {
            $order = (int) $layout['source_order'];
            $styles[] = 'order:' . $order;
            $styles[] = 'z-index:' . $order;
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
            return $this->sanitizeText((string) $text['characters']);
        }

        return $this->sanitizeText((string) ($node['characters'] ?? $node['text'] ?? ''));
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

        return $this->textStyleDeclarations($style);
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

        if ( isset($style['line_height_px']) && is_numeric($style['line_height_px']) ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_px']) . 'px';
        } elseif ( isset($style['line_height_percent']) && is_numeric($style['line_height_percent']) ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_percent']) . '%';
        }

        if ( isset($style['letter_spacing']) && is_numeric($style['letter_spacing']) ) {
            $styles[] = 'letter-spacing:' . $this->number((float) $style['letter_spacing']) . 'px';
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
        $stroke = $this->firstSolidPaint($paints);
        if ( null === $stroke ) {
            return array();
        }

        $width = 1;
        if ( isset($node['strokeWeight']) && is_numeric($node['strokeWeight']) ) {
            $width = (float) $node['strokeWeight'];
        }

        return array('border:' . $this->number((float) $width) . 'px solid ' . $stroke);
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

            $mimeType = (string) ($asset['mime_type'] ?? $asset['mimeType'] ?? 'application/octet-stream');
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
                return (string) $this->assetsById[$assetId]['path'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<int, string>
     */
    private function assetAliases(array $asset, string $id): array
    {
        $aliases = array($id);
        foreach ( array('hash', 'imageRef', 'imageHash', 'asset_id', 'image_ref', 'source_id') as $key ) {
            if ( isset($asset[$key]) && is_scalar($asset[$key]) ) {
                $aliases[] = (string) $asset[$key];
            }
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
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $references[] = (string) $node[$key];
            }
        }

        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            if ( ! is_array($node[$paintKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$paintKey] as $paint ) {
                if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
                    continue;
                }

                foreach ( array('imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
                    if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                        $references[] = (string) $paint[$key];
                    }
                }
            }
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
        if ( ! in_array($type, array('VECTOR', 'LINE', 'ELLIPSE', 'RECTANGLE'), true) ) {
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

        $attributes = array(
            'xmlns="http://www.w3.org/2000/svg"',
            'viewBox="0 0 ' . $this->number($width) . ' ' . $this->number($height) . '"',
            'width="100%"',
            'height="100%"',
            'role="img"',
            'aria-label="' . $this->sanitizeAttribute((string) ($node['name'] ?? $type)) . '"',
            'data-figma-vector="true"',
        );

        return '<svg ' . implode(' ', $attributes) . '>' . implode('', $elements) . '</svg>';
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function vectorPathElements(array $node): array
    {
        $rawPaths = array();
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
            $path = $this->safeSvgPathData($path);
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
        return array();
    }

    private function safeSvgPathData(string $path): ?string
    {
        $path = trim(preg_replace('/\s+/', ' ', $path) ?? '');
        if ( '' === $path || strlen($path) > 20000 ) {
            return null;
        }

        return preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9,\.\-+\s]+$/', $path) ? $path : null;
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
        $color = $this->firstSolidPaint($paints);
        if ( null !== $color ) {
            return $color;
        }

        $paints = is_array($node['figma_paints']['background'] ?? null) ? $node['figma_paints']['background'] : array();
        $color = $this->firstSolidPaint($paints);
        if ( null !== $color ) {
            return $color;
        }

        return $this->color($node['background'] ?? $node['backgroundColor'] ?? $node['fill'] ?? $node['fills'][0]['color'] ?? null);
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

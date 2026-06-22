<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes decoded Figma scenegraph payloads into a deterministic transformer contract.
 */
final class ScenegraphNormalizer
{
    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex()
    ) {
    }

    /**
     * @param array<string, mixed> $source Decoded NODE_CHANGES-shaped source array.
     * @param array<string, mixed> $options Normalization options.
     * @return array<string, mixed>
     */
    public function normalize(array $source, array $options = array()): array
    {
        $index       = $this->index->build($source);
        $diagnostics = $index['diagnostics'];
        $nodeMap     = $this->normalizeNodeMap($index['nodes'], $diagnostics);
        $topLevelIds = $index['top_level_node_ids'];
        $frameIds    = $this->selectTopLevelFrameIds($topLevelIds, $nodeMap);

        $selectedFrameId = null;
        if ( isset($options['frame_id']) && is_scalar($options['frame_id']) && isset($nodeMap[(string) $options['frame_id']]) ) {
            $selectedFrameId = (string) $options['frame_id'];
        } elseif ( ! empty($frameIds) ) {
            $selectedFrameId = $frameIds[0];
        } elseif ( ! empty($topLevelIds) ) {
            $selectedFrameId = $topLevelIds[0];
        }

        $renderIds = $topLevelIds;
        $renderNodes = array();
        foreach ( $renderIds as $id ) {
            if ( isset($nodeMap[$id]) ) {
                $renderNodes[] = $nodeMap[$id];
            }
        }

        $textInventory   = $this->buildTextInventory($nodeMap);
        $assetReferences = $this->buildAssetReferences($nodeMap);
        $sourceName      = $this->readSourceName($source, $renderNodes);

        return array(
            'schema'              => 'blocks-engine/figma-transformer/scenegraph/v1',
            'name'                => $sourceName,
            'assets'              => is_array($source['assets'] ?? null) ? $source['assets'] : array(),
            'nodes'               => $renderNodes,
            'assets'              => is_array($source['assets'] ?? null) ? $source['assets'] : array(),
            'node_map'            => $nodeMap,
            'parent_index'        => $index['parent_index'],
            'children_index'      => $index['children_index'],
            'top_level_node_ids'  => $topLevelIds,
            'top_level_frame_ids' => $frameIds,
            'selected_frame_id'   => $selectedFrameId,
            'text_inventory'      => $textInventory,
            'asset_references'    => $assetReferences,
            'diagnostics'         => $diagnostics,
            'source_report'       => array(
                'schema'                => 'blocks-engine/figma-transformer/scenegraph-source/v1',
                'input_shape'           => $this->detectInputShape($source),
                'name'                  => $sourceName,
                'node_count'            => count($nodeMap),
                'top_level_node_ids'    => $topLevelIds,
                'top_level_frame_ids'   => $frameIds,
                'selected_frame_id'     => $selectedFrameId,
                'text_node_count'       => count($textInventory),
                'asset_reference_count' => count($assetReferences),
                'asset_references'      => $assetReferences,
                'diagnostic_count'      => count($diagnostics),
            ),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>>    $diagnostics
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodeMap(array $nodeMap, array &$diagnostics): array
    {
        foreach ( $nodeMap as $id => $node ) {
            $nodeMap[$id] = $this->normalizeNode($node, $diagnostics);
        }

        return $nodeMap;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node, array &$diagnostics): array
    {
        $id = (string) ($node['id'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? ''));

        if ( 'TEXT' === $type ) {
            $text = $this->normalizeText($node);
            if ( ! empty($text) ) {
                $node['figma_text'] = $text;
            }
        }

        $paints = $this->normalizePaintCollections($node, $id, $diagnostics);
        if ( ! empty($paints) ) {
            $node['figma_paints'] = $paints;
        }

        $box = $this->normalizeVisualBox($node);
        if ( ! empty($box) ) {
            $node['figma_box'] = $box;
        }

        $layoutBox = $this->normalizeLayoutBox($node);
        if ( ! empty($layoutBox) ) {
            $node['box'] = $layoutBox;
        }

        $layout = $this->normalizeLayout($node);
        if ( ! empty($layout) ) {
            $node['layout'] = $layout;
        }

        $this->diagnoseEffects($node, $id, $diagnostics);

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $index => $child ) {
                if ( is_array($child) ) {
                    $normalizedChild = $this->normalizeNode($child, $diagnostics);
                    $childLayout = is_array($normalizedChild['layout'] ?? null) ? $normalizedChild['layout'] : array();
                    $childLayout['source_order'] = isset($normalizedChild['_source_order']) && is_numeric($normalizedChild['_source_order'])
                        ? (int) $normalizedChild['_source_order']
                        : (int) $index;
                    $normalizedChild['layout'] = $childLayout;
                    $node[$childrenKey][$index] = $normalizedChild;
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeText(array $node): array
    {
        $text = array();

        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $text['characters'] = (string) $node[$key];
                break;
            }
        }

        $style = array();
        if ( is_array($node['style'] ?? null) ) {
            $style = $this->normalizeTextStyle($node['style']);
        }

        $rootStyle = $this->normalizeTextStyle($node);
        foreach ( $rootStyle as $key => $value ) {
            if ( ! array_key_exists($key, $style) ) {
                $style[$key] = $value;
            }
        }

        if ( ! empty($style) ) {
            $text['style'] = $style;
        }

        $segments = $this->normalizeStyledTextSegments($node);
        if ( ! empty($segments) ) {
            $text['segments'] = $segments;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normalizeTextStyle(array $source): array
    {
        $style = array();

        foreach ( array(
            'fontFamily' => 'font_family',
            'fontPostScriptName' => 'font_postscript_name',
            'fontWeight' => 'font_weight',
            'textAlignHorizontal' => 'text_align_horizontal',
            'textAlignVertical' => 'text_align_vertical',
            'textDecoration' => 'text_decoration',
        ) as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_scalar($source[$sourceKey]) && '' !== (string) $source[$sourceKey] ) {
                $style[$targetKey] = (string) $source[$sourceKey];
            }
        }

        foreach ( array('fontSize' => 'font_size', 'lineHeightPx' => 'line_height_px', 'lineHeightPercent' => 'line_height_percent', 'letterSpacing' => 'letter_spacing') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_numeric($source[$sourceKey]) ) {
                $style[$targetKey] = (float) $source[$sourceKey];
            }
        }

        foreach ( array('color', 'textColor') as $sourceKey ) {
            $color = $this->normalizeColor($source[$sourceKey] ?? null);
            if ( null !== $color ) {
                $style['color'] = $color;
                break;
            }
        }

        foreach ( array('underline' => 'underline', 'strikethrough' => 'strikethrough') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_bool($source[$sourceKey]) ) {
                $style[$targetKey] = $source[$sourceKey];
            }
        }

        return $style;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStyledTextSegments(array $node): array
    {
        $segments = array();
        $rawSegments = null;
        foreach ( array('styledTextSegments', 'segments') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $rawSegments = $node[$key];
                break;
            }
        }

        if ( ! is_array($rawSegments) ) {
            return array();
        }

        foreach ( $rawSegments as $segment ) {
            if ( ! is_array($segment) ) {
                continue;
            }

            $normalized = array();
            foreach ( array('characters', 'text') as $key ) {
                if ( isset($segment[$key]) && is_scalar($segment[$key]) ) {
                    $normalized['characters'] = (string) $segment[$key];
                    break;
                }
            }
            foreach ( array('start', 'end') as $key ) {
                if ( isset($segment[$key]) && is_numeric($segment[$key]) ) {
                    $normalized[$key] = (int) $segment[$key];
                }
            }

            $style = is_array($segment['style'] ?? null) ? $this->normalizeTextStyle($segment['style']) : $this->normalizeTextStyle($segment);
            if ( ! empty($style) ) {
                $normalized['style'] = $style;
            }

            if ( ! empty($normalized) ) {
                $segments[] = $normalized;
            }
        }

        return $segments;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizePaintCollections(array $node, string $nodeId, array &$diagnostics): array
    {
        $collections = array();
        foreach ( array('fills' => 'fills', 'strokes' => 'strokes', 'background' => 'background') as $sourceKey => $targetKey ) {
            if ( ! is_array($node[$sourceKey] ?? null) ) {
                continue;
            }

            $paints = array();
            foreach ( $node[$sourceKey] as $paint ) {
                if ( ! is_array($paint) ) {
                    continue;
                }

                $normalized = $this->normalizePaint($paint, $nodeId, $sourceKey, $diagnostics);
                if ( ! empty($normalized) ) {
                    $paints[] = $normalized;
                }
            }

            if ( ! empty($paints) ) {
                $collections[$targetKey] = $paints;
            }
        }

        foreach ( array('fill' => 'fills', 'backgroundColor' => 'background') as $sourceKey => $targetKey ) {
            if ( ! isset($node[$sourceKey]) ) {
                continue;
            }

            $color = $this->normalizeColor($node[$sourceKey]);
            if ( null !== $color ) {
                $collections[$targetKey][] = array('type' => 'SOLID', 'color' => $color);
            }
        }

        return $collections;
    }

    /**
     * @param array<string, mixed>             $paint
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizePaint(array $paint, string $nodeId, string $paintKey, array &$diagnostics): array
    {
        $type = strtoupper((string) ($paint['type'] ?? 'SOLID'));
        if ( false === ($paint['visible'] ?? true) ) {
            return array();
        }

        if ( 'SOLID' === $type ) {
            $color = $this->normalizeColor($paint['color'] ?? $paint);
            if ( null === $color ) {
                return array();
            }

            $normalized = array('type' => 'SOLID', 'color' => $color);
            if ( isset($paint['opacity']) && is_numeric($paint['opacity']) ) {
                $normalized['opacity'] = (float) $paint['opacity'];
            }

            return $normalized;
        }

        if ( 'IMAGE' === $type ) {
            $ref = $paint['imageRef'] ?? $paint['imageHash'] ?? null;
            return is_scalar($ref) && '' !== (string) $ref ? array('type' => 'IMAGE', 'ref' => (string) $ref) : array('type' => 'IMAGE');
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_figma_paint_type',
            'message'  => 'Unsupported Figma paint type was omitted from static CSS.',
            'context'  => array(
                'node_id' => $nodeId,
                'paint'   => $paintKey,
                'type'    => $type,
            ),
        );

        return array();
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeVisualBox(array $node): array
    {
        $box = array();

        if ( isset($node['opacity']) && is_numeric($node['opacity']) ) {
            $box['opacity'] = (float) $node['opacity'];
        }

        foreach ( array('rotation' => 'rotation') as $sourceKey => $targetKey ) {
            if ( isset($node[$sourceKey]) && is_numeric($node[$sourceKey]) ) {
                $box[$targetKey] = (float) $node[$sourceKey];
            }
        }

        foreach ( array('relativeTransform', 'absoluteTransform') as $sourceKey ) {
            if ( is_array($node[$sourceKey] ?? null) ) {
                $box['transform'] = $node[$sourceKey];
                break;
            }
        }

        if ( isset($node['cornerRadius']) && is_numeric($node['cornerRadius']) ) {
            $box['corner_radius'] = (float) $node['cornerRadius'];
        }

        foreach ( array(
            'topLeftRadius' => 'top_left_radius',
            'topRightRadius' => 'top_right_radius',
            'bottomRightRadius' => 'bottom_right_radius',
            'bottomLeftRadius' => 'bottom_left_radius',
        ) as $sourceKey => $targetKey ) {
            if ( isset($node[$sourceKey]) && is_numeric($node[$sourceKey]) ) {
                $box[$targetKey] = (float) $node[$sourceKey];
            }
        }

        return $box;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function diagnoseEffects(array $node, string $nodeId, array &$diagnostics): void
    {
        if ( ! is_array($node['effects'] ?? null) ) {
            return;
        }

        foreach ( $node['effects'] as $effect ) {
            if ( ! is_array($effect) || false === ($effect['visible'] ?? true) ) {
                continue;
            }

            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'unsupported_figma_effect_type',
                'message'  => 'Unsupported Figma effect was omitted from static CSS.',
                'context'  => array(
                    'node_id' => $nodeId,
                    'type'    => strtoupper((string) ($effect['type'] ?? 'UNKNOWN')),
                ),
            );
        }
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeColor(mixed $value): ?array
    {
        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->normalizeColorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->normalizeColorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->normalizeColorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $color = array('r' => $red, 'g' => $green, 'b' => $blue);
        if ( isset($value['a']) && is_numeric($value['a']) ) {
            $color['a'] = (float) $value['a'];
        }

        return $color;
    }

    private function normalizeColorChannel(mixed $value): ?float
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel > 1 ) {
            $channel /= 255;
        }

        return max(0, min(1, $channel));
    }

    /**
     * @param array<int, string> $topLevelIds
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, string>
     */
    private function selectTopLevelFrameIds(array $topLevelIds, array $nodeMap): array
    {
        $frameIds = array();

        foreach ( $topLevelIds as $id ) {
            $type = strtoupper((string) ($nodeMap[$id]['type'] ?? ''));
            if ( in_array($type, array('FRAME', 'COMPONENT', 'INSTANCE'), true) ) {
                $frameIds[] = $id;
            }
        }

        return $frameIds;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<string, array<string, mixed>>
     */
    /**
     * @param array<string, mixed> $node
     * @return array<string, float>
     */
    private function normalizeLayoutBox(array $node): array
    {
        $box = array();

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $boundsKey ) {
            if ( ! is_array($node[$boundsKey] ?? null) ) {
                continue;
            }

            foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
                if ( ! array_key_exists($dimension, $box) && isset($node[$boundsKey][$dimension]) && is_numeric($node[$boundsKey][$dimension]) ) {
                    $box[$dimension] = (float) $node[$boundsKey][$dimension];
                }
            }
        }

        foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                $box[$dimension] = (float) $node[$dimension];
            }
        }

        return $box;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLayout(array $node): array
    {
        $layout = array();

        if ( isset($node['layoutMode']) && is_scalar($node['layoutMode']) ) {
            $mode = strtoupper((string) $node['layoutMode']);
            $layout['mode'] = $mode;

            if ( 'HORIZONTAL' === $mode ) {
                $layout['display'] = 'flex';
                $layout['flex_direction'] = 'row';
            } elseif ( 'VERTICAL' === $mode ) {
                $layout['display'] = 'flex';
                $layout['flex_direction'] = 'column';
            }
        }

        foreach ( array(
            'layoutSizingHorizontal' => 'sizing_horizontal',
            'layoutSizingVertical' => 'sizing_vertical',
            'horizontalSizing' => 'sizing_horizontal',
            'verticalSizing' => 'sizing_vertical',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        foreach ( array(
            'primaryAxisSizingMode' => 'primary_axis_sizing',
            'counterAxisSizingMode' => 'counter_axis_sizing',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        foreach ( array(
            'primaryAxisAlignItems' => 'primary_axis_alignment',
            'counterAxisAlignItems' => 'counter_axis_alignment',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        if ( isset($layout['primary_axis_alignment']) ) {
            $layout['justify_content'] = $this->cssAxisAlignment((string) $layout['primary_axis_alignment']);
        }

        if ( isset($layout['counter_axis_alignment']) ) {
            $layout['align_items'] = $this->cssAxisAlignment((string) $layout['counter_axis_alignment']);
        }

        $padding = array();
        foreach ( array('top' => 'paddingTop', 'right' => 'paddingRight', 'bottom' => 'paddingBottom', 'left' => 'paddingLeft') as $edge => $source ) {
            if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                $padding[$edge] = (float) $node[$source];
            }
        }
        foreach ( array('left', 'right') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingHorizontal']) && is_numeric($node['paddingHorizontal']) ) {
                $padding[$edge] = (float) $node['paddingHorizontal'];
            }
        }
        foreach ( array('top', 'bottom') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingVertical']) && is_numeric($node['paddingVertical']) ) {
                $padding[$edge] = (float) $node['paddingVertical'];
            }
        }
        if ( ! empty($padding) ) {
            $layout['padding'] = $padding;
        }

        if ( isset($node['itemSpacing']) && is_numeric($node['itemSpacing']) ) {
            $layout['item_spacing'] = (float) $node['itemSpacing'];
        }

        if ( isset($node['layoutWrap']) && is_scalar($node['layoutWrap']) ) {
            $layout['wrap'] = strtoupper((string) $node['layoutWrap']);
            if ( 'WRAP' === $layout['wrap'] ) {
                $layout['flex_wrap'] = 'wrap';
            }
        }

        if ( isset($node['layoutPositioning']) && 'ABSOLUTE' === strtoupper((string) $node['layoutPositioning']) ) {
            $layout['positioning'] = 'absolute';
        }

        if ( isset($node['layoutGrow']) && is_numeric($node['layoutGrow']) ) {
            $layout['grow'] = (float) $node['layoutGrow'];
        }

        if ( isset($node['layoutAlign']) && is_scalar($node['layoutAlign']) ) {
            $layout['align'] = strtoupper((string) $node['layoutAlign']);
        }

        if ( true === ($node['clipsContent'] ?? false) ) {
            $layout['clips_content'] = true;
        }

        if ( is_array($node['constraints'] ?? null) ) {
            $constraints = array();
            foreach ( array('horizontal', 'vertical') as $axis ) {
                if ( isset($node['constraints'][$axis]) && is_scalar($node['constraints'][$axis]) ) {
                    $constraints[$axis] = strtoupper((string) $node['constraints'][$axis]);
                }
            }
            if ( ! empty($constraints) ) {
                $layout['constraints'] = $constraints;
            }
        }

        return $layout;
    }

    private function cssAxisAlignment(string $alignment): ?string
    {
        return match ( strtoupper($alignment) ) {
            'MIN' => 'flex-start',
            'CENTER' => 'center',
            'MAX' => 'flex-end',
            'SPACE_BETWEEN' => 'space-between',
            'BASELINE' => 'baseline',
            'STRETCH' => 'stretch',
            default => null,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, array<string, string>>
     */
    private function buildTextInventory(array $nodeMap): array
    {
        $inventory = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $text = null;
            foreach ( array('characters', 'text', 'name') as $key ) {
                if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                    $text = (string) $node[$key];
                    break;
                }
            }

            $inventory[] = array(
                'id'   => $id,
                'name' => (string) ($node['name'] ?? ''),
                'text' => $text ?? '',
            );
        }

        return $inventory;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, array<string, string>>
     */
    private function buildAssetReferences(array $nodeMap): array
    {
        $references = array();

        foreach ( $nodeMap as $id => $node ) {
            foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef') as $assetKey ) {
                if ( isset($node[$assetKey]) && is_scalar($node[$assetKey]) && '' !== (string) $node[$assetKey] ) {
                    $references[] = array(
                        'node_id' => $id,
                        'paint'   => $assetKey,
                        'ref'     => (string) $node[$assetKey],
                    );
                    break;
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

                    $reference = $this->readImageReference($paint);
                    if ( null !== $reference ) {
                        $references[] = array(
                            'node_id'    => $id,
                            'paint'      => $paintKey,
                            'source_key' => $reference['source_key'],
                            'ref'        => $reference['ref'],
                        );
                    }
                }
            }
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{source_key: string, ref: string}|null
     */
    private function readImageReference(array $paint): ?array
    {
        foreach ( array('imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                return array(
                    'source_key' => $key,
                    'ref'        => (string) $paint[$key],
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array<string, mixed>> $renderNodes
     */
    private function readSourceName(array $source, array $renderNodes): string
    {
        if ( isset($source['name']) && is_scalar($source['name']) && '' !== (string) $source['name'] ) {
            return (string) $source['name'];
        }

        if ( isset($renderNodes[0]['name']) && is_scalar($renderNodes[0]['name']) && '' !== (string) $renderNodes[0]['name'] ) {
            return (string) $renderNodes[0]['name'];
        }

        return 'Figma Site';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function detectInputShape(array $source): string
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
            if ( isset($source[$key]) ) {
                return $key;
            }
        }

        return 'unknown';
    }
}

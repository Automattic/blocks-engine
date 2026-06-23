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
        $blobs       = is_array($source['blobs'] ?? null) ? $source['blobs'] : array();
        $paintStyles = $this->buildPaintStyleDefinitions($index['nodes'], $diagnostics);
        $nodeMap     = $this->normalizeNodeMap($index['nodes'], $diagnostics, $blobs, $paintStyles);
        $components  = $this->buildComponentDefinitions($nodeMap);
        $componentDefinitionCount = $this->countComponentDefinitions($nodeMap);
        $instanceReport = $this->resolveInstances($nodeMap, $components, $diagnostics);
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
        if ( null !== $selectedFrameId && 1 === count($topLevelIds) && $selectedFrameId !== $topLevelIds[0] ) {
            $renderIds = array($selectedFrameId);
        }
        $renderNodes = array();
        foreach ( $renderIds as $id ) {
            if ( isset($nodeMap[$id]) ) {
                $renderNodes[] = $this->refreshResolvedTree($nodeMap[$id], $nodeMap);
            }
        }

        $textInventory   = $this->buildTextInventory($nodeMap);
        $assetReferences = $this->buildAssetReferences($nodeMap);
        $sourceName      = $this->readSourceName($source, $renderNodes);

        return array(
            'schema'              => 'blocks-engine/figma-transformer/scenegraph/v1',
            'name'                => $sourceName,
            'nodes'               => $renderNodes,
            'assets'              => is_array($source['assets'] ?? null) ? $source['assets'] : array(),
            'figma_blobs'         => $blobs,
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
                'component_definition_count' => $componentDefinitionCount,
                'instance_node_count'   => $instanceReport['instance_node_count'],
                'resolved_instance_count' => $instanceReport['resolved_instance_count'],
                'unresolved_component_references' => $instanceReport['unresolved_component_references'],
                'diagnostic_count'      => count($diagnostics),
            ),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>>    $diagnostics
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodeMap(array $nodeMap, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        foreach ( $nodeMap as $id => $node ) {
            $nodeMap[$id] = $this->normalizeNode($node, $diagnostics, $blobs, $paintStyles);
        }

        return $nodeMap;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node, array &$diagnostics, array $blobs = array(), array $paintStyles = array()): array
    {
        $id = (string) ($node['id'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? ''));

        $component = $this->normalizeComponentMetadata($node, $type);
        if ( ! empty($component) ) {
            $node['figma_component'] = $component;
        }

        if ( 'TEXT' === $type ) {
            $text = $this->normalizeText($node);
            if ( ! empty($text) ) {
                $node['figma_text'] = $text;
            }
        }

        $paints = $this->normalizePaintCollections($node, $id, $diagnostics, $paintStyles);
        if ( ! empty($paints) ) {
            $node['figma_paints'] = $paints;
        }

        $vectorPaths = $this->normalizeVectorPaths($node, $blobs, $id, $diagnostics);
        if ( ! empty($vectorPaths) ) {
            $node['figma_vector_paths'] = $vectorPaths;
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

        $effects = $this->normalizeEffects($node, $id, $diagnostics);
        if ( ! empty($effects) ) {
            $node['figma_effects'] = $effects;
        }

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $index => $child ) {
                if ( is_array($child) ) {
                    $normalizedChild = $this->normalizeNode($child, $diagnostics, $blobs, $paintStyles);
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
    private function normalizeComponentMetadata(array $node, string $type): array
    {
        $metadata = array();

        if ( in_array($type, array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
            $metadata['role'] = 'definition';
            $metadata['definition_id'] = (string) ($node['id'] ?? '');
        } elseif ( 'INSTANCE' === $type ) {
            $metadata['role'] = 'instance';
            $metadata['instance_id'] = (string) ($node['id'] ?? '');
            $reference = $this->readComponentReference($node);
            if ( null !== $reference ) {
                $metadata['component_id'] = $reference['id'];
                $metadata['component_source_key'] = $reference['source_key'];
            }
        }

        if ( is_array($node['componentProperties'] ?? null) ) {
            $metadata['component_properties'] = $node['componentProperties'];
        }

        if ( is_array($node['overrides'] ?? null) ) {
            $metadata['overrides'] = $node['overrides'];
        }

        return $metadata;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, array<string, mixed>>
     */
    private function buildComponentDefinitions(array $nodeMap): array
    {
        $components = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( ! in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                continue;
            }

            foreach ( array_unique(array_filter(array($id, $this->readString($node, array('componentId', 'component_id', 'key'))))) as $componentId ) {
                $components[(string) $componentId] = $node;
            }
        }

        return $components;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     */
    private function countComponentDefinitions(array $nodeMap): int
    {
        $count = 0;

        foreach ( $nodeMap as $node ) {
            if ( in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    private function buildPaintStyleDefinitions(array $nodeMap, array &$diagnostics): array
    {
        $styles = array();
        foreach ( $nodeMap as $id => $node ) {
            if ( 'FILL' !== strtoupper((string) ($node['styleType'] ?? '')) ) {
                continue;
            }

            $paints = $this->normalizePaintList(is_array($node['fillPaints'] ?? null) ? $node['fillPaints'] : (is_array($node['fills'] ?? null) ? $node['fills'] : array()), $id, 'style.fillPaints', $diagnostics);
            if ( ! empty($paints) ) {
                $styles[$id]['fills'] = $paints;
            }
        }

        return $styles;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array{instance_node_count: int, resolved_instance_count: int, unresolved_component_references: array<int, array<string, string>>}
     */
    private function resolveInstances(array &$nodeMap, array $components, array &$diagnostics): array
    {
        $instanceCount = 0;
        $resolvedCount = 0;
        $unresolved = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( 'INSTANCE' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $instanceCount++;
            $reference = $this->readComponentReference($node);
            if ( null === $reference || ! isset($components[$reference['id']]) ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id'] ?? '');
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_component_unresolved',
                    'message'  => 'Figma instance references a component definition that is not present in the same source graph.',
                    'context'  => array(
                        'instance_id'  => $id,
                        'component_id' => $reference['id'] ?? null,
                    ),
                );
                continue;
            }

            if ( ! empty($node['children']) ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id']);
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_resolution_skipped',
                    'message'  => 'Figma instance resolution was skipped because the source instance already contains children.',
                    'context'  => array('instance_id' => $id, 'component_id' => $reference['id']),
                );
                continue;
            }

            $overrides = $this->normalizeInstanceOverrides($node, $id, $diagnostics);
            if ( null === $overrides ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id']);
                continue;
            }

            $resolved = $this->cloneComponentForInstance($components[$reference['id']], $node, $reference['id'], $overrides, $nodeMap);
            $nodeMap[$id] = $resolved;
            $resolvedCount++;
        }

        return array(
            'instance_node_count' => $instanceCount,
            'resolved_instance_count' => $resolvedCount,
            'unresolved_component_references' => $unresolved,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, string> $trail
     * @return array<string, mixed>
     */
    private function refreshResolvedTree(array $node, array $nodeMap, array $trail = array()): array
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' !== $id && isset($nodeMap[$id]) && ! in_array($id, $trail, true) ) {
            $node = $nodeMap[$id];
            $trail[] = $id;
        }

        if ( true === ($node['figma_component']['resolved'] ?? false) ) {
            return $node;
        }

        if ( ! is_array($node['children'] ?? null) ) {
            return $node;
        }

        foreach ( $node['children'] as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $node['children'][$index] = $this->refreshResolvedTree($child, $nodeMap, $trail);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{id: string, source_key: string}|null
     */
    private function readComponentReference(array $node): ?array
    {
        foreach ( array('componentId', 'component_id', 'mainComponentId', 'main_component_id') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return array('id' => (string) $node[$key], 'source_key' => $key);
            }
        }

        foreach ( array('mainComponent', 'component') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $id = $this->readString($node[$key], array('id', 'key', 'componentId', 'node_id', 'nodeId'));
                if ( null !== $id && '' !== $id ) {
                    return array('id' => $id, 'source_key' => $key);
                }
            } elseif ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return array('id' => (string) $node[$key], 'source_key' => $key);
            }
        }

        $symbolId = $this->readGuidId($node['symbolData']['symbolID'] ?? null);
        if ( null !== $symbolId ) {
            return array('id' => $symbolId, 'source_key' => 'symbolData.symbolID');
        }

        return null;
    }

    private function readGuidId(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        if ( is_scalar($guid) && '' !== (string) $guid ) {
            return (string) $guid;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $keys
     */
    private function readString(array $node, array $keys): ?string
    {
        foreach ( $keys as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, mixed>>|null
     */
    private function normalizeInstanceOverrides(array $node, string $instanceId, array &$diagnostics): ?array
    {
        $rawOverrides = array();
        if ( is_array($node['overrides'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['overrides']);
        }
        if ( is_array($node['symbolData']['symbolOverrides'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['symbolData']['symbolOverrides']);
        }

        if ( empty($rawOverrides) ) {
            return array();
        }

        $overrides = array();
        foreach ( $rawOverrides as $key => $override ) {
            if ( ! is_array($override) ) {
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_override_unsupported',
                    'message'  => 'Figma instance override shape is unsupported and was not applied.',
                    'context'  => array('instance_id' => $instanceId),
                );
                return null;
            }

            $nodeId = $this->readString($override, array('nodeId', 'node_id', 'id')) ?? $this->readOverrideGuidPathTarget($override) ?? (is_string($key) ? $key : null);
            if ( null === $nodeId || '' === $nodeId ) {
                return null;
            }

            foreach ( array('characters', 'text', 'name') as $field ) {
                if ( isset($override[$field]) && is_scalar($override[$field]) ) {
                    $overrides[$nodeId][$field] = $override[$field];
                }
            }
            if ( isset($override['textData']['characters']) && is_scalar($override['textData']['characters']) ) {
                $overrides[$nodeId]['characters'] = (string) $override['textData']['characters'];
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function readOverrideGuidPathTarget(array $override): ?string
    {
        $guidPath = $override['guidPath'] ?? null;
        if ( ! is_array($guidPath) ) {
            return null;
        }

        $guids = is_array($guidPath['guids'] ?? null) ? $guidPath['guids'] : $guidPath;
        $last = end($guids);
        if ( false === $last ) {
            return null;
        }

        return $this->readGuidId($last);
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $instance
     * @param array<string, array<string, mixed>> $overrides
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, mixed>
     */
    private function cloneComponentForInstance(array $component, array $instance, string $componentId, array $overrides, array $nodeMap): array
    {
        $resolved = $component;
        $resolved['id'] = (string) ($instance['id'] ?? $resolved['id'] ?? '');
        $resolved['type'] = 'INSTANCE';
        $resolved['name'] = (string) ($instance['name'] ?? $resolved['name'] ?? '');

        foreach ( array('box', 'figma_box', 'layout', 'figma_paints', 'figma_effects', 'figma_vector_paths', 'componentProperties', 'fillPaints', 'effects', 'styleIdForFill', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd') as $key ) {
            if ( array_key_exists($key, $instance) ) {
                $resolved[$key] = $instance[$key];
            }
        }

        $resolved['figma_component'] = array_merge(
            is_array($instance['figma_component'] ?? null) ? $instance['figma_component'] : array(),
            array(
                'role'               => 'instance',
                'instance_id'        => (string) ($instance['id'] ?? ''),
                'component_id'       => $componentId,
                'definition_node_id' => (string) ($component['id'] ?? ''),
                'resolved'           => true,
            )
        );
        $resolvedChildren = is_array($resolved['children'] ?? null) ? $resolved['children'] : array();
        $resolvedChildren = $this->resolveClonedInstanceChildren($resolvedChildren, $nodeMap);
        $resolvedChildren = $this->scaleVectorOnlyInstanceChildren($resolvedChildren, $component, $instance);
        $resolved['children'] = $this->namespaceResolvedInstanceChildren(
            $this->applyInstanceOverridesToChildren($resolvedChildren, $overrides),
            (string) ($instance['id'] ?? '')
        );

        return $resolved;
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, mixed>
     */
    private function resolveClonedInstanceChildren(array $children, array $nodeMap): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $id = (string) ($child['id'] ?? '');
            if ( 'INSTANCE' === strtoupper((string) ($child['type'] ?? '')) && '' !== $id && isset($nodeMap[$id]) ) {
                $child = $nodeMap[$id];
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->resolveClonedInstanceChildren($child['children'], $nodeMap);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function scaleVectorOnlyInstanceChildren(array $children, array $component, array $instance): array
    {
        if ( ! $this->isVectorOnlyComponent($component) ) {
            return $children;
        }

        $componentBox = is_array($component['box'] ?? null) ? $component['box'] : array();
        $instanceBox  = is_array($instance['box'] ?? null) ? $instance['box'] : array();
        $componentWidth = isset($componentBox['width']) && is_numeric($componentBox['width']) ? (float) $componentBox['width'] : 0.0;
        $componentHeight = isset($componentBox['height']) && is_numeric($componentBox['height']) ? (float) $componentBox['height'] : 0.0;
        $instanceWidth = isset($instanceBox['width']) && is_numeric($instanceBox['width']) ? (float) $instanceBox['width'] : 0.0;
        $instanceHeight = isset($instanceBox['height']) && is_numeric($instanceBox['height']) ? (float) $instanceBox['height'] : 0.0;
        if ( $componentWidth <= 0.0 || $componentHeight <= 0.0 || $instanceWidth <= 0.0 || $instanceHeight <= 0.0 ) {
            return $children;
        }

        $scaleX = $instanceWidth / $componentWidth;
        $scaleY = $instanceHeight / $componentHeight;
        if ( abs($scaleX - 1.0) < 0.0001 && abs($scaleY - 1.0) < 0.0001 ) {
            return $children;
        }

        return $this->scaleVectorChildren($children, $scaleX, $scaleY);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function scaleVectorChildren(array $children, float $scaleX, float $scaleY): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            if ( is_array($child['box'] ?? null) ) {
                foreach ( array('x' => $scaleX, 'width' => $scaleX, 'y' => $scaleY, 'height' => $scaleY) as $key => $scale ) {
                    if ( isset($child['box'][$key]) && is_numeric($child['box'][$key]) ) {
                        $child['box'][$key] = (float) $child['box'][$key] * $scale;
                    }
                }
            }

            if ( is_array($child['figma_box']['transform'] ?? null) ) {
                foreach ( array('m00' => $scaleX, 'm02' => $scaleX, 'm11' => $scaleY, 'm12' => $scaleY) as $key => $scale ) {
                    if ( isset($child['figma_box']['transform'][$key]) && is_numeric($child['figma_box']['transform'][$key]) ) {
                        $child['figma_box']['transform'][$key] = (float) $child['figma_box']['transform'][$key] * $scale;
                    }
                }
            }

            if ( in_array(strtoupper((string) ($child['type'] ?? '')), array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE'), true) ) {
                $child['figma_vector_scale'] = array('x' => $scaleX, 'y' => $scaleY);
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->scaleVectorChildren($child['children'], $scaleX, $scaleY);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $component
     */
    private function isVectorOnlyComponent(array $component): bool
    {
        if ( ! empty($component['layout']) ) {
            return false;
        }

        $children = is_array($component['children'] ?? null) ? $component['children'] : array();
        if ( empty($children) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isVectorOnlyNode(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'INSTANCE'), true) ) {
            return false;
        }

        foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function namespaceResolvedInstanceChildren(array $children, string $instanceId): array
    {
        if ( '' === $instanceId ) {
            return $children;
        }

        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $sourceId = (string) ($child['id'] ?? '');
            if ( '' !== $sourceId && ! str_starts_with($sourceId, $instanceId . '/') ) {
                $child['figma_component_source_id'] = $sourceId;
                $child['id'] = $instanceId . '/' . $sourceId;
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->namespaceResolvedInstanceChildren($child['children'], $instanceId);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $overrides
     * @return array<int, mixed>
     */
    private function applyInstanceOverridesToChildren(array $children, array $overrides): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $id = (string) ($child['id'] ?? '');
            foreach ( $overrides[$id] ?? array() as $field => $value ) {
                $child[$field] = $value;
                if ( in_array($field, array('characters', 'text'), true) && is_array($child['figma_text'] ?? null) ) {
                    $child['figma_text']['characters'] = (string) $value;
                }
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->applyInstanceOverridesToChildren($child['children'], $overrides);
            }

            $children[$index] = $child;
        }

        return $children;
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

        if ( ! isset($text['characters']) && isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ) {
            $text['characters'] = (string) $node['textData']['characters'];
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

        $derivedLayout = $this->normalizeDerivedTextLayout($node);
        if ( ! empty($derivedLayout) ) {
            $text['derived_layout'] = $derivedLayout;
        }

        $segments = $this->normalizeStyledTextSegments($node);
        if ( ! empty($segments) ) {
            $text['segments'] = $segments;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeDerivedTextLayout(array $node): array
    {
        $source = is_array($node['derivedTextData'] ?? null) ? $node['derivedTextData'] : array();
        if ( empty($source) ) {
            return array();
        }

        $layout = array();
        if ( is_array($source['layoutSize'] ?? null) ) {
            $size = array();
            foreach ( array('x' => 'width', 'y' => 'height', 'width' => 'width', 'height' => 'height') as $sourceKey => $targetKey ) {
                if ( ! isset($size[$targetKey]) && isset($source['layoutSize'][$sourceKey]) && is_numeric($source['layoutSize'][$sourceKey]) ) {
                    $size[$targetKey] = (float) $source['layoutSize'][$sourceKey];
                }
            }
            if ( ! empty($size) ) {
                $layout['size'] = $size;
            }
        }

        if ( is_array($source['baselines'] ?? null) ) {
            $layout['baseline_count'] = count($source['baselines']);
            $baselines = array();
            foreach ( $source['baselines'] as $baseline ) {
                if ( ! is_array($baseline) ) {
                    continue;
                }
                $normalized = array();
                foreach ( array('width', 'lineY', 'lineHeight', 'lineAscent', 'firstCharacter', 'endCharacter') as $key ) {
                    if ( isset($baseline[$key]) && is_numeric($baseline[$key]) ) {
                        $normalized[$key] = (float) $baseline[$key];
                    }
                }
                if ( is_array($baseline['position'] ?? null) ) {
                    foreach ( array('x', 'y') as $axis ) {
                        if ( isset($baseline['position'][$axis]) && is_numeric($baseline['position'][$axis]) ) {
                            $normalized['position_' . $axis] = (float) $baseline['position'][$axis];
                        }
                    }
                }
                if ( ! empty($normalized) ) {
                    $baselines[] = $normalized;
                }
            }
            if ( ! empty($baselines) ) {
                $layout['baselines'] = $baselines;
            }
        }

        if ( is_array($source['glyphs'] ?? null) ) {
            $layout['glyph_count'] = count($source['glyphs']);
        }
        if ( is_array($source['fontMetaData'] ?? null) ) {
            $fonts = array();
            foreach ( $source['fontMetaData'] as $font ) {
                if ( ! is_array($font) ) {
                    continue;
                }
                $fonts[] = array(
                    'family' => (string) ($font['key']['family'] ?? ''),
                    'style' => (string) ($font['key']['style'] ?? ''),
                    'font_weight' => isset($font['fontWeight']) && is_numeric($font['fontWeight']) ? (int) $font['fontWeight'] : null,
                    'font_line_height' => isset($font['fontLineHeight']) && is_numeric($font['fontLineHeight']) ? (float) $font['fontLineHeight'] : null,
                );
            }
            if ( ! empty($fonts) ) {
                $layout['fonts'] = $fonts;
            }
        }

        return $layout;
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

        if ( isset($source['fontName']) && is_array($source['fontName']) ) {
            if ( isset($source['fontName']['family']) && is_scalar($source['fontName']['family']) ) {
                $style['font_family'] = (string) $source['fontName']['family'];
            }
            if ( isset($source['fontName']['postscript']) && is_scalar($source['fontName']['postscript']) ) {
                $style['font_postscript_name'] = (string) $source['fontName']['postscript'];
            }
            if ( ! isset($style['font_weight']) && isset($source['fontName']['style']) && is_scalar($source['fontName']['style']) ) {
                $fontWeight = $this->fontWeightFromStyle((string) $source['fontName']['style']);
                if ( null !== $fontWeight ) {
                    $style['font_weight'] = $fontWeight;
                }
            }
        }

        foreach ( array('fontSize' => 'font_size', 'lineHeightPx' => 'line_height_px', 'lineHeightPercent' => 'line_height_percent', 'letterSpacing' => 'letter_spacing') as $sourceKey => $targetKey ) {
            if ( isset($source[$sourceKey]) && is_numeric($source[$sourceKey]) ) {
                $style[$targetKey] = (float) $source[$sourceKey];
            }
        }

        if ( isset($source['lineHeight']) && is_array($source['lineHeight']) && isset($source['lineHeight']['value']) && is_numeric($source['lineHeight']['value']) ) {
            $lineHeightUnits = strtoupper((string) ($source['lineHeight']['units'] ?? ''));
            if ( 'PIXELS' === $lineHeightUnits ) {
                $style['line_height_px'] = (float) $source['lineHeight']['value'];
            } elseif ( 'RAW' === $lineHeightUnits ) {
                $style['line_height_raw'] = (float) $source['lineHeight']['value'];
            } elseif ( str_contains($lineHeightUnits, 'PERCENT') ) {
                $style['line_height_percent'] = (float) $source['lineHeight']['value'];
            }
        }

        if ( isset($source['letterSpacing']) && is_array($source['letterSpacing']) && isset($source['letterSpacing']['value']) && is_numeric($source['letterSpacing']['value']) ) {
            $style['letter_spacing'] = (float) $source['letterSpacing']['value'];
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

    private function fontWeightFromStyle(string $style): ?int
    {
        $style = strtolower(str_replace(array('-', '_'), ' ', $style));
        if ( str_contains($style, 'thin') ) {
            return 100;
        }
        if ( str_contains($style, 'extra light') || str_contains($style, 'ultra light') ) {
            return 200;
        }
        if ( str_contains($style, 'light') ) {
            return 300;
        }
        if ( str_contains($style, 'regular') || str_contains($style, 'normal') ) {
            return 400;
        }
        if ( str_contains($style, 'medium') ) {
            return 500;
        }
        if ( str_contains($style, 'semi bold') || str_contains($style, 'semibold') || str_contains($style, 'demi bold') ) {
            return 600;
        }
        if ( str_contains($style, 'extra bold') || str_contains($style, 'ultra bold') ) {
            return 800;
        }
        if ( str_contains($style, 'bold') ) {
            return 700;
        }
        if ( str_contains($style, 'black') || str_contains($style, 'heavy') ) {
            return 900;
        }

        return null;
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
    private function normalizePaintCollections(array $node, string $nodeId, array &$diagnostics, array $paintStyles = array()): array
    {
        $collections = array();
        foreach ( array('fills' => 'fills', 'fillPaints' => 'fills', 'strokes' => 'strokes', 'strokePaints' => 'strokes', 'background' => 'background') as $sourceKey => $targetKey ) {
            if ( ! is_array($node[$sourceKey] ?? null) ) {
                continue;
            }

            $paints = $this->normalizePaintList($node[$sourceKey], $nodeId, $sourceKey, $diagnostics);

            if ( ! empty($paints) ) {
                $collections[$targetKey] = $paints;
            }
        }

        $styleFillId = $this->readStyleGuidId($node['styleIdForFill'] ?? null);
        if ( null !== $styleFillId && ! empty($paintStyles[$styleFillId]['fills']) ) {
            $collections['fills'] = $paintStyles[$styleFillId]['fills'];
        }

        $styleStrokeId = $this->readStyleGuidId($node['styleIdForStrokeFill'] ?? $node['styleIdForStroke'] ?? null);
        if ( null !== $styleStrokeId && ! empty($paintStyles[$styleStrokeId]['fills']) ) {
            $collections['strokes'] = $paintStyles[$styleStrokeId]['fills'];
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
     * @param array<int, mixed> $paints
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function normalizePaintList(array $paints, string $nodeId, string $paintKey, array &$diagnostics): array
    {
        $normalizedPaints = array();
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) ) {
                continue;
            }

            $normalized = $this->normalizePaint($paint, $nodeId, $paintKey, $diagnostics);
            if ( ! empty($normalized) ) {
                $normalizedPaints[] = $normalized;
            }
        }

        return $normalizedPaints;
    }

    private function readStyleGuidId(mixed $style): ?string
    {
        if ( is_array($style) && isset($style['guid']) ) {
            return $this->readGuidId($style['guid']);
        }

        return $this->readGuidId($style);
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
            $normalized = array('type' => 'IMAGE');
            $ref = $paint['imageRef'] ?? $paint['imageHash'] ?? $paint['ref'] ?? null;
            if ( is_scalar($ref) && '' !== (string) $ref ) {
                $normalized['ref'] = $this->normalizeImageHash((string) $ref);
            }

            if ( is_array($paint['image'] ?? null) ) {
                $imageRef = $this->readNestedImageHash($paint['image']);
                if ( null !== $imageRef ) {
                    $normalized['ref'] = $imageRef;
                    $normalized['imageHash'] = $imageRef;
                }
                if ( isset($paint['image']['name']) && is_scalar($paint['image']['name']) ) {
                    $normalized['imageName'] = (string) $paint['image']['name'];
                }
            }

            if ( is_array($paint['imageThumbnail'] ?? null) ) {
                $thumbnailRef = $this->readNestedImageHash($paint['imageThumbnail']);
                if ( null !== $thumbnailRef ) {
                    $normalized['thumbnailRef'] = $thumbnailRef;
                    $normalized['thumbnailHash'] = $thumbnailRef;
                }
                if ( isset($paint['imageThumbnail']['name']) && is_scalar($paint['imageThumbnail']['name']) ) {
                    $normalized['thumbnailName'] = (string) $paint['imageThumbnail']['name'];
                }
            }

            foreach ( array('imageScaleMode', 'scaleMode', 'altText') as $key ) {
                if ( isset($paint[$key]) && is_scalar($paint[$key]) ) {
                    $normalized[$key] = (string) $paint[$key];
                }
            }
            foreach ( array('originalImageWidth', 'originalImageHeight', 'scale', 'rotation', 'opacity') as $key ) {
                if ( isset($paint[$key]) && is_numeric($paint[$key]) ) {
                    $normalized[$key] = (float) $paint[$key];
                }
            }
            if ( isset($paint['imageShouldColorManage']) && is_bool($paint['imageShouldColorManage']) ) {
                $normalized['imageShouldColorManage'] = $paint['imageShouldColorManage'];
            }
            foreach ( array('transform', 'imageTransform') as $transformKey ) {
                if ( is_array($paint[$transformKey] ?? null) ) {
                    $normalized['transform'] = $paint[$transformKey];
                    break;
                }
            }

            return $normalized;
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

    private function readNestedImageHash(array $image): ?string
    {
        if ( ! isset($image['hash']) || ! is_scalar($image['hash']) || '' === (string) $image['hash'] ) {
            return null;
        }

        return $this->normalizeImageHash((string) $image['hash']);
    }

    private function normalizeImageHash(string $hash): string
    {
        if ( 1 === preg_match('/^[a-f0-9]{40}$/i', $hash) ) {
            return strtolower($hash);
        }

        if ( 20 === strlen($hash) ) {
            return bin2hex($hash);
        }

        return $hash;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVectorPaths(array $node, array $blobs, string $nodeId, array &$diagnostics): array
    {
        $paths = array();
        foreach ( array('fillGeometry', 'strokeGeometry') as $geometryKey ) {
            if ( ! is_array($node[$geometryKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$geometryKey] as $geometry ) {
                if ( ! is_array($geometry) || ! isset($geometry['commandsBlob']) ) {
                    continue;
                }

                $bytes = $this->readCommandBlobBytes($geometry['commandsBlob'], $blobs);
                if ( null === $bytes ) {
                    continue;
                }

                $path = $this->decodeVectorCommandBlob($bytes);
                if ( null === $path ) {
                    $diagnostics[] = array(
                        'severity' => 'warning',
                        'code'     => 'unsupported_vector_command_blob',
                        'message'  => 'Unsupported Figma vector command blob was omitted from SVG output.',
                        'context'  => array('node_id' => $nodeId, 'geometry' => $geometryKey),
                    );
                    continue;
                }

                $normalized = array('data' => $path, 'source' => $geometryKey);
                if ( isset($geometry['windingRule']) && is_scalar($geometry['windingRule']) ) {
                    $normalized['windingRule'] = (string) $geometry['windingRule'];
                }
                $paths[] = $normalized;
            }
        }

        return $paths;
    }

    /**
     * @param array<int|string, mixed> $blobs
     */
    private function readCommandBlobBytes(mixed $commandsBlob, array $blobs): ?string
    {
        if ( is_array($commandsBlob) && isset($commandsBlob['bytes']) && is_scalar($commandsBlob['bytes']) ) {
            return (string) $commandsBlob['bytes'];
        }

        if ( is_numeric($commandsBlob) ) {
            $blob = $blobs[(int) $commandsBlob] ?? null;
            if ( is_array($blob) && isset($blob['bytes']) && is_scalar($blob['bytes']) ) {
                return (string) $blob['bytes'];
            }
            if ( is_scalar($blob) ) {
                return (string) $blob;
            }
        }

        if ( is_string($commandsBlob) ) {
            return $commandsBlob;
        }

        return null;
    }

    private function decodeVectorCommandBlob(string $bytes): ?string
    {
        $offset = 0;
        $length = strlen($bytes);
        $parts = array();

        while ( $offset < $length ) {
            $opcode = ord($bytes[$offset]);
            $offset++;

            if ( 0 === $opcode ) {
                $parts[] = 'Z';
                continue;
            }

            if ( 1 === $opcode || 2 === $opcode ) {
                $point = $this->readFloatPair($bytes, $offset);
                if ( null === $point ) {
                    return null;
                }
                $parts[] = ( 1 === $opcode ? 'M ' : 'L ' ) . $this->svgNumber($point[0]) . ' ' . $this->svgNumber($point[1]);
                $offset += 8;
                continue;
            }

            if ( 4 === $opcode ) {
                $points = array();
                for ( $i = 0; $i < 3; $i++ ) {
                    $point = $this->readFloatPair($bytes, $offset + ( $i * 8 ));
                    if ( null === $point ) {
                        return null;
                    }
                    $points[] = $point;
                }
                $parts[] = 'C ' . $this->svgNumber($points[0][0]) . ' ' . $this->svgNumber($points[0][1]) . ' ' . $this->svgNumber($points[1][0]) . ' ' . $this->svgNumber($points[1][1]) . ' ' . $this->svgNumber($points[2][0]) . ' ' . $this->svgNumber($points[2][1]);
                $offset += 24;
                continue;
            }

            return null;
        }

        return empty($parts) ? null : implode(' ', $parts);
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function readFloatPair(string $bytes, int $offset): ?array
    {
        if ( strlen($bytes) < $offset + 8 ) {
            return null;
        }

        $x = unpack('g', substr($bytes, $offset, 4));
        $y = unpack('g', substr($bytes, $offset + 4, 4));
        if ( false === $x || false === $y ) {
            return null;
        }

        return array((float) $x[1], (float) $y[1]);
    }

    private function svgNumber(float $value): string
    {
        $number = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        return '' === $number || '-0' === $number ? '0' : $number;
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

        foreach ( array('relativeTransform', 'absoluteTransform', 'transform') as $sourceKey ) {
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
    private function normalizeEffects(array $node, string $nodeId, array &$diagnostics): array
    {
        if ( ! is_array($node['effects'] ?? null) ) {
            return array();
        }

        $effects = array();
        foreach ( $node['effects'] as $effect ) {
            if ( ! is_array($effect) || false === ($effect['visible'] ?? true) ) {
                continue;
            }

            $type = strtoupper((string) ($effect['type'] ?? 'UNKNOWN'));
            if ( in_array($type, array('DROP_SHADOW', 'INNER_SHADOW'), true) ) {
                $normalized = array(
                    'type' => 'DROP_SHADOW' === $type ? 'drop_shadow' : 'inner_shadow',
                    'offset_x' => is_numeric($effect['offset']['x'] ?? null) ? (float) $effect['offset']['x'] : 0.0,
                    'offset_y' => is_numeric($effect['offset']['y'] ?? null) ? (float) $effect['offset']['y'] : 0.0,
                    'radius' => is_numeric($effect['radius'] ?? null) ? (float) $effect['radius'] : 0.0,
                    'spread' => is_numeric($effect['spread'] ?? null) ? (float) $effect['spread'] : 0.0,
                );
                $color = $this->normalizeColor($effect['color'] ?? null);
                if ( null !== $color ) {
                    $normalized['color'] = $color;
                }
                if ( isset($effect['blendMode']) && is_scalar($effect['blendMode']) ) {
                    $normalized['blend_mode'] = (string) $effect['blendMode'];
                }
                $effects[] = $normalized;
                continue;
            }

            if ( in_array($type, array('LAYER_BLUR', 'BACKGROUND_BLUR'), true) ) {
                $effects[] = array(
                    'type' => 'LAYER_BLUR' === $type ? 'layer_blur' : 'background_blur',
                    'radius' => is_numeric($effect['radius'] ?? null) ? (float) $effect['radius'] : 0.0,
                );
                continue;
            }

            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'unsupported_figma_effect_type',
                'message'  => 'Unsupported Figma effect was omitted from static CSS.',
                'context'  => array(
                    'node_id' => $nodeId,
                    'type'    => $type,
                ),
            );
        }

        return $effects;
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

        if ( ! empty($frameIds) ) {
            return $frameIds;
        }

        foreach ( $topLevelIds as $id ) {
            foreach ( $this->collectFrameDescendantIds($nodeMap[$id]['children'] ?? array()) as $frameId ) {
                $frameIds[] = $frameId;
            }
        }

        return $frameIds;
    }

    /**
     * @param mixed $children
     * @return array<int, string>
     */
    private function collectFrameDescendantIds(mixed $children): array
    {
        if ( ! is_array($children) ) {
            return array();
        }

        $frameIds = array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $type = strtoupper((string) ($child['type'] ?? ''));
            if ( in_array($type, array('DOCUMENT', 'CANVAS'), true) ) {
                if ( 'CANVAS' === $type && (false === ($child['visible'] ?? true) || true === ($child['internalOnly'] ?? false)) ) {
                    continue;
                }

                foreach ( $this->collectFrameDescendantIds($child['children'] ?? array()) as $frameId ) {
                    $frameIds[] = $frameId;
                }
                continue;
            }

            if ( in_array($type, array('FRAME', 'COMPONENT', 'INSTANCE'), true) ) {
                $frameIds[] = (string) ($child['id'] ?? '');
                continue;
            }
        }

        return array_values(array_filter(array_unique($frameIds)));
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<string, array<string, mixed>>
     */
    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLayoutBox(array $node): array
    {
        $box = array();
        $coordinateSpace = null;

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $boundsKey ) {
            if ( ! is_array($node[$boundsKey] ?? null) ) {
                continue;
            }

            foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
                if ( ! array_key_exists($dimension, $box) && isset($node[$boundsKey][$dimension]) && is_numeric($node[$boundsKey][$dimension]) ) {
                    $box[$dimension] = (float) $node[$boundsKey][$dimension];
                }
            }

            if ( isset($node[$boundsKey]['x']) || isset($node[$boundsKey]['y']) ) {
                $coordinateSpace = 'absolute';
            }
        }

        foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                $box[$dimension] = (float) $node[$dimension];
                if ( 'x' === $dimension || 'y' === $dimension ) {
                    $coordinateSpace = 'local';
                }
            }
        }

        if ( is_array($node['size'] ?? null) ) {
            foreach ( array('x' => 'width', 'y' => 'height') as $source => $target ) {
                if ( ! array_key_exists($target, $box) && isset($node['size'][$source]) && is_numeric($node['size'][$source]) ) {
                    $box[$target] = (float) $node['size'][$source];
                }
            }
        }

        if ( is_array($node['transform'] ?? null) ) {
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( ! array_key_exists($target, $box) && isset($node['transform'][$source]) && is_numeric($node['transform'][$source]) ) {
                    $box[$target] = (float) $node['transform'][$source];
                    $coordinateSpace = 'local';
                }
            }
        }

        if ( null !== $coordinateSpace ) {
            $box['coordinate_space'] = $coordinateSpace;
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
        } elseif ( isset($node['stackMode']) && is_scalar($node['stackMode']) ) {
            $mode = strtoupper((string) $node['stackMode']);
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
            'stackPrimaryAlignItems' => 'primary_axis_alignment',
            'stackCounterAlignItems' => 'counter_axis_alignment',
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
        foreach ( array('left' => 'stackPaddingLeft', 'right' => 'stackPaddingRight', 'top' => 'stackPaddingTop', 'bottom' => 'stackPaddingBottom') as $edge => $source ) {
            if ( ! array_key_exists($edge, $padding) && isset($node[$source]) && is_numeric($node[$source]) ) {
                $padding[$edge] = (float) $node[$source];
            }
        }
        foreach ( array('left', 'right') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingHorizontal']) && is_numeric($node['paddingHorizontal']) ) {
                $padding[$edge] = (float) $node['paddingHorizontal'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['stackHorizontalPadding']) && is_numeric($node['stackHorizontalPadding']) ) {
                $padding[$edge] = (float) $node['stackHorizontalPadding'];
            }
        }
        foreach ( array('top', 'bottom') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingVertical']) && is_numeric($node['paddingVertical']) ) {
                $padding[$edge] = (float) $node['paddingVertical'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['stackVerticalPadding']) && is_numeric($node['stackVerticalPadding']) ) {
                $padding[$edge] = (float) $node['stackVerticalPadding'];
            }
        }
        if ( ! empty($padding) ) {
            $layout['padding'] = $padding;
        }

        if ( isset($node['itemSpacing']) && is_numeric($node['itemSpacing']) ) {
            $layout['item_spacing'] = (float) $node['itemSpacing'];
        } elseif ( isset($node['stackSpacing']) && is_numeric($node['stackSpacing']) ) {
            $layout['item_spacing'] = (float) $node['stackSpacing'];
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
        } elseif ( isset($node['stackChildAlignSelf']) && is_scalar($node['stackChildAlignSelf']) ) {
            $layout['align'] = strtoupper((string) $node['stackChildAlignSelf']);
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

        if ( ! isset($layout['display']) && is_array($node['children'] ?? null) && count($node['children']) > 1 ) {
            $type = strtoupper((string) ($node['type'] ?? ''));
            if ( true === ($node['resizeToFit'] ?? false) || in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true) ) {
                $layout['freeform'] = true;
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
            foreach ( array('characters', 'text') as $key ) {
                if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                    $text = (string) $node[$key];
                    break;
                }
            }
            if ( null === $text && isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ) {
                $text = (string) $node['textData']['characters'];
            }
            if ( null === $text && isset($node['name']) && is_scalar($node['name']) ) {
                $text = (string) $node['name'];
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
        }

        $unique = array();
        foreach ( $references as $reference ) {
            $key = (string) ($reference['node_id'] ?? '') . '|' . (string) ($reference['paint'] ?? '') . '|' . (string) ($reference['ref'] ?? '');
            $unique[$key] = $reference;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{source_key: string, ref: string}|null
     */
    private function readImageReference(array $paint): ?array
    {
        foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
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

<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Resolves Figma component instance data into the normalized scenegraph shape.
 */
final class InstanceResolver
{
    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, mixed>>|null
     */
    public function normalizeInstanceOverrides(array $node, string $instanceId, array &$diagnostics): ?array
    {
        $rawOverrides = array();
        if ( is_array($node['overrides'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['overrides']);
        }
        if ( is_array($node['symbolData']['symbolOverrides'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['symbolData']['symbolOverrides']);
        }
        if ( is_array($node['derivedSymbolData'] ?? null) ) {
            $rawOverrides = array_merge($rawOverrides, $node['derivedSymbolData']);
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
            foreach ( array('derivedTextData', 'fontName', 'fontFamily', 'fontPostScriptName', 'fontWeight', 'fontSize', 'lineHeight', 'lineHeightPx', 'lineHeightPercent', 'letterSpacing', 'listSpacing', 'styleIdForText', 'size', 'relativeTransform', 'absoluteTransform', 'transform', 'fillPaints', 'fills', 'strokes', 'strokePaints', 'strokeWeight', 'strokeAlign', 'dashPattern', 'borderStrokeWeightsIndependent', 'borderTopWeight', 'borderBottomWeight', 'borderLeftWeight', 'borderRightWeight', 'effects', 'styleIdForFill', 'styleIdForStrokeFill', 'styleIdForStroke', 'styleIdForEffect', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd', 'arcData', 'cornerRadius', 'rectangleTopLeftCornerRadius', 'rectangleTopRightCornerRadius', 'rectangleBottomLeftCornerRadius', 'rectangleBottomRightCornerRadius', 'stackMode', 'stackPrimarySizing', 'stackCounterSizing', 'stackPositioning', 'stackChildAlignSelf', 'stackChildPrimaryGrow', 'componentPropAssignments') as $field ) {
                if ( array_key_exists($field, $override) ) {
                    $overrides[$nodeId][$field] = $override[$field];
                }
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
        $ids = array();
        foreach ( $guids as $guid ) {
            $id = $this->readGuidId($guid);
            if ( null !== $id ) {
                $ids[] = $id;
            }
        }

        return empty($ids) ? null : implode('/', $ids);
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
     * @param array<int, string>   $keys
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
}

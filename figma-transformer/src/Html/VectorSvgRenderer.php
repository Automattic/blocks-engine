<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

use Closure;

/**
 * Renders normalized Figma vector geometry as inline SVG markup.
 */
final class VectorSvgRenderer
{
    private const MAX_RAW_SVG_PATH_DATA_BYTES = 20000;
    private const MAX_DECODED_FIGMA_SVG_PATH_DATA_BYTES = 4194304;

    private Closure $nodeList;
    private Closure $number;
    private Closure $sanitizeAttribute;
    private Closure $firstSolidPaint;
    private Closure $backgroundColor;
    private Closure $nodeImagePaints;
    private Closure $explicitNodeAssetReferences;

    public function __construct(
        callable $nodeList,
        callable $number,
        callable $sanitizeAttribute,
        callable $firstSolidPaint,
        callable $backgroundColor,
        callable $nodeImagePaints,
        callable $explicitNodeAssetReferences
    ) {
        $this->nodeList = $nodeList instanceof Closure ? $nodeList : Closure::fromCallable($nodeList);
        $this->number = $number instanceof Closure ? $number : Closure::fromCallable($number);
        $this->sanitizeAttribute = $sanitizeAttribute instanceof Closure ? $sanitizeAttribute : Closure::fromCallable($sanitizeAttribute);
        $this->firstSolidPaint = $firstSolidPaint instanceof Closure ? $firstSolidPaint : Closure::fromCallable($firstSolidPaint);
        $this->backgroundColor = $backgroundColor instanceof Closure ? $backgroundColor : Closure::fromCallable($backgroundColor);
        $this->nodeImagePaints = $nodeImagePaints instanceof Closure ? $nodeImagePaints : Closure::fromCallable($nodeImagePaints);
        $this->explicitNodeAssetReferences = $explicitNodeAssetReferences instanceof Closure ? $explicitNodeAssetReferences : Closure::fromCallable($explicitNodeAssetReferences);
    }

    /**
     * @param array<string, mixed> $node
     */
    public function supportedVectorSvg(array $node, string $type, ?array $parentNode = null): ?string
    {
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return null;
        }

        if ( 'BOOLEAN_OPERATION' === $type && $this->shouldComposeBooleanOperationChildren($node) ) {
            $composed = $this->booleanOperationSvg($node, $parentNode);
            if ( null !== $composed ) {
                return $composed;
            }
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
        if ( ( abs($scaleX - 1.0) >= 0.0001 || abs($scaleY - 1.0) >= 0.0001 ) && $this->shouldApplyVectorScale($pathBounds, $width, $renderHeight) ) {
            $body = '<g transform="scale(' . $this->number($scaleX) . ' ' . $this->number($scaleY) . ')">' . $body . '</g>';
        }

        return '<svg ' . implode(' ', $attributes) . '>' . $body . '</svg>';
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function vectorPlaceholderDiagnostic(array $node, string $type, ?array $parentNode = null): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? max(0.0, (float) $box['width']) : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? max(0.0, (float) $box['height']) : 0.0;
        $sourceFields = $this->vectorSourceFieldNames($node);
        $rejectedPathSources = $this->rejectedVectorPathSourceDiagnostics($node);
        $missingFields = array();
        $reason = 'missing_vector_geometry';

        if ( $width <= 0.0 || ( $height <= 0.0 && null === $this->zeroHeightVectorFallbackHeight($node, $type) ) ) {
            $reason = 'missing_dimensions';
            if ( $width <= 0.0 ) {
                $missingFields[] = 'box.width';
            }
            if ( $height <= 0.0 ) {
                $missingFields[] = 'box.height';
            }
        } elseif ( ! empty($rejectedPathSources) ) {
            $reason = $this->hasOversizedRejectedVectorPath($rejectedPathSources) ? 'oversized_path_data' : 'unsupported_path_data';
        } elseif ( 'BOOLEAN_OPERATION' === $type && ! empty($this->nodeList($node)) ) {
            $reason = 'unsupported_boolean_operation_children';
        } elseif ( isset($node['vectorData']) && is_array($node['vectorData']) && array_key_exists('vectorNetworkBlob', $node['vectorData']) ) {
            $reason = 'unsupported_vector_network_blob';
        } elseif ( ! empty($this->explicitNodeAssetReferences($node)) || ! empty($this->nodeImagePaints($node)) ) {
            $reason = 'unresolved_asset_fallback';
        } elseif ( ! empty($sourceFields) ) {
            $reason = 'unsupported_vector_geometry';
        } else {
            $missingFields[] = 'figma_vector_paths';
            $missingFields[] = 'vectorPaths';
            $missingFields[] = 'paths';
            $missingFields[] = 'pathData';
            $missingFields[] = 'vectorData.vectorNetworkBlob';
        }

        $diagnostic = array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => $type,
            'reason' => $reason,
            'source_fields' => $sourceFields,
            'missing_fields' => array_values(array_unique($missingFields)),
        );

        if ( ! empty($rejectedPathSources) ) {
            $diagnostic['rejected_path_sources'] = $rejectedPathSources;
        }
        if ( null !== $parentNode && empty($sourceFields) && ! empty($this->inheritedVectorPaintAttributes($parentNode)) ) {
            $diagnostic['inherited_paint_available'] = true;
        }

        return $diagnostic;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function zeroHeightVectorFallbackHeight(array $node, string $type): ?float
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
     * @param array<string, mixed> $node
     */
    private function shouldComposeBooleanOperationChildren(array $node): bool
    {
        if ( empty($this->nodeList($node)) ) {
            return false;
        }

        if ( ! $this->hasExplicitVectorSource($node) ) {
            return true;
        }

        return 'UNION' === strtoupper(trim((string) ($node['booleanOperation'] ?? 'UNION')))
            && ! empty($this->booleanOperationChildVectors($node));
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
     * @param array{x: float, y: float, width: float, height: float}|null $pathBounds
     */
    private function shouldApplyVectorScale(?array $pathBounds, float $width, float $height): bool
    {
        if ( null === $pathBounds || $width <= 0.0 || $height <= 0.0 ) {
            return true;
        }

        $tolerance = max(0.5, min($width, $height) * 0.05);
        $fitsBox = $pathBounds['x'] >= -$tolerance
            && $pathBounds['y'] >= -$tolerance
            && $pathBounds['x'] + $pathBounds['width'] <= $width + $tolerance
            && $pathBounds['y'] + $pathBounds['height'] <= $height + $tolerance;
        $fillsBox = $pathBounds['width'] >= $width * 0.75
            && $pathBounds['height'] >= $height * 0.75;

        return ! ( $fitsBox && $fillsBox );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function vectorPathElements(array $node): array
    {
        $paint = $this->svgPaintAttributes($node);
        $elements = array();
        foreach ( $this->nodeVectorPathData($node) as $path ) {
            $attributes = $paint;
            if ( null !== ($path['windingRule'] ?? null) ) {
                $attributes[] = 'fill-rule="' . $path['windingRule'] . '"';
            }
            if ( null !== ($path['styleID'] ?? null) ) {
                $attributes[] = 'data-figma-style-id="' . $this->sanitizeAttribute($path['styleID']) . '"';
            }
            $elements[] = '<path d="' . $this->sanitizeAttribute($path['d']) . '" ' . implode(' ', $attributes) . '/>';
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{d: string, windingRule: string|null, styleID: string|null}>
     */
    private function nodeVectorPathData(array $node): array
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

        $paths = array();
        foreach ( $rawPaths as $rawPath ) {
            $path = is_array($rawPath) ? (string) ($rawPath['data'] ?? $rawPath['pathData'] ?? $rawPath['path'] ?? $rawPath['d'] ?? '') : (string) $rawPath;
            $path = $this->safeSvgPathData($path, $this->svgPathDataByteLimit($rawPath));
            if ( null === $path ) {
                continue;
            }

            $rule = null;
            if ( is_array($rawPath) && isset($rawPath['windingRule']) && is_scalar($rawPath['windingRule']) ) {
                $candidate = strtolower((string) $rawPath['windingRule']);
                if ( in_array($candidate, array('evenodd', 'nonzero'), true) ) {
                    $rule = $candidate;
                }
            }

            $styleId = null;
            if ( is_array($rawPath) && isset($rawPath['styleID']) && is_scalar($rawPath['styleID']) && '' !== trim((string) $rawPath['styleID']) ) {
                $styleId = (string) $rawPath['styleID'];
            }

            $paths[] = array('d' => $path, 'windingRule' => $rule, 'styleID' => $styleId);
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function booleanOperationSvg(array $node, ?array $parentNode): ?string
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? max(0.0, (float) $box['width']) : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? max(0.0, (float) $box['height']) : 0.0;
        if ( $width <= 0 || $height <= 0 ) {
            return null;
        }

        $children = $this->booleanOperationChildVectors($node);
        if ( empty($children) ) {
            return null;
        }

        $operation = strtoupper(trim((string) ($node['booleanOperation'] ?? 'UNION')));
        $body = null;
        if ( in_array($operation, array('SUBTRACT', 'INTERSECT', 'EXCLUDE'), true) ) {
            $body = $this->booleanEvenOddBody($node, $children);
        }
        if ( null === $body ) {
            $body = $this->booleanUnionBody($children);
        }
        if ( '' === $body ) {
            return null;
        }

        $attributes = array(
            'xmlns="http://www.w3.org/2000/svg"',
            'viewBox="0 0 ' . $this->number($width) . ' ' . $this->number($height) . '"',
            'width="100%"',
            'height="100%"',
            'role="img"',
            'aria-label="' . $this->sanitizeAttribute((string) ($node['name'] ?? $operation)) . '"',
            'data-figma-vector="true"',
            'data-figma-boolean-operation="' . $this->sanitizeAttribute(strtolower($operation)) . '"',
        );

        return '<svg ' . implode(' ', $attributes) . '>' . $body . '</svg>';
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array{paths: array<int, array{d: string, windingRule: string|null, styleID: string|null}>, paint: array<int, string>, dx: float, dy: float}>
     */
    private function booleanOperationChildVectors(array $node): array
    {
        $originBox = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : (is_array($node['box'] ?? null) ? $node['box'] : array());
        $originX = isset($originBox['x']) && is_numeric($originBox['x']) ? (float) $originBox['x'] : 0.0;
        $originY = isset($originBox['y']) && is_numeric($originBox['y']) ? (float) $originBox['y'] : 0.0;

        $collected = array();
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->collectBooleanChildVectors($child, $originX, $originY, $collected);
            }
        }

        return $collected;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $collected
     */
    private function collectBooleanChildVectors(array $node, float $originX, float $originY, array &$collected): void
    {
        $paths = $this->nodeVectorPathData($node);
        if ( ! empty($paths) ) {
            $box = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : (is_array($node['box'] ?? null) ? $node['box'] : array());
            $dx = isset($box['x']) && is_numeric($box['x']) ? (float) $box['x'] - $originX : 0.0;
            $dy = isset($box['y']) && is_numeric($box['y']) ? (float) $box['y'] - $originY : 0.0;
            $collected[] = array(
                'paths' => $paths,
                'paint' => $this->svgPaintAttributes($node),
                'dx'    => $dx,
                'dy'    => $dy,
            );
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->collectBooleanChildVectors($child, $originX, $originY, $collected);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function booleanUnionBody(array $children): string
    {
        $body = '';
        foreach ( $children as $child ) {
            $paint = is_array($child['paint'] ?? null) && ! empty($child['paint']) ? $child['paint'] : array('fill="currentColor"');
            $elements = '';
            foreach ( is_array($child['paths'] ?? null) ? $child['paths'] : array() as $path ) {
                $attributes = $paint;
                if ( null !== ($path['windingRule'] ?? null) ) {
                    $attributes[] = 'fill-rule="' . $path['windingRule'] . '"';
                }
                if ( null !== ($path['styleID'] ?? null) ) {
                    $attributes[] = 'data-figma-style-id="' . $this->sanitizeAttribute($path['styleID']) . '"';
                }
                $elements .= '<path d="' . $this->sanitizeAttribute((string) $path['d']) . '" ' . implode(' ', $attributes) . '/>';
            }
            if ( '' === $elements ) {
                continue;
            }

            $dx = (float) ($child['dx'] ?? 0.0);
            $dy = (float) ($child['dy'] ?? 0.0);
            if ( abs($dx) >= 0.0001 || abs($dy) >= 0.0001 ) {
                $body .= '<g transform="translate(' . $this->number($dx) . ' ' . $this->number($dy) . ')">' . $elements . '</g>';
            } else {
                $body .= $elements;
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $children
     */
    private function booleanEvenOddBody(array $node, array $children): ?string
    {
        $combined = array();
        foreach ( $children as $child ) {
            $dx = (float) ($child['dx'] ?? 0.0);
            $dy = (float) ($child['dy'] ?? 0.0);
            if ( abs($dx) >= 0.0001 || abs($dy) >= 0.0001 ) {
                return null;
            }
            foreach ( is_array($child['paths'] ?? null) ? $child['paths'] : array() as $path ) {
                $combined[] = (string) $path['d'];
            }
        }
        if ( empty($combined) ) {
            return null;
        }

        $paint = $this->svgPaintAttributes($node);
        if ( ( 'fill="none"' === ($paint[0] ?? '') ) && ! $this->hasSvgStroke($paint) ) {
            foreach ( $children as $child ) {
                if ( ! empty($child['paint']) && 'fill="none"' !== ($child['paint'][0] ?? '') ) {
                    $paint = $child['paint'];
                    break;
                }
            }
        }

        $paint[] = 'fill-rule="evenodd"';
        return '<path d="' . $this->sanitizeAttribute(implode(' ', $combined)) . '" ' . implode(' ', $paint) . '/>';
    }

    /**
     * @param array<string, mixed> $node
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
            $arcPath = $this->primitiveEllipseArcPath($node, $width, $height);
            if ( null !== $arcPath ) {
                return array('<path d="' . $this->sanitizeAttribute($arcPath) . '" ' . implode(' ', $paint) . '/>');
            }
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
    private function primitiveEllipseArcPath(array $node, float $width, float $height): ?string
    {
        $arc = is_array($node['arcData'] ?? null) ? $node['arcData'] : array();
        if ( ! isset($arc['startingAngle'], $arc['endingAngle']) || ! is_numeric($arc['startingAngle']) || ! is_numeric($arc['endingAngle']) ) {
            return null;
        }

        $start = (float) $arc['startingAngle'];
        $end = (float) $arc['endingAngle'];
        $sweep = $end - $start;
        if ( abs($sweep) >= (2 * M_PI) - 0.0001 || abs($sweep) <= 0.0001 ) {
            return null;
        }

        $rx = $width / 2;
        $ry = $height / 2;
        $cx = $rx;
        $cy = $ry;
        $innerRadiusRatio = isset($arc['innerRadius']) && is_numeric($arc['innerRadius']) ? max(0.0, min(1.0, (float) $arc['innerRadius'])) : 0.0;
        $largeArc = abs($sweep) > M_PI ? 1 : 0;
        $sweepFlag = $sweep >= 0 ? 1 : 0;
        $outerStart = $this->ellipsePoint($cx, $cy, $rx, $ry, $start);
        $outerEnd = $this->ellipsePoint($cx, $cy, $rx, $ry, $end);

        if ( $innerRadiusRatio <= 0.0001 ) {
            return 'M ' . $this->number($cx) . ' ' . $this->number($cy)
                . ' L ' . $this->number($outerStart[0]) . ' ' . $this->number($outerStart[1])
                . ' A ' . $this->number($rx) . ' ' . $this->number($ry) . ' 0 ' . $largeArc . ' ' . $sweepFlag . ' ' . $this->number($outerEnd[0]) . ' ' . $this->number($outerEnd[1])
                . ' Z';
        }

        $innerRx = $rx * $innerRadiusRatio;
        $innerRy = $ry * $innerRadiusRatio;
        $innerEnd = $this->ellipsePoint($cx, $cy, $innerRx, $innerRy, $end);
        $innerStart = $this->ellipsePoint($cx, $cy, $innerRx, $innerRy, $start);
        $innerSweepFlag = 1 === $sweepFlag ? 0 : 1;

        return 'M ' . $this->number($outerStart[0]) . ' ' . $this->number($outerStart[1])
            . ' A ' . $this->number($rx) . ' ' . $this->number($ry) . ' 0 ' . $largeArc . ' ' . $sweepFlag . ' ' . $this->number($outerEnd[0]) . ' ' . $this->number($outerEnd[1])
            . ' L ' . $this->number($innerEnd[0]) . ' ' . $this->number($innerEnd[1])
            . ' A ' . $this->number($innerRx) . ' ' . $this->number($innerRy) . ' 0 ' . $largeArc . ' ' . $innerSweepFlag . ' ' . $this->number($innerStart[0]) . ' ' . $this->number($innerStart[1])
            . ' Z';
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function ellipsePoint(float $cx, float $cy, float $rx, float $ry, float $angle): array
    {
        return array(
            $cx + ($rx * cos($angle)),
            $cy + ($ry * sin($angle)),
        );
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

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function vectorSourceFieldNames(array $node): array
    {
        $fields = array();
        foreach ( array('figma_vector_paths', 'vectorPaths', 'paths', 'fillGeometry', 'strokeGeometry') as $key ) {
            if ( ! empty($node[$key]) ) {
                $fields[] = $key;
            }
        }
        foreach ( array('pathData', 'path', 'd') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== trim((string) $node[$key]) ) {
                $fields[] = $key;
            }
        }
        if ( isset($node['vectorData']) && is_array($node['vectorData']) && ! empty($node['vectorData']) ) {
            foreach ( array_keys($node['vectorData']) as $key ) {
                if ( is_scalar($key) ) {
                    $fields[] = 'vectorData.' . (string) $key;
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function rejectedVectorPathSourceDiagnostics(array $node): array
    {
        $rawPaths = array();
        if ( is_array($node['figma_vector_paths'] ?? null) ) {
            foreach ( $node['figma_vector_paths'] as $rawPath ) {
                $rawPaths[] = array('field' => 'figma_vector_paths', 'value' => $rawPath);
            }
        }
        foreach ( array('vectorPaths', 'paths') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                foreach ( $node[$key] as $rawPath ) {
                    $rawPaths[] = array('field' => $key, 'value' => $rawPath);
                }
            }
        }
        foreach ( array('pathData', 'path', 'd') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $rawPaths[] = array('field' => $key, 'value' => array('data' => (string) $node[$key]));
            }
        }

        $rejected = array();
        foreach ( $rawPaths as $rawPath ) {
            $value = $rawPath['value'];
            $path = is_array($value) ? (string) ($value['data'] ?? $value['pathData'] ?? $value['path'] ?? $value['d'] ?? '') : (string) $value;
            if ( '' === trim($path) ) {
                continue;
            }
            $limit = $this->svgPathDataByteLimit($value);
            if ( null !== $this->safeSvgPathData($path, $limit) ) {
                continue;
            }

            $reason = strlen($path) > $limit ? 'oversized_path_data' : 'unsupported_path_data';
            $source = is_array($value) && isset($value['source']) && is_scalar($value['source']) ? (string) $value['source'] : (string) $rawPath['field'];
            $rejected[] = array(
                'field' => (string) $rawPath['field'],
                'source' => $source,
                'reason' => $reason,
                'bytes' => strlen($path),
                'limit_bytes' => $limit,
            );
        }

        return $rejected;
    }

    /**
     * @param array<int, array<string, mixed>> $rejectedPathSources
     */
    private function hasOversizedRejectedVectorPath(array $rejectedPathSources): bool
    {
        foreach ( $rejectedPathSources as $source ) {
            if ( 'oversized_path_data' === ($source['reason'] ?? null) ) {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<string, mixed> $node
     */
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
            if ( str_starts_with($source, 'fillGeometry') || str_starts_with($source, 'strokeGeometry') || str_starts_with($source, 'vectorData.vectorNetwork') ) {
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
            foreach ( $this->strokeGeometryAttributes($node) as $attribute ) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function strokeGeometryAttributes(array $node): array
    {
        $attributes = array();

        $cap = strtoupper((string) ($node['strokeCap'] ?? ''));
        $capMap = array('ROUND' => 'round', 'SQUARE' => 'square', 'BUTT' => 'butt', 'NONE' => 'butt');
        if ( isset($capMap[$cap]) ) {
            $attributes[] = 'stroke-linecap="' . $capMap[$cap] . '"';
        }

        $join = strtoupper((string) ($node['strokeJoin'] ?? ''));
        $joinMap = array('ROUND' => 'round', 'BEVEL' => 'bevel', 'MITER' => 'miter');
        if ( isset($joinMap[$join]) ) {
            $attributes[] = 'stroke-linejoin="' . $joinMap[$join] . '"';
        }

        $dashPattern = is_array($node['dashPattern'] ?? null) ? $node['dashPattern'] : array();
        $dashes = array();
        foreach ( $dashPattern as $dash ) {
            if ( ! is_numeric($dash) ) {
                return $attributes;
            }
            $value = max(0.0, (float) $dash);
            if ( $value > 0.0 ) {
                $dashes[] = $this->number($value);
            }
        }
        if ( ! empty($dashes) ) {
            $attributes[] = 'stroke-dasharray="' . implode(' ', $dashes) . '"';
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
     * @return array<int, mixed>
     */
    private function nodeList(array $node): array
    {
        return ($this->nodeList)($node);
    }

    private function number(float $value): string
    {
        return ($this->number)($value);
    }

    private function sanitizeAttribute(string $value): string
    {
        return ($this->sanitizeAttribute)($value);
    }

    /**
     * @param array<int, mixed> $paints
     */
    private function firstSolidPaint(array $paints): ?string
    {
        return ($this->firstSolidPaint)($paints);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function backgroundColor(array $node): ?string
    {
        return ($this->backgroundColor)($node);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function nodeImagePaints(array $node): array
    {
        return ($this->nodeImagePaints)($node);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function explicitNodeAssetReferences(array $node): array
    {
        return ($this->explicitNodeAssetReferences)($node);
    }
}

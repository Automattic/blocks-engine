<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes Figma paint collections into the scenegraph contract.
 */
final class PaintNormalizer
{
    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function normalizePaintCollections(array $node, string $nodeId, array &$diagnostics, array $paintStyles = array()): array
    {
        $collections = array();
        foreach ( array('fills' => 'fills', 'fillPaints' => 'fills', 'strokes' => 'strokes', 'strokePaints' => 'strokes', 'background' => 'background', 'backgroundPaints' => 'background') as $sourceKey => $targetKey ) {
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
    public function normalizePaintList(array $paints, string $nodeId, string $paintKey, array &$diagnostics): array
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

    /**
     * @param array<int, mixed> $overridePaints
     * @param array<int, mixed> $sourcePaints
     * @return array<int, mixed>
     */
    public function removeSourceImagePaintsFromOverrideList(array $overridePaints, array $sourcePaints): array
    {
        $sourceRefs = array();
        foreach ( $sourcePaints as $paint ) {
            $ref = is_array($paint) ? $this->readImagePaintRef($paint) : null;
            if ( null !== $ref ) {
                $sourceRefs[$ref] = true;
            }
        }

        if ( empty($sourceRefs) ) {
            return $overridePaints;
        }

        $hasReplacementImage = false;
        foreach ( $overridePaints as $paint ) {
            $ref = is_array($paint) ? $this->readImagePaintRef($paint) : null;
            if ( null !== $ref && ! isset($sourceRefs[$ref]) ) {
                $hasReplacementImage = true;
                break;
            }
        }

        if ( ! $hasReplacementImage ) {
            return $overridePaints;
        }

        $filtered = array();
        foreach ( $overridePaints as $paint ) {
            $ref = is_array($paint) ? $this->readImagePaintRef($paint) : null;
            if ( null !== $ref && isset($sourceRefs[$ref]) ) {
                continue;
            }

            $filtered[] = $paint;
        }

        return $filtered;
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
            if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                $normalized['blendMode'] = strtoupper((string) $paint['blendMode']);
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
            if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                $normalized['blendMode'] = strtoupper((string) $paint['blendMode']);
            }
            foreach ( array('originalImageWidth', 'originalImageHeight', 'scale', 'rotation', 'opacity') as $key ) {
                if ( isset($paint[$key]) && is_numeric($paint[$key]) ) {
                    $normalized[$key] = (float) $paint[$key];
                }
            }
            if ( isset($paint['animationFrame']) && is_numeric($paint['animationFrame']) ) {
                $normalized['animationFrame'] = (int) $paint['animationFrame'];
            }
            if ( isset($paint['imageShouldColorManage']) && is_bool($paint['imageShouldColorManage']) ) {
                $normalized['imageShouldColorManage'] = $paint['imageShouldColorManage'];
            }
            if ( isset($paint['thumbHash']) && is_scalar($paint['thumbHash']) && '' !== (string) $paint['thumbHash'] ) {
                $normalized['thumbHash'] = $this->normalizeByteString((string) $paint['thumbHash']);
            }
            foreach ( array('transform', 'imageTransform', 'cropTransform') as $transformKey ) {
                if ( is_array($paint[$transformKey] ?? null) ) {
                    $normalized['transform'] = $paint[$transformKey];
                    break;
                }
            }
            if ( is_array($paint['cropRect'] ?? null) ) {
                $normalized['cropRect'] = $paint['cropRect'];
            }

            return $normalized;
        }

        if ( in_array($type, array('GRADIENT_LINEAR', 'GRADIENT_RADIAL', 'GRADIENT_ANGULAR'), true) ) {
            $stops = $this->normalizeGradientStops($paint['gradientStops'] ?? $paint['stops'] ?? array());
            if ( ! empty($stops) ) {
                $normalized = array(
                    'type'  => $type,
                    'stops' => $stops,
                );
                if ( isset($paint['opacity']) && is_numeric($paint['opacity']) ) {
                    $normalized['opacity'] = (float) $paint['opacity'];
                }
                if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                    $normalized['blendMode'] = strtoupper((string) $paint['blendMode']);
                }
                if ( is_array($paint['gradientTransform'] ?? null) ) {
                    $normalized['gradientTransform'] = $paint['gradientTransform'];
                }

                return $normalized;
            }
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
     * @param mixed $stops
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGradientStops(mixed $stops): array
    {
        if ( ! is_array($stops) ) {
            return array();
        }

        $normalizedStops = array();
        foreach ( $stops as $stop ) {
            if ( ! is_array($stop) || ! isset($stop['position']) || ! is_numeric($stop['position']) ) {
                continue;
            }

            $color = $this->normalizeColor($stop['color'] ?? null);
            if ( null === $color ) {
                continue;
            }

            $normalizedStops[] = array(
                'position' => max(0.0, min(1.0, (float) $stop['position'])),
                'color'    => $color,
            );
        }

        return $normalizedStops;
    }

    private function readNestedImageHash(array $image): ?string
    {
        if ( ! isset($image['hash']) || ! is_scalar($image['hash']) || '' === (string) $image['hash'] ) {
            return null;
        }

        return $this->normalizeImageHash((string) $image['hash']);
    }

    /**
     * @param array<string, mixed> $paint
     */
    private function readImagePaintRef(array $paint): ?string
    {
        $type = strtoupper((string) ($paint['type'] ?? ''));
        if ( '' !== $type && 'IMAGE' !== $type ) {
            return null;
        }

        $ref = $paint['imageRef'] ?? $paint['imageHash'] ?? $paint['ref'] ?? null;
        if ( is_scalar($ref) && '' !== (string) $ref ) {
            return $this->normalizeImageHash((string) $ref);
        }

        if ( is_array($paint['image'] ?? null) ) {
            return $this->readNestedImageHash($paint['image']);
        }

        return null;
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

    private function normalizeByteString(string $value): string
    {
        return 1 === preg_match('//u', $value) ? $value : bin2hex($value);
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
}

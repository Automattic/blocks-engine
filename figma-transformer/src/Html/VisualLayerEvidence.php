<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Shared evidence helpers for deciding how visual layers should render.
 */
final class VisualLayerEvidence
{
    /** @var array<int, string> */
    private const PAINT_COLLECTION_KEYS = array('fills', 'strokes', 'background');

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    public static function imagePaints(array $node): array
    {
        $imagePaints = array();
        foreach ( self::PAINT_COLLECTION_KEYS as $paintKey ) {
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
     * @param array<string, mixed> $node
     */
    public static function firstImagePaint(array $node): ?array
    {
        foreach ( self::imagePaints($node) as $paint ) {
            return $paint;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    public static function hasImagePaint(array $node): bool
    {
        return null !== self::firstImagePaint($node);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public static function explicitNodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $references[] = (string) $node[$key];
            }
        }
        if ( is_array($node['image'] ?? null) ) {
            $references = array_merge($references, self::imageAssetReferences($node['image']));
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $image
     * @return array<int, string>
     */
    public static function imageAssetReferences(array $image): array
    {
        $references = array();
        foreach ( array('hash', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref', 'ref', 'source_id', 'node_id', 'nodeId', 'name', 'fileName', 'filename') as $key ) {
            if ( isset($image[$key]) && is_scalar($image[$key]) && '' !== (string) $image[$key] ) {
                $references[] = (string) $image[$key];
            }
        }

        if ( is_array($image['assetRef'] ?? null) ) {
            $references = array_merge($references, self::assetRefReferences($image['assetRef']));
        }
        if ( is_array($image['sourceImage'] ?? null) ) {
            $references = array_merge($references, self::imageAssetReferences($image['sourceImage']));
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $paint
     * @param array<int, string>|null $referenceKeys
     * @return array<int, string>
     */
    public static function paintAssetReferences(array $paint, ?array $referenceKeys = null): array
    {
        $references = array();
        foreach ( $referenceKeys ?? array('ref', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                $references[] = (string) $paint[$key];
            }
        }

        if ( is_array($paint['assetRef'] ?? null) ) {
            $references = array_merge($references, self::assetRefReferences($paint['assetRef']));
        }
        foreach ( array('image', 'thumbnail', 'imageThumbnail', 'sourceImage') as $imageKey ) {
            if ( is_array($paint[$imageKey] ?? null) ) {
                $references = array_merge($references, self::imageAssetReferences($paint[$imageKey]));
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $assetRef
     * @return array<int, string>
     */
    public static function assetRefReferences(array $assetRef): array
    {
        $references = array();
        foreach ( array('id', 'key', 'nodeID', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $key ) {
            if ( isset($assetRef[$key]) && is_scalar($assetRef[$key]) && '' !== (string) $assetRef[$key] ) {
                $references[] = (string) $assetRef[$key];
            }
        }
        if ( is_array($assetRef['guid'] ?? null) && isset($assetRef['guid']['sessionID'], $assetRef['guid']['localID']) ) {
            $references[] = (string) $assetRef['guid']['sessionID'] . ':' . (string) $assetRef['guid']['localID'];
        } elseif ( isset($assetRef['guid']) && is_scalar($assetRef['guid']) && '' !== (string) $assetRef['guid'] ) {
            $references[] = (string) $assetRef['guid'];
        }

        return array_values(array_unique($references));
    }
}

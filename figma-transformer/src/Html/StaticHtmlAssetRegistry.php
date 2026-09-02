<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Per-emission asset aliases, availability evidence, and usage tracking.
 */
final class StaticHtmlAssetRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $assetsById = array();

    /** @var array<string, string> */
    private array $unavailableReasonsById = array();

    /** @var array<string, bool> */
    private array $usedPaths = array();

    public function __construct(private readonly StaticHtmlValueFormatter $formatter)
    {
    }

    public function reset(): void
    {
        $this->assetsById = array();
        $this->unavailableReasonsById = array();
        $this->usedPaths = array();
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<int, string>
     */
    public function aliases(array $asset, string $id): array
    {
        $aliases = array($id);
        foreach ( array('hash', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref', 'source_id', 'node_id', 'nodeId', 'name', 'fileName', 'filename', 'key', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $key ) {
            if ( isset($asset[$key]) && is_scalar($asset[$key]) ) {
                $aliases[] = (string) $asset[$key];
            }
        }

        foreach ( $aliases as $alias ) {
            $aliases[] = $this->formatter->slug($alias);
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
     * @param array<int, string> $aliases
     * @param array<string, mixed> $file
     */
    public function register(array $aliases, array $file): void
    {
        foreach ( $aliases as $alias ) {
            $this->assetsById[$alias] = $file;
        }
    }

    /** @param array<int, string> $aliases */
    public function markUnavailable(array $aliases, string $reason): void
    {
        foreach ( $aliases as $alias ) {
            $this->unavailableReasonsById[$alias] = $reason;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function index(): array
    {
        return $this->assetsById;
    }

    /** @param array<string, mixed> $node */
    public function resolveAndMarkNode(array $node): ?string
    {
        return $this->resolveAndMarkReferences($this->nodeReferences($node));
    }

    /** @param array<string, mixed> $paint */
    public function resolveAndMarkPaint(array $paint): ?string
    {
        return $this->resolveAndMarkReferences(VisualLayerEvidence::paintAssetReferences($paint));
    }

    /**
     * @param array<int, string> $references
     */
    public function unavailableReason(array $references): ?string
    {
        foreach ( $references as $reference ) {
            if ( isset($this->unavailableReasonsById[$reference]) ) {
                return $this->unavailableReasonsById[$reference];
            }

            $slugged = $this->formatter->slug($reference);
            if ( isset($this->unavailableReasonsById[$slugged]) ) {
                return $this->unavailableReasonsById[$slugged];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<int, array<string, mixed>>
     */
    public function referencedFiles(array $assetFiles): array
    {
        if ( empty($this->usedPaths) ) {
            return array();
        }

        return array_values(array_filter(
            $assetFiles,
            fn (array $file): bool => isset($this->usedPaths[(string) ($file['path'] ?? '')])
        ));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function nodeReferences(array $node): array
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
                    if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                        $references = array_merge($references, VisualLayerEvidence::paintAssetReferences($paint));
                    }
                }
            }
        }

        foreach ( $references as $reference ) {
            $references[] = $this->formatter->slug($reference);
        }

        return array_values(array_unique($references));
    }

    /** @param array<int, string> $references */
    private function resolveAndMarkReferences(array $references): ?string
    {
        foreach ( $references as $assetId ) {
            $path = $this->resolvePath($assetId);
            if ( null !== $path ) {
                $this->usedPaths[$path] = true;
                return $path;
            }
        }

        return null;
    }

    private function resolvePath(string $assetId): ?string
    {
        if ( isset($this->assetsById[$assetId]) ) {
            return (string) $this->assetsById[$assetId]['path'];
        }

        $slugged = $this->formatter->slug($assetId);
        return isset($this->assetsById[$slugged]) ? (string) $this->assetsById[$slugged]['path'] : null;
    }
}

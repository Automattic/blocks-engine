<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds stable node match keys for responsive breakpoint diffs.
 */
final class ResponsiveNodeMatcher
{
    /**
     * @param callable(string): string $slug
     */
    public function __construct(
        private readonly mixed $slug,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $siblings
     * @return array<string, int>
     */
    public function siblingSignatureCounts(array $siblings): array
    {
        $counts = array();
        foreach ( $siblings as $sibling ) {
            $signature = $this->structuralSignature($sibling);
            if ( null === $signature ) {
                continue;
            }

            $counts[$signature] = ($counts[$signature] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $siblings
     * @return array<string, int>
     */
    public function siblingSourceIdentityCounts(array $siblings): array
    {
        $counts = array();
        foreach ( $siblings as $sibling ) {
            foreach ( $this->sourceIdentities($sibling) as $sourceId ) {
                $key = ($this->slug)($sourceId);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, int>   $siblingSignatureCounts
     * @return array<int, string>
     */
    public function childKeys(array $node, int $ordinal, array $siblingSignatureCounts, array $siblingSourceIdentityCounts = array()): array
    {
        $keys = array();

        foreach ( $this->sourceIdentities($node) as $sourceId ) {
            $sourceKey = ($this->slug)($sourceId);
            if ( 1 === ($siblingSourceIdentityCounts[$sourceKey] ?? 1) ) {
                $keys[] = 'source:' . $sourceKey;
            }
        }

        $signature = $this->structuralSignature($node);
        if ( null !== $signature && 1 === ($siblingSignatureCounts[$signature] ?? 0) ) {
            $keys[] = 'struct-signature:' . $signature;
        }

        if ( ! empty($keys) ) {
            return array_values(array_unique($keys));
        }

        $keys[] = 'struct-ordinal:' . $ordinal . ':' . $this->nodeType($node) . ':' . ($this->slug)($this->nodeName($node));

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function sourceIdentities(array $node): array
    {
        $sourceIds = array();
        foreach ( array('figma_component_source_id', 'source_id') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $sourceIds[] = (string) $node[$key];
            }
        }

        $component = is_array($node['figma_component'] ?? null) ? $node['figma_component'] : array();
        foreach ( array('component_id', 'component_source_key', 'definition_id') as $key ) {
            if ( isset($component[$key]) && is_scalar($component[$key]) && '' !== (string) $component[$key] ) {
                $sourceIds[] = 'figma_component.' . $key . ':' . (string) $component[$key];
            }
        }

        return array_values(array_unique($sourceIds));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function structuralSignature(array $node): ?string
    {
        $name = $this->nodeName($node);
        if ( '' === $name ) {
            return null;
        }

        return $this->nodeType($node) . ':' . ($this->slug)($name);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeType(array $node): string
    {
        return strtoupper((string) ($node['type'] ?? 'FRAME'));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeName(array $node): string
    {
        return isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : '';
    }
}

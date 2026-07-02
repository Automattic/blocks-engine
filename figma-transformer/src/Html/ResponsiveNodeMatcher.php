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
     * @param array<string, mixed> $node
     * @param array<string, int>   $siblingSignatureCounts
     * @return array<int, string>
     */
    public function childKeys(array $node, int $ordinal, array $siblingSignatureCounts): array
    {
        $keys = array();

        $sourceId = $this->sourceIdentity($node);
        if ( '' !== $sourceId ) {
            $keys[] = 'source:' . ($this->slug)($sourceId);
        }

        $signature = $this->structuralSignature($node);
        if ( null !== $signature && 1 === ($siblingSignatureCounts[$signature] ?? 0) ) {
            $keys[] = 'struct-signature:' . $signature;
        }

        $keys[] = 'struct-ordinal:' . $ordinal . ':' . $this->nodeType($node) . ':' . ($this->slug)($this->nodeName($node));

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function sourceIdentity(array $node): string
    {
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        if ( '' === $sourceId && isset($node['source_id']) && is_scalar($node['source_id']) ) {
            $sourceId = (string) $node['source_id'];
        }

        return $sourceId;
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

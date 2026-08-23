<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves responsive browser wrapping declarations from classified text flow.
 *
 * @internal
 */
final class StaticHtmlTextWrappingResolver
{
    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function declarations(array $node, string $tag, bool $atomicSingleLineLabel, bool $longFallbackWrappingHeading, bool $preserveChromeSpacing): array
    {
        if ( ($atomicSingleLineLabel && ! $longFallbackWrappingHeading) || $preserveChromeSpacing ) {
            return array();
        }

        $styles = array('overflow-wrap:break-word');
        if ( in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true) ) {
            $styles[] = 'text-wrap:balance';
        } elseif ( 'p' === $tag || $this->hasBodyTextNameIntent(strtolower((string) ($node['name'] ?? ''))) ) {
            $styles[] = 'hyphens:auto';
            $styles[] = 'text-wrap:pretty';
        }

        return $styles;
    }

    private function hasBodyTextNameIntent(string $lowerName): bool
    {
        foreach ( array('paragraph', 'body', 'supporting text', 'caption', 'description', 'excerpt', 'copy') as $needle ) {
            if ( str_contains($lowerName, $needle) ) {
                return true;
            }
        }

        return false;
    }
}

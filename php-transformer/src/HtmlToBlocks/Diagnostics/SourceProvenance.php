<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\Utf8Text;
use DOMElement;

/**
 * Builds the per-block source provenance record: where a block came from, a
 * safe excerpt of its source, and how its source element was converted.
 */
final class SourceProvenance
{
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(string $blockName, DOMElement $element): array
    {
        $sourceHtml = SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers());
        return array_merge(array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => SourceDom::elementSelector($element),
            'source_attributes' => $this->safeSourceAttributes($element),
            'source_fragment'   => $this->safeSourceFragment($element),
            'source_digest'     => hash('sha256', $sourceHtml),
            'source_bytes'      => strlen($sourceHtml),
            'source_path'       => $this->session->transformationProvenanceState()->fallback()['source'] ?? '',
            'context'           => $this->sourceElementDescription->sourceContext($element),
        ), $this->sourceConversionMetadata($blockName, $element), $this->navigationSourceOwnership($blockName, $element));
    }

    /** @return array<string, array<string, array<string, string>>> */
    private function navigationSourceOwnership(string $blockName, DOMElement $element): array
    {
        if ( ! in_array($blockName, array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
            return array();
        }

        $anchor = 'a' === strtolower($element->tagName) ? $element : null;
        $submenu = null;
        if ( 'core/navigation-submenu' === $blockName ) {
            foreach ( $element->childNodes as $child ) {
                if ( ! $child instanceof DOMElement ) {
                    continue;
                }
                if ( 'a' === strtolower($child->tagName) && ! $anchor instanceof DOMElement ) {
                    $anchor = $child;
                }
                if ( 'a' !== strtolower($child->tagName)
                    && 0 < $child->getElementsByTagName('a')->length
                    && ! $submenu instanceof DOMElement
                ) {
                    $submenu = $child;
                }
            }
        }

        $ownership = array();
        foreach ( array( 'anchor' => $anchor, 'submenu' => $submenu ) as $kind => $source ) {
            if ( ! $source instanceof DOMElement ) {
                continue;
            }
            $ownership[$kind] = array(
                'selector'   => SourceDom::elementSelector($source),
                'class_name' => trim(SourceDom::attr($source, 'class')),
            );
        }

        return array() === $ownership ? array() : array( 'navigation_source_ownership' => $ownership );
    }

    /**
     * @return array{conversion_classification: string, preservation_strategy: string}
     */
    private function sourceConversionMetadata(string $blockName, DOMElement $element): array
    {
        $tagName = strtolower($element->tagName);

        if ( 'core/html' === $blockName ) {
            return array(
                'conversion_classification' => 'runtime_island_preserved',
                'preservation_strategy'     => 'bounded_raw_html_runtime_island',
            );
        }

        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            return array(
                'conversion_classification' => 'runtime_island_preserved',
                'preservation_strategy'     => 'core_block_shell_with_runtime_target',
            );
        }

        // An SVG text run lowered to a native text block keeps its text and
        // typography; its line box approximates the SVG glyph placement.
        if ( 'text' === $tagName ) {
            return array(
                'conversion_classification' => 'editable_approximation',
                'preservation_strategy'     => 'svg_text_run_as_native_text',
            );
        }

        if ( in_array($tagName, array('form', 'input', 'select', 'textarea'), true) && 'core/search' !== $blockName ) {
            return array(
                'conversion_classification' => 'editable_approximation',
                'preservation_strategy'     => 'readable_static_block_approximation',
            );
        }

        return array(
            'conversion_classification' => 'native_block_conversion',
            'preservation_strategy'     => 'core_block',
        );
    }

    /**
     * @return array<string, string>
     */
    private function safeSourceAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'alt', 'class', 'data-layout', 'data-wp-layout', 'height', 'href', 'id', 'media', 'open', 'sizes', 'src', 'srcset', 'style', 'title', 'type', 'width' ));
        foreach ( SourceDom::htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/^\s*javascript\s*:/i', $value) ) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    private function safeSourceFragment(DOMElement $element): string
    {
        $html = SourceDom::safeFallbackHtml($element, $this->session->authorSelectorProjectionState()->tagMarkers());
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';

        if ( strlen($html) > 500 ) {
            return Utf8Text::prefix($html, 500) . '...';
        }

        return $html;
    }
}

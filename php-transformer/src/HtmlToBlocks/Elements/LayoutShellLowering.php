<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Preserves a static source wrapper chain around editable inner blocks as one
 * generated layout-shell block.
 */
final class LayoutShellLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly HtmlTransformerSession $session,
        private readonly StyleResolver $styleResolver,
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /**
     * Preserve a static source wrapper chain around editable inner blocks.
     *
     * @param list<DOMElement> $elements
     * @param list<array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function blockForElements(array $elements, array $innerBlocks, DOMElement $sourceElement, bool $fieldBox = false): array
    {
        $wrappers = array_map(fn (DOMElement $element): array => $this->layoutShellWrapper($element, $fieldBox), $elements);
        $blockName = $this->session->requireGeneratedBlockRegistry()->blockName('layout-shell');
        $this->session->requireGeneratedBlockRegistry()->register(LayoutShellBlockGenerator::class, (new LayoutShellBlockGenerator())->definition($blockName));
        $block = $this->blocks->createBlock(
            $blockName,
            array('wrappers' => array_map(static fn (array $wrapper): array => array('tagName' => $wrapper['tagName'], 'attributes' => $wrapper['attributes']), $wrappers)),
            $innerBlocks,
            $sourceElement
        );
        // layout-shell's save function reads wrappers only, not className.
        // Source block creation can add projected selector carriers, so retain
        // those on the outer wrapper in both the save attributes and HTML.
        if (isset($elements[0])) {
            $wrappers[0] = $this->layoutShellWrapper($elements[0], $fieldBox, (string) ($block['attrs']['className'] ?? ''));
            $block['attrs']['wrappers'][0]['attributes'] = $wrappers[0]['attributes'];
        }
        $opening = implode('', array_column($wrappers, 'opening'));
        $closing = implode('', array_reverse(array_column($wrappers, 'closing')));
        $block['innerHTML'] = $opening . $closing;
        $block['innerContent'] = array_merge(array($opening), array_fill(0, count($innerBlocks), null), array($closing));
        $block['_layout_shell_wrappers'] = $wrappers;

        return $block;
    }

    /**
     * One source element as a layout-shell wrapper: its tag, its static
     * attributes and its projected class list. A field box frames a provider
     * field that renders its own label, so a source `<label>` box becomes a
     * `div` (its tag marker class keeps label selectors matching).
     *
     * @return array{tagName: string, attributes: array<string, mixed>, opening: string, closing: string}
     */
    private function layoutShellWrapper(DOMElement $element, bool $fieldBox = false, string $className = ''): array
    {
        $sourceTagName = strtolower($element->tagName);
        $tagName = str_contains($sourceTagName, '-') || ($fieldBox && 'label' === $sourceTagName) ? 'div' : $sourceTagName;
        $attributes = SourceDom::htmlAttributes($element);
        if ($fieldBox && 'label' === $sourceTagName) {
            unset($attributes['for']);
        }
        $className = SourceDom::mergeClassNames($this->layoutShellClassName($element), $className);
        if ( '' === $className ) {
            unset($attributes['class']);
        } else {
            $attributes['class'] = $className;
        }
        foreach ( $attributes as $name => $value ) {
            if ( LayoutShellBlockGenerator::isBooleanAttribute($name) ) {
                $attributes[$name] = true;
            }
        }
        $opening = '<' . $tagName;
        foreach ( $attributes as $name => $value ) {
            if ( ! preg_match('/^[a-z_:][a-z0-9_.:-]*$/i', $name) ) {
                continue;
            }
            $opening .= true === $value
                ? ' ' . $name . '=""'
                : ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $opening .= '>';

        return array(
            'tagName' => $tagName,
            'attributes' => $attributes,
            'opening' => $opening,
            'closing' => '</' . $tagName . '>',
        );
    }

    private function layoutShellClassName(DOMElement $element): string
    {
        $classes = array_values(array_filter(preg_split('/\s+/', $this->styleResolver->promotedClassName(SourceDom::attr($element, 'class'))) ?: array()));
        $tagMarker = $this->session->authorSelectorProjectionState()->tagMarker(strtolower($element->tagName));
        if ( '' !== $tagMarker ) {
            $classes[] = $tagMarker;
        }
        if (SourceDom::isCaptureDeviceVisibilityCarrier($element)) return SourceDom::mergeClassNames(SourceDom::attr($element, 'class'), implode(' ', $classes));
        $runtime = array();
        foreach ( $this->runtimeIslands->runtimeDomSelectorsForElement($element) as $selector ) {
            if ( str_starts_with($selector, '.') ) {
                $runtime[substr($selector, 1)] = true;
            }
        }
        foreach ( SourceDom::classNames($element) as $class ) {
            if ( isset($runtime[$class]) || 1 === preg_match('/^blocks-engine-(?:attribute|editor-anchor)-/', $class) ) {
                $classes[] = $class;
            }
        }

        return implode(' ', array_values(array_unique(array_filter($classes, static fn (string $class): bool => '' !== $class))));
    }
}

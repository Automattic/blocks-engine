<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Preserves captured scroll behavior on an editable core Group. */
final class ScrollStateConverter implements ElementConverter
{
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly StyleResolver $styleResolver,
        /** @var Closure(DOMElement, string): string Source projection classes, as on any converted block. */
        private readonly Closure $sourceProjectionClassName,
        /**
         * @var Closure(DOMElement, array<string, mixed>, list<string>): array<string, mixed> The box-tree
         *      carrier every converted block gets (HtmlCompilation::projectBoxTreeCarrier()), withholding
         *      the given properties; an identity outside evidence routes.
         */
        private readonly Closure $boxTreeCarrier
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'true' !== SourceDom::attr($element, 'data-blocks-engine-scroll-state') ) {
            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->block($element, $fallbacks));
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    public function block(DOMElement $element, array &$fallbacks): array
    {
        $tagName = strtolower(trim($element->tagName));
        if (1 !== preg_match('/^[a-z][a-z0-9-]*$/', $tagName)) {
            $tagName = 'div';
        }
        $anchor = trim(SourceDom::attr($element, 'id'));
        $config = trim(SourceDom::attr($element, 'data-blocks-engine-scroll-state-config'));
        if ('' === $config || null === json_decode($config, true)) {
            $config = '{}';
        }
        $decodedConfig = json_decode($config, true);
        // The wrapper is rebuilt from id, class and config, so its inline
        // style would be lost with it. Custom properties defined there scope
        // the var() lookups of every descendant; carry them (with the rest of
        // the carried inline declarations) the way a converted group does. A
        // property the runtime toggles on the wrapper itself stays out of the
        // carrier, whose !important rule would pin it against the toggle.
        $toggled = array();
        foreach (ArrayField::array($decodedConfig, 'styleTargets') as $target) {
            if (is_array($target) && in_array($target['selector'] ?? '', array('', ':scope'), true) && is_array($target['properties'] ?? null)) {
                $toggled = array_merge($toggled, array_map('strtolower', array_map('strval', array_keys($target['properties']))));
            }
        }
        // Children first: converting them can mark this wrapper (for example a
        // header that releases a native navigation overlay's containing block).
        $children = $this->tree->children($element, $fallbacks, true);
        $className = ($this->sourceProjectionClassName)($element, trim(SourceDom::attr($element, 'class') . ' ' . $this->styleResolver->inlineGeometryClassName($element, $toggled)));

        $attrs = array_filter(array(
            'anchor' => $anchor,
            'className' => $className,
        ), static fn(mixed $value): bool => '' !== $value);
        $saveTag = in_array($tagName, array('div', 'header', 'footer', 'main', 'section', 'article', 'aside', 'nav'), true) ? $tagName : 'div';
        if ('div' !== $saveTag) $attrs['tagName'] = $saveTag;
        // On an evidence route the author stylesheets are retired, so the
        // wrapper's observed box rides a carrier class like any converted
        // group's. The runtime toggles its own properties on the wrapper, so
        // those stay out of the carrier and the toggle still wins.
        $attrs = ($this->boxTreeCarrier)($element, $attrs, array_values(array_unique($toggled)));
        $className = (string) ($attrs['className'] ?? '');
        $attrs['metadata']['blocksEngineScrollState'] = array(
            'tagName' => $tagName,
            'config' => $config,
        );

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Core owns the saved shape; the theme restores the source shell at render time.
        $opening = '<' . $saveTag;
        if ('' !== $anchor) $opening .= ' id="' . $escape($anchor) . '"';
        $opening .= ' class="wp-block-group' . ('' !== $className ? ' ' . $escape($className) : '') . '">';
        $closing = '</' . $saveTag . '>';
        $innerContent = array($opening);
        foreach ($children as $_) $innerContent[] = null;
        $innerContent[] = $closing;

        return array(
            'blockName' => 'core/group',
            'attrs' => $attrs,
            'innerBlocks' => $children,
            'innerHTML' => $opening . $closing,
            'innerContent' => $innerContent,
        );
    }
}

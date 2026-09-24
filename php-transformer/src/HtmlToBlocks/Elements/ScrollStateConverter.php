<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ScrollStateBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use LogicException;

/** Preserves a captured scroll-driven class/style toggle as a companion block. */
final class ScrollStateConverter implements ElementConverter
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     * @param Closure(DOMElement, array<int, string>): string $inlineCarrierClassName the generated carrier
     *        class restating the element's carried inline declarations (custom properties its descendants
     *        read, inline geometry) minus the excluded properties, or '' when nothing needs carrying
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $convertChildren,
        private readonly Closure $inlineCarrierClassName
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
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
        $registry = $this->session->generatedBlockRegistry()
            ?? throw new LogicException('Generated block registry has not been prepared for this transform.');
        $blockName = $registry->blockName(ScrollStateBlockGenerator::LOCAL_NAME);
        $registry->register(ScrollStateBlockGenerator::class, (new ScrollStateBlockGenerator())->definition($blockName));

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
        foreach (is_array($decodedConfig['styleTargets'] ?? null) ? $decodedConfig['styleTargets'] : array() as $target) {
            if (is_array($target) && in_array($target['selector'] ?? '', array('', ':scope'), true) && is_array($target['properties'] ?? null)) {
                $toggled = array_merge($toggled, array_map('strtolower', array_map('strval', array_keys($target['properties']))));
            }
        }
        $className = trim(SourceDom::attr($element, 'class') . ' ' . ($this->inlineCarrierClassName)($element, $toggled));

        $attrs = array_filter(array(
            'tagName' => 'div' === $tagName ? '' : $tagName,
            'anchor' => $anchor,
            'className' => $className,
            'config' => '{}' === $config ? '' : $config,
        ), static fn(mixed $value): bool => '' !== $value);

        $children = ($this->convertChildren)($element, $fallbacks);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $opening = '<' . $tagName;
        if ('' !== $anchor) $opening .= ' id="' . $escape($anchor) . '"';
        if ('' !== $className) $opening .= ' class="' . $escape($className) . '"';
        $opening .= ' data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . $escape($config) . '">';
        $closing = '</' . $tagName . '>';
        $innerContent = array($opening);
        foreach ($children as $_) $innerContent[] = null;
        $innerContent[] = $closing;

        return array(
            'blockName' => $blockName,
            'attrs' => $attrs,
            'innerBlocks' => $children,
            'innerHTML' => $opening . $closing,
            'innerContent' => $innerContent,
        );
    }
}

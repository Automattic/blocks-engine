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
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $convertChildren
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
        $className = trim(SourceDom::attr($element, 'class'));
        $config = trim(SourceDom::attr($element, 'data-blocks-engine-scroll-state-config'));
        if ('' === $config || null === json_decode($config, true)) {
            $config = '{}';
        }

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

<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Materializes a captured selectable set into the core/tabs family. */
final class CapturedSelectableSetConverter implements ElementConverter
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     */
    public function __construct(
        private readonly SourceBlockCreator $createBlock,
        private readonly ElementPresentationResolver $presentation,
        private readonly Closure $convertChildren
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ('true' !== SourceDom::attr($element, 'data-blocks-engine-captured-selectable-set')) {
            return ConversionOutcome::unhandled();
        }

        $block = $this->block($element, $fallbacks);
        return null === $block ? ConversionOutcome::unhandled() : ConversionOutcome::handled($block);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function block(DOMElement $element, array &$fallbacks): ?array
    {
        $tabList = null;
        $panels = array();
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                if (XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '')) {
                    return null;
                }
                continue;
            }
            $role = strtolower(trim(SourceDom::attr($child, 'role')));
            if ('tablist' === $role) {
                $tabList = $child;
                continue;
            }
            if ('tabpanel' === $role) {
                $panels[] = $child;
            }
        }
        if (! $tabList instanceof DOMElement || count($panels) < 2) {
            return null;
        }

        $labels = array();
        foreach ($tabList->getElementsByTagName('*') as $candidate) {
            if (! $candidate instanceof DOMElement || 'tab' !== strtolower(trim(SourceDom::attr($candidate, 'role')))) {
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', $candidate->textContent ?? '') ?? '');
            if ('' === $label) {
                return null;
            }
            $labels[] = array('label' => $label);
        }
        if (count($labels) !== count($panels)) {
            return null;
        }

        $panelBlocks = array();
        foreach ($panels as $index => $panel) {
            $children = ($this->convertChildren)($panel, $fallbacks);
            if (array() === $children) {
                $text = trim(preg_replace('/\s+/', ' ', $panel->textContent ?? '') ?? '');
                if ('' === $text) {
                    return null;
                }
                $children = array($this->createBlock->createBlock('core/paragraph', array('content' => $text), array(), $panel));
            }
            $panelBlocks[] = $this->createBlock->createBlock('core/tab-panel', array_filter(array_merge(
                $this->presentation->presentationAttributes($panel),
                array(
                    'anchor' => trim(SourceDom::attr($panel, 'id')),
                    'label' => $labels[$index]['label'],
                )
            ), static fn ($value): bool => '' !== $value), $children, $panel);
        }

        $tabListAttributes = array_merge($this->presentation->presentationAttributes($tabList), array('tabs' => $labels));
        unset($tabListAttributes['anchor']);
        $ariaLabel = trim(SourceDom::attr($tabList, 'aria-label'));
        if ('' !== $ariaLabel) {
            $tabListAttributes['ariaLabel'] = $ariaLabel;
        }

        return $this->createBlock->createBlock('core/tabs', $this->presentation->presentationAttributes($element), array(
            $this->createBlock->createBlock('core/tab-list', $tabListAttributes, array(), $tabList),
            $this->createBlock->createBlock('core/tab-panels', array(), $panelBlocks),
        ), $element);
    }
}

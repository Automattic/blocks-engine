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
    public const VISUALLY_HIDDEN_TABLIST_CLASS = 'blocks-engine-tablist-visually-hidden';

    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     * @param Closure(): string $visuallyHiddenClassName
     */
    public function __construct(
        private readonly SourceBlockCreator $createBlock,
        private readonly ElementPresentationResolver $presentation,
        private readonly Closure $convertChildren,
        private readonly Closure $visuallyHiddenClassName
    ) {
    }

    public static function visuallyHiddenTabListCss(string $className = self::VISUALLY_HIDDEN_TABLIST_CLASS): string
    {
        return '.' . $className . '{border:0;clip:rect(0,0,0,0);clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;white-space:nowrap;width:1px}'
            . '.editor-styles-wrapper .' . $className . ',.block-editor-iframe__body .' . $className . '{clip:auto;clip-path:none;height:auto;margin:0;overflow:visible;position:static;white-space:normal;width:auto}';
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
        if ('hidden' === strtolower(trim(SourceDom::attr($tabList, 'data-blocks-engine-tablist-presentation')))) {
            $hiddenClass = trim(($this->visuallyHiddenClassName)());
            if ('' !== $hiddenClass) {
                $tabListAttributes['className'] = trim((string) ($tabListAttributes['className'] ?? '') . ' ' . $hiddenClass);
            }
        }

        return $this->createBlock->createBlock('core/tabs', $this->presentation->presentationAttributes($element), array(
            $this->createBlock->createBlock('core/tab-list', $tabListAttributes, array(), $tabList),
            $this->createBlock->createBlock('core/tab-panels', array(), $panelBlocks),
        ), $element);
    }
}

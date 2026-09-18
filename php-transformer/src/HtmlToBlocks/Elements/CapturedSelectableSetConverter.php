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
    public const TABLIST_ROW_ATTRIBUTE = 'data-blocks-engine-tablist-row';

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
        if ('' !== SourceDom::attr($element, self::TABLIST_ROW_ATTRIBUTE)
            && 'tablist' !== strtolower(trim(SourceDom::attr($element, 'role')))
            && 'true' !== SourceDom::attr($element, 'data-blocks-engine-captured-selectable-set')
        ) {
            return ConversionOutcome::handled(null);
        }
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

        $hidden = 'hidden' === strtolower(trim(SourceDom::attr($tabList, 'data-blocks-engine-tablist-presentation')));
        $row = $hidden ? null : $this->triggerRowSource($tabList);
        if ($row instanceof DOMElement) {
            $tabListAttributes = array_merge($this->presentation->presentationAttributes($row), array('tabs' => $labels));
            $tabListSource = null;
        } elseif (! $hidden) {
            $tabListAttributes = array_filter(array(
                'className' => trim(SourceDom::attr($tabList, 'class')),
                'tabs' => $labels,
            ), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
            $tabListSource = null;
        } else {
            $tabListAttributes = array_merge($this->presentation->presentationAttributes($tabList), array('tabs' => $labels));
            $tabListSource = $tabList;
        }
        unset($tabListAttributes['anchor']);
        $ariaLabel = trim(SourceDom::attr($tabList, 'aria-label'));
        if ('' !== $ariaLabel) {
            $tabListAttributes['ariaLabel'] = $ariaLabel;
        }
        if ($hidden) {
            $hiddenClass = trim(($this->visuallyHiddenClassName)());
            if ('' !== $hiddenClass) {
                $tabListAttributes['className'] = trim((string) ($tabListAttributes['className'] ?? '') . ' ' . $hiddenClass);
            }
        }

        return $this->createBlock->createBlock('core/tabs', $this->presentation->presentationAttributes($element), array(
            $this->createBlock->createBlock('core/tab-list', $tabListAttributes, array(), $tabListSource),
            $this->createBlock->createBlock('core/tab-panels', array(), $panelBlocks),
        ), $element);
    }

    private function triggerRowSource(DOMElement $tabList): ?DOMElement
    {
        $identity = trim(SourceDom::attr($tabList, self::TABLIST_ROW_ATTRIBUTE));
        if ('' === $identity || 1 !== preg_match('/^[A-Za-z0-9-]+$/', $identity)) {
            return null;
        }
        $document = $tabList->ownerDocument;
        if (! $document instanceof \DOMDocument) {
            return null;
        }
        foreach ((new \DOMXPath($document))->query('//*[@' . self::TABLIST_ROW_ATTRIBUTE . '="' . $identity . '"]') ?: array() as $node) {
            if ($node instanceof DOMElement && ! $node->isSameNode($tabList)) {
                return $node;
            }
        }

        return null;
    }
}

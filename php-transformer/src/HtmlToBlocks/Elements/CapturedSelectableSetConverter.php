<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedSelectableSetProjector;
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
    public const FLOW_CLASS = 'blocks-engine-tabs-flow';
    public const FLOW_LIST_LAST_CLASS = 'blocks-engine-tabs-flow-list-last';

    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren
     * @param Closure(): string $visuallyHiddenClassName
     * @param Closure(string, string): void $registerRule Registers one stylesheet rule under a class name.
     * @param Closure(bool): string $flowClassName Registers and returns the class that lets the tab-list and tab-panels lay out as the source's trigger row and region did.
     */
    public function __construct(
        private readonly SourceBlockCreator $createBlock,
        private readonly ElementPresentationResolver $presentation,
        private readonly Closure $convertChildren,
        private readonly Closure $visuallyHiddenClassName,
        private readonly Closure $flowClassName,
        private readonly Closure $registerRule
    ) {
    }

    public static function visuallyHiddenTabListCss(string $className = self::VISUALLY_HIDDEN_TABLIST_CLASS): string
    {
        return '.' . $className . '{border:0;clip:rect(0,0,0,0);clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;white-space:nowrap;width:1px}'
            . '.editor-styles-wrapper .' . $className . ',.block-editor-iframe__body .' . $className . '{clip:auto;clip-path:none;height:auto;margin:0;overflow:visible;position:static;white-space:normal;width:auto}';
    }

    /**
     * Neither wrapper box existed in the source: the tabs block stands in for
     * a region that was a sibling of the trigger row, so its two children
     * must stay direct layout children of the shared parent.
     */
    public static function flowCss(string $className = self::FLOW_CLASS): string
    {
        return '.' . $className . '{display:contents}';
    }

    public static function flowListLastCss(string $className = self::FLOW_LIST_LAST_CLASS): string
    {
        return '.' . self::FLOW_CLASS . '.' . $className . '>.wp-block-tab-list{order:1}';
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        // The surviving source trigger row shares the projected tab list's row
        // identity and is replaced by it. A source row can itself be role=tablist
        // (ARIA tab widgets), so only the tab list projected inside the region
        // is kept; the source row is dropped either way.
        $parent = $element->parentNode;
        $projected = $parent instanceof DOMElement
            && 'true' === SourceDom::attr($parent, 'data-blocks-engine-captured-selectable-set');
        if ('' !== SourceDom::attr($element, self::TABLIST_ROW_ATTRIBUTE)
            && ! $projected
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
            $label = trim(SourceDom::attr($candidate, CapturedSelectableSetProjector::LABEL_ATTRIBUTE));
            $markup = '';
            if ('' !== $label) {
                foreach ($candidate->childNodes as $node) {
                    $markup .= (string) $candidate->ownerDocument?->saveHTML($node);
                }
            } else {
                $label = trim(preg_replace('/\s+/', ' ', $candidate->textContent ?? '') ?? '');
            }
            if ('' === $label) {
                return null;
            }
            $labels[] = array('label' => '' !== $markup ? $markup : $label, 'plain' => $label);
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
                    'label' => $labels[$index]['plain'],
                )
            ), static fn ($value): bool => '' !== $value), $children, $panel);
        }

        $tabs = array_map(static fn (array $entry): array => array('label' => $entry['label']), $labels);
        $hidden = 'hidden' === strtolower(trim(SourceDom::attr($tabList, 'data-blocks-engine-tablist-presentation')));
        $row = $hidden ? null : $this->triggerRowSource($tabList);
        if ($row instanceof DOMElement) {
            $tabListAttributes = array_merge($this->presentation->presentationAttributes($row), array('tabs' => $tabs));
            $tabListSource = null;
        } elseif (! $hidden) {
            $tabListAttributes = array_filter(array(
                'className' => trim(SourceDom::attr($tabList, 'class')),
                'tabs' => $tabs,
            ), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
            $tabListSource = null;
        } else {
            $tabListAttributes = array_merge($this->presentation->presentationAttributes($tabList), array('tabs' => $tabs));
            $tabListSource = $tabList;
        }
        unset($tabListAttributes['anchor']);
        if ($row instanceof DOMElement) {
            $triggerClass = $this->triggerPresentationClass($row, $tabList);
            if ('' !== $triggerClass) {
                $tabListAttributes['className'] = trim((string) ($tabListAttributes['className'] ?? '') . ' ' . $triggerClass);
            }
        }
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

        $tabsAttributes = array();
        $active = (int) SourceDom::attr($element, CapturedSelectableSetProjector::ACTIVE_TAB_ATTRIBUTE);
        if ($active > 0 && $active < count($panelBlocks)) {
            $tabsAttributes['activeTabIndex'] = $active;
        }
        $flow = SourceDom::attr($element, CapturedSelectableSetProjector::FLOW_ATTRIBUTE);
        if (in_array($flow, array(CapturedSelectableSetProjector::FLOW_INLINE, CapturedSelectableSetProjector::FLOW_LIST_LAST), true) && ! $hidden) {
            $tabsAttributes['className'] = trim(($this->flowClassName)(CapturedSelectableSetProjector::FLOW_LIST_LAST === $flow));
        }

        return $this->createBlock->createBlock('core/tabs', $tabsAttributes, array(
            $this->createBlock->createBlock('core/tab-list', $tabListAttributes, array(), $tabListSource),
            $this->createBlock->createBlock('core/tab-panels', array(), $panelBlocks),
        ));
    }

    /**
     * core/tab-list renders its own flex row of plain buttons. Carry the source
     * trigger list's orientation and the trigger's box styling onto the
     * rendered tab-list, so a vertical stack of bordered rows stays one.
     */
    private function triggerPresentationClass(DOMElement $row, DOMElement $tabList): string
    {
        $identity = trim(SourceDom::attr($tabList, self::TABLIST_ROW_ATTRIBUTE));
        $document = $tabList->ownerDocument;
        if ('' === $identity || ! $document instanceof \DOMDocument) {
            return '';
        }
        $triggers = array();
        foreach ((new \DOMXPath($document))->query('//*[@' . CapturedSelectableSetProjector::TRIGGER_ATTRIBUTE . '="' . $identity . '"]') ?: array() as $node) {
            if ($node instanceof DOMElement) {
                $triggers[] = $node;
            }
        }
        if (array() === $triggers) {
            return '';
        }

        $rowDeclarations = $this->presentation->presentationDeclarations($row);
        $items = array();
        foreach ($triggers as $trigger) {
            for ($node = $trigger; $node instanceof DOMElement && $node->parentNode instanceof DOMElement; $node = $node->parentNode) {
                if ($node->parentNode->isSameNode($row)) {
                    $items[] = $node;
                    break;
                }
            }
        }
        $display = strtolower(trim((string) ($rowDeclarations['display'] ?? '')));
        $direction = strtolower(trim((string) ($rowDeclarations['flex-direction'] ?? '')));
        if (str_contains($display, 'flex')) {
            $vertical = str_starts_with($direction, 'column');
        } else {
            $itemDisplay = array() === $items ? '' : strtolower(trim((string) ($this->presentation->presentationDeclarations($items[0])['display'] ?? '')));
            $itemTag = array() === $items ? '' : strtolower($items[0]->tagName);
            $vertical = '' !== $itemDisplay
                ? ! str_starts_with($itemDisplay, 'inline')
                : in_array($itemTag, array('li', 'div', 'p', 'section', 'article'), true);
        }

        $declarations = $this->safeDeclarations($this->presentation->presentationDeclarations($triggers[0]));
        $gap = trim((string) ($rowDeclarations['row-gap'] ?? $rowDeclarations['gap'] ?? ''));
        if ('' === $gap && isset($items[1])) {
            $gap = trim((string) ($this->presentation->presentationDeclarations($items[1])['margin-top'] ?? ''));
        }
        $listRule = array();
        if ($vertical) {
            $listRule = array('flex-direction' => 'column', 'flex-wrap' => 'nowrap', 'align-items' => 'stretch');
            if ('' !== $gap && 1 === count($this->safeDeclarations(array('row-gap' => $gap)))) {
                $listRule['row-gap'] = $gap;
            }
        }
        if (array() === $listRule && array() === $declarations) {
            return '';
        }
        $body = static fn (array $rules): string => implode(';', array_map(static fn (string $property, string $value): string => $property . ':' . $value, array_keys($rules), $rules));
        $className = 'blocks-engine-tab-list-' . substr(md5($body($listRule) . '|' . $body($declarations)), 0, 10);
        $css = array();
        if (array() !== $listRule) {
            $css[] = '.wp-block-tab-list.' . $className . '{' . $body($listRule) . '}';
        }
        if (array() !== $declarations) {
            $css[] = '.wp-block-tab-list.' . $className . ' button{' . $body($declarations) . '}';
        }
        ($this->registerRule)($className, implode("\n", $css));

        return $className;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function safeDeclarations(array $declarations): array
    {
        $safe = array();
        foreach ($declarations as $property => $value) {
            $property = strtolower(trim((string) $property));
            $value = trim((string) $value);
            if (1 !== preg_match('/^[a-z-]+$/', $property) || '' === $value || 1 === preg_match('/[{};<>\\\\]|url\(|expression\(|@import/i', $value)) {
                continue;
            }
            $safe[$property] = $value;
        }

        return $safe;
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

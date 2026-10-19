<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
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
        // The member visible at rest; capture records it as the active index
        // (absent means the first member).
        $active = max(0, (int) SourceDom::attr($element, CapturedSelectableSetProjector::ACTIVE_TAB_ATTRIBUTE));
        if ($row instanceof DOMElement) {
            $triggerClass = $this->triggerPresentationClass($row, $tabList, $active < count($panels) ? $active : 0, count($panels));
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
    private function triggerPresentationClass(DOMElement $row, DOMElement $tabList, int $selectedIndex, int $memberCount): string
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
        $display = CssValueInspector::comparable((string) ($rowDeclarations['display'] ?? ''));
        $direction = strtolower(trim((string) ($rowDeclarations['flex-direction'] ?? '')));
        if (str_contains($display, 'flex')) {
            $vertical = str_starts_with($direction, 'column');
        } else {
            $itemDisplay = array() === $items ? '' : CssValueInspector::comparable((string) ($this->presentation->presentationDeclarations($items[0])['display'] ?? ''));
            $itemTag = array() === $items ? '' : strtolower($items[0]->tagName);
            $vertical = '' !== $itemDisplay
                ? ! str_starts_with($itemDisplay, 'inline')
                : in_array($itemTag, array('li', 'div', 'p', 'section', 'article'), true);
        }

        // The source marks the selected trigger with state attributes its own CSS
        // keys on (aria-selected, data-state). Carry the unselected look to every
        // button and only the selected trigger's differences to the active one.
        $active = null;
        foreach ($triggers as $trigger) {
            if ('true' === strtolower(trim(SourceDom::attr($trigger, 'aria-selected')))
                || 'active' === strtolower(trim(SourceDom::attr($trigger, 'data-state')))) {
                $active ??= $trigger;
            }
        }
        // A source that marks selection only with classes (a JS toggle of
        // `.on`) leaves no state attribute. The captured set still knows which
        // member was visible at rest, so that member's trigger is the selected one.
        if (null === $active && count($triggers) === $memberCount) {
            $active = $triggers[$selectedIndex] ?? null;
        }
        $base = $triggers[0];
        foreach ($triggers as $trigger) {
            if (null === $active || ! $trigger->isSameNode($active)) {
                $base = $trigger;
                break;
            }
        }
        $declarations = $this->safeDeclarations($this->triggerDeclarations($base));
        $activeDeclarations = array();
        if ($active instanceof DOMElement && ! $active->isSameNode($base)) {
            $activeDeclarations = array_diff_assoc(
                $this->safeDeclarations($this->triggerDeclarations($active)),
                $declarations
            );
        }
        // core/tab-list sets `flex-grow` and `flex-basis` on its buttons to
        // `inherit !important`, so a source trigger that stretches (flex: 1) can
        // only do so through the tab list element itself.
        [$declarations, $listFlex] = $this->splitInheritedFlex($declarations);
        $gap = trim((string) ($rowDeclarations['row-gap'] ?? $rowDeclarations['gap'] ?? ''));
        if ('' === $gap && isset($items[1])) {
            $gap = trim((string) ($this->presentation->presentationDeclarations($items[1])['margin-top'] ?? ''));
        }
        $listRule = array();
        $conditionalWrap = array();
        if ($vertical) {
            $listRule = array('flex-direction' => 'column', 'flex-wrap' => 'nowrap', 'align-items' => 'stretch');
            if ('' !== $gap && 1 === count($this->safeDeclarations(array('row-gap' => $gap)))) {
                $listRule['row-gap'] = $gap;
            }
        } elseif (str_contains($display, 'flex')) {
            // Core's tab list wraps by default; a source row that scrolled
            // sideways (`nowrap`, the flex initial value) must stay one row.
            [$wrap, $conditionalWrap] = $this->rowFlexWrap($row, $rowDeclarations);
            $listRule['flex-wrap'] = $wrap;
        }
        $listRule = array_merge($listRule, $listFlex);
        if (array() === $listRule && array() === $declarations && array() === $activeDeclarations) {
            return '';
        }
        // Core marks the selected tab with a `::before` underline the source's
        // selected trigger never drew; a carried trigger look replaces it.
        $hideUnderline = $active instanceof DOMElement && ! $this->drawsUnderline($active);
        $body = static fn (array $rules): string => implode(';', array_map(static fn (string $property, string $value): string => $property . ':' . $value, array_keys($rules), $rules));
        $className = 'blocks-engine-tab-list-' . substr(md5($body($listRule) . '|' . serialize($conditionalWrap) . '|' . $body($declarations) . '|' . $body($activeDeclarations) . '|' . ($hideUnderline ? 'u' : '')), 0, 10);
        $css = array();
        if (array() !== $listRule) {
            $css[] = '.wp-block-tab-list.' . $className . '{' . $body($listRule) . '}';
        }
        foreach ($conditionalWrap as $condition => $value) {
            $css[] = $condition . '{.wp-block-tab-list.' . $className . '{flex-wrap:' . $value . '}' . str_repeat('}', substr_count($condition, '{') + 1);
        }
        if (array() !== $declarations) {
            $css[] = '.wp-block-tab-list.' . $className . ' button{' . $body($declarations) . '}';
        }
        if (array() !== $activeDeclarations) {
            $css[] = '.wp-block-tab-list.' . $className . ' button[aria-selected="true"]{' . $body($activeDeclarations) . '}';
        }
        if ($hideUnderline) {
            $css[] = '.wp-block-tab-list.' . $className . ' button[aria-selected="true"]::before{display:none}';
        }
        ($this->registerRule)($className, implode("\n", $css));

        return $className;
    }

    /**
     * The source row's `flex-wrap`: its resting value plus every viewport
     * query that changes it. An inline declaration wins over all of them.
     *
     * @param array<string, string> $rowDeclarations
     * @return array{0: string, 1: array<string, string>}
     */
    private function rowFlexWrap(DOMElement $row, array $rowDeclarations): array
    {
        $inline = strtolower(SourceDom::attr($row, 'style'));
        if (1 === preg_match('/(?:^|;)\s*flex-(?:wrap|flow)\s*:/', $inline)) {
            return array($this->wrapToken((string) ($rowDeclarations['flex-wrap'] ?? ''), (string) ($rowDeclarations['flex-flow'] ?? '')), array());
        }
        $wrap = $this->presentation->declaredPresentation($row, 'flex-wrap');
        $flow = $this->presentation->declaredPresentation($row, 'flex-flow');
        if ($wrap->isEmpty() && $flow->isEmpty()) {
            $base = $this->wrapToken((string) ($rowDeclarations['flex-wrap'] ?? ''), (string) ($rowDeclarations['flex-flow'] ?? ''));
        } else {
            $base = $this->wrapToken($wrap->base(), $flow->base());
        }
        $conditional = array();
        foreach ($flow->conditional() as $condition => $value) {
            $conditional[$condition] = $this->wrapToken('', $value);
        }
        foreach ($wrap->conditional() as $condition => $value) {
            $conditional[$condition] = $this->wrapToken($value, '');
        }
        $conditional = array_filter(
            $conditional,
            static fn (string $value, string $condition): bool => 1 === preg_match('/^@(?:media|supports|container)\b[^;<>}]*$/i', $condition),
            ARRAY_FILTER_USE_BOTH
        );

        return array($base, $conditional);
    }

    /** Resolve `flex-wrap` (or the `flex-flow` shorthand) to its keyword; unset is `nowrap`. */
    private function wrapToken(string $wrap, string $flow): string
    {
        foreach (array($wrap, $flow) as $value) {
            foreach (preg_split('/\s+/', CssValueInspector::comparable($value)) ?: array() as $token) {
                if (in_array($token, array('nowrap', 'wrap', 'wrap-reverse'), true)) {
                    return $token;
                }
            }
        }

        return 'nowrap';
    }

    /**
     * Properties that size a trigger as a row item and keep its label on one
     * line. The presentation cascade leaves them out as structural, but a tab
     * button that loses them shrinks and wraps inside a scrolling row.
     */
    private const TRIGGER_ITEM_SIZING_PROPERTIES = array(
        'white-space',
        'white-space-collapse',
        'text-wrap',
        'text-wrap-mode',
        'flex',
        'flex-grow',
        'flex-shrink',
        'flex-basis',
        'min-width',
    );

    /**
     * The trigger's paint plus its source item sizing and text wrapping. The
     * presentation value wins where both carry a property.
     *
     * @return array<string, string>
     */
    private function triggerDeclarations(DOMElement $trigger): array
    {
        $declarations = $this->presentation->presentationDeclarations($trigger);
        $sizing = array_intersect_key(
            $this->presentation->authorStructuralDeclarations($trigger),
            array_flip(self::TRIGGER_ITEM_SIZING_PROPERTIES)
        );
        foreach ($sizing as $property => $value) {
            $declarations[$property] ??= (string) $value;
        }

        return $declarations;
    }

    /** Does the source's selected trigger draw its own underline marker? */
    private function drawsUnderline(DOMElement $trigger): bool
    {
        $declarations = $this->presentation->presentationDeclarations($trigger);
        foreach (array('text-decoration', 'text-decoration-line') as $property) {
            if (str_contains(strtolower((string) ($declarations[$property] ?? '')), 'underline')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split the flex sizing core/tab-list takes from its own element
     * (`flex-grow`, `flex-basis`) from the declarations that stay on a button.
     *
     * @param array<string, string> $declarations
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function splitInheritedFlex(array $declarations): array
    {
        $list = array();
        if (isset($declarations['flex'])) {
            $tokens = preg_split('/\s+/', strtolower(trim($declarations['flex']))) ?: array();
            $grow = null;
            $shrink = null;
            $basis = null;
            if (array('none') === $tokens) {
                $grow = '0';
                $shrink = '0';
                $basis = 'auto';
            } elseif (array('auto') === $tokens) {
                $grow = '1';
                $shrink = '1';
                $basis = 'auto';
            } elseif (isset($tokens[0]) && 1 === preg_match('/^[0-9.]+$/', $tokens[0])) {
                $grow = $tokens[0];
                $rest = array_slice($tokens, 1);
                if (isset($rest[0]) && 1 === preg_match('/^[0-9.]+$/', $rest[0])) {
                    $shrink = array_shift($rest);
                }
                $basis = array() === $rest ? '0%' : implode(' ', $rest);
            }
            if (null !== $grow) {
                $list['flex-grow'] = $grow;
                $list['flex-basis'] = $basis;
                if (null !== $shrink) {
                    $declarations['flex-shrink'] = $shrink;
                }
            }
            unset($declarations['flex']);
        }
        foreach (array('flex-grow', 'flex-basis') as $property) {
            if (isset($declarations[$property])) {
                $list[$property] = $declarations[$property];
                unset($declarations[$property]);
            }
        }

        return array($declarations, $list);
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

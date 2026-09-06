<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class TabsPattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $converter = $context->recursiveConverter();
        if ( null === $converter || $this->hasRuntimeHeavyDescendant($element) ) {
            return null;
        }

        $tabList = $this->tabListElement($element);
        $controls = $tabList instanceof DOMElement
            ? $this->ariaControls($tabList)
            : $this->radioControls($element);
        $panels = $this->panelElements($element);
        $allowPositionalPairing = $tabList instanceof DOMElement
            && ($this->hasTabIdentity($element) || $this->hasTabIdentity($tabList));
        if ( count($controls) < 2 || count($controls) !== count($panels) ) {
            return null;
        }

        $labels = array();
        $activeIndex = null;
        foreach ( $controls as $index => $control ) {
            $label = $control['label'];
            if ( '' === trim(strip_tags($label)) || ! $this->controlMatchesPanel($control['target'], $panels[$index], $allowPositionalPairing) ) {
                return null;
            }
            $labels[] = array( 'label' => $label );
            if ( $control['active'] ) {
                if ( $context->sourceElementStartsHidden($panels[$index]) ) {
                    return null;
                }
                if ( null !== $activeIndex ) {
                    return null;
                }
                $activeIndex = $index;
            }
        }
        if ( null === $activeIndex ) {
            return null;
        }

        $fallbacks = array();
        $panelBlocks = array();
        foreach ( $panels as $index => $panel ) {
            $children = $converter->children($panel, $fallbacks, true);
            if ( array() === $children ) {
                return null;
            }
            $panelBlocks[] = $context->createBlock('core/tab-panel', array_filter(array_merge(
                $context->presentationAttributes($panel),
                array(
                    'anchor' => $this->trimmedAttribute($panel, 'id'),
                    'label' => $labels[$index]['label'],
                )
            ), static fn ($value): bool => '' !== $value), $children, $panel);
        }

        $tabListSource = $tabList ?? $element;
        $tabListPresentation = $tabList instanceof DOMElement ? $context->presentationAttributes($tabList) : array();
        unset($tabListPresentation['anchor']);
        $tabListAttributes = array_merge($tabListPresentation, array( 'tabs' => $labels ));
        $ariaLabel = $this->trimmedAttribute($tabListSource, 'aria-label');
        if ( '' !== $ariaLabel ) {
            $tabListAttributes['ariaLabel'] = $ariaLabel;
        }

        $tabsAttributes = $context->presentationAttributes($element);
        if ( null !== $activeIndex && 0 !== $activeIndex ) {
            $tabsAttributes['activeTabIndex'] = $activeIndex;
        }

        return new PatternRecognitionResult(
            $context->createBlock('core/tabs', $tabsAttributes, array(
                $context->createBlock('core/tab-list', $tabListAttributes, array(), $tabListSource),
                $context->createBlock('core/tab-panels', array(), $panelBlocks),
            ), $element),
            $fallbacks
        );
    }

    private function tabListElement(DOMElement $element): ?DOMElement
    {
        foreach ( $this->directChildElements($element) as $child ) {
            if ( 'tablist' === strtolower($this->trimmedAttribute($child, 'role')) ) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<array{label: string, target: string, active: bool}> */
    private function ariaControls(DOMElement $tabList): array
    {
        $controls = array();
        foreach ( $tabList->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement && 'tab' === strtolower($this->trimmedAttribute($candidate, 'role')) ) {
                $controls[] = array(
                    'label' => $this->labelHtml($candidate),
                    'target' => $this->trimmedAttribute($candidate, 'aria-controls') ?: $this->trimmedAttribute($candidate, 'data-tab'),
                    'active' => 'true' === strtolower($this->trimmedAttribute($candidate, 'aria-selected')),
                );
            }
        }

        return $controls;
    }

    /** @return list<array{label: string, target: string, active: bool}> */
    private function radioControls(DOMElement $element): array
    {
        if ( ! SourceDom::hasClass($element, 'tabs') && ! $element->hasAttribute('data-tabs') ) {
            return array();
        }

        $labelsById = array();
        foreach ( $element->getElementsByTagName('label') as $label ) {
            if ( $label instanceof DOMElement && '' !== $this->trimmedAttribute($label, 'for') ) {
                $labelsById[$this->trimmedAttribute($label, 'for')] = $label;
            }
        }

        $controls = array();
        foreach ( $this->directChildElements($element) as $child ) {
            if ( 'input' !== strtolower($child->tagName) || 'radio' !== strtolower($this->trimmedAttribute($child, 'type')) ) {
                continue;
            }
            $id = $this->trimmedAttribute($child, 'id');
            if ( '' === $id || ! isset($labelsById[$id]) ) {
                return array();
            }
            $target = $this->trimmedAttribute($labelsById[$id], 'data-tab-target');
            $controls[] = array(
                'label' => $this->labelHtml($labelsById[$id]),
                'target' => '' !== $target ? $target : '.' . $id,
                'active' => $child->hasAttribute('checked'),
            );
        }

        return $controls;
    }

    /** @return list<DOMElement> */
    private function panelElements(DOMElement $element): array
    {
        $panels = array();
        foreach ( $this->directChildElements($element) as $child ) {
            $role = strtolower($this->trimmedAttribute($child, 'role'));
            $class = strtolower($this->trimmedAttribute($child, 'class'));
            if ( 'tabpanel' === $role || preg_match('/(?:^|[\s_-])(?:tab-)?panel(?:$|[\s_-])/', $class) ) {
                $panels[] = $child;
            }
        }

        return $panels;
    }

    private function labelHtml(DOMElement $element): string
    {
        $html = SourceDom::innerHtml($element);
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        return trim($html);
    }

    private function controlMatchesPanel(string $target, DOMElement $panel, bool $allowPositionalPairing): bool
    {
        if ( '' !== $target ) {
            if ( str_starts_with($target, '.') ) {
                return SourceDom::hasClass($panel, substr($target, 1));
            }
            return ltrim($target, '#') === $this->trimmedAttribute($panel, 'id');
        }

        return $allowPositionalPairing && '' === $this->trimmedAttribute($panel, 'id');
    }

    private function hasTabIdentity(DOMElement $element): bool
    {
        return 1 === preg_match('/(?:^|[\s_-])tabs?(?:$|[\s_-])/', strtolower($this->trimmedAttribute($element, 'class')))
            || $element->hasAttribute('data-tabs');
    }
}

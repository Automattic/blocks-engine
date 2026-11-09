<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CustomElementRuntimeDependency;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Drops fallback findings that a native block or an owning form fallback
 * already accounts for.
 */
final class FallbackReconciliation
{
    public function __construct(
        private readonly RuntimeIslandAnalyzer $runtimeIslands
    ) {
    }

    /**
     * Form classification must see the original conversion findings. Only after
     * conversion can the enclosing form claim a control-only unsupported group.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function reconcileFormControlGroupFallbacks(DOMElement $body, array &$fallbacks): void
    {
        $forms = array();
        $unsupported = array();
        foreach ( $fallbacks as $index => $finding ) {
            $selector = $finding['selector'] ?? '';
            if ( 'html_form_fallback' === ($finding['diagnostic_code'] ?? '') ) {
                $forms[$selector] = array_fill_keys(array_column($finding['controls'] ?? array(), 'selector'), true);
            } elseif ( 'html_unsupported_element' === ($finding['diagnostic_code'] ?? '') ) {
                $unsupported[$selector][] = $index;
            }
        }
        if ( array() === $forms || array() === $unsupported ) return;

        foreach ( $body->getElementsByTagName('*') as $element ) {
            $selector = SourceDom::elementSelector($element);
            if ( ! isset($unsupported[$selector]) ) continue;
            for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
                $owner = $forms[SourceDom::elementSelector($parent)] ?? null;
                if ( null === $owner ) continue;
                $controls = FormControlClassifier::controlElements($element);
                if ( array() !== $controls
                    && $this->runtimeIslands->isSafeTransparentCustomElement($element)
                    && $this->isFormOwnedControlStructure($element, $owner)
                ) {
                    foreach ( $unsupported[$selector] as $index ) unset($fallbacks[$index]);
                }
                break;
            }
        }
        $fallbacks = array_values($fallbacks);
    }

    /** @param array<string, bool> $controls */
    private function isFormOwnedControlStructure(DOMElement $element, array $controls): bool
    {
        if ( FormControlClassifier::isControlElement($element) ) {
            return isset($controls[SourceDom::elementSelector($element)]);
        }
        $tag = strtolower($element->tagName);
        if ( ! in_array($tag, array('div', 'span'), true) && ! str_contains($tag, '-') ) return false;
        if ( (new CustomElementRuntimeDependency())->hasConfigurationAttribute($element)
            || $element->hasAttribute('role') || $element->hasAttribute('aria-label') || $element->hasAttribute('aria-labelledby')
        ) return false;
        $hasChild = false;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $hasChild = true;
                if ( ! $this->isFormOwnedControlStructure($child, $controls) ) return false;
            } elseif ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return false;
            }
        }
        return $hasChild;
    }

    /** @param array<int, array<string, mixed>> $fallbacks @param array<int, array<string, mixed>> $blocks */
    public function reconcileNativeListItemFallbacks(array &$fallbacks, array $blocks): void
    {
        $nativeListItemMarkup = array();
        $collect = function (array $nodes) use (&$collect, &$nativeListItemMarkup): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                if ('core/list-item' === ($node['blockName'] ?? null)) {
                    $content = $node['attrs']['content'] ?? null;
                    if (is_string($content)) {
                        $nativeListItemMarkup[] = $content;
                    }
                }
                $collect(is_array($node['innerBlocks'] ?? null) ? $node['innerBlocks'] : array());
            }
        };
        $collect($blocks);
        \Automattic\BlocksEngine\PhpTransformer\Support\NativeListItemFallbackReconciler::reconcile($fallbacks, $nativeListItemMarkup);
    }
}

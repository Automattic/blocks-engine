<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Recognizes custom element tags used as a wrapping convention rather than as
 * component hosts, and the author attribute selectors that still address an
 * instance.
 */
final class CustomWrapperConventions
{
    /**
     * Instances a custom element tag must reach before recurring usage can be
     * read as a wrapping convention rather than a component.
     */
    private const WRAPPER_CONVENTION_MIN_INSTANCES = 3;

    /**
     * Depth of the shallow tag skeleton compared across wrapper instances.
     */
    private const WRAPPER_CONTENT_SHAPE_DEPTH = 2;

    public function __construct(
        private readonly RenderNeutralElements $renderNeutral
    ) {
    }

    /**
     * Remove custom elements a source uses as a wrapping convention.
     *
     * Site builders wrap ordinary content in presentation-only custom elements.
     * A custom element tag is not evidence that its subtree needs a generated
     * block, so leaving those wrappers in place freezes headings and copy into
     * opaque markup. A tag is treated as a convention when it recurs across the
     * document while the content it wraps diverges: a genuine component repeats
     * its internal structure, a wrapper does not.
     *
     * Instances an author attribute selector still addresses stay in place as
     * selector hooks.
     *
     * @return list<DOMElement> The retained hook instances.
     */
    public function unwrapConventionWrappers(DOMElement $body, string $staticCss): array
    {
        $retainedHooks = array();
        $instances = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && str_contains(strtolower($element->tagName), '-') ) {
                $instances[strtolower($element->tagName)][] = $element;
            }
        }

        $attributeSelectors = null;
        foreach ( $instances as $tag => $elements ) {
            if ( ! $this->isWrapperConventionTag($tag, $elements, $staticCss) ) {
                continue;
            }
            $attributeSelectors ??= $this->authorDataAttributeSelectors($staticCss);
            foreach ( $elements as $element ) {
                if ( $this->renderNeutral->isRenderNeutralWrapper($element, false) ) {
                    if ( $this->authorAttributeSelectorAddresses($element, $attributeSelectors) ) {
                        $retainedHooks[] = $element;
                    } else {
                        SourceDom::unwrapElement($element);
                    }
                }
            }
        }

        return $retainedHooks;
    }

    /**
     * Data-attribute compounds written in author selectors, e.g. the
     * `[data-key="a"]` of `[data-key="a"] .photo`.
     *
     * @return array<string, true>
     */
    private function authorDataAttributeSelectors(string $css): array
    {
        $selectors = array();
        foreach ( CssValueSplitter::ruleBlocks($css) as $rule ) {
            if ( ! str_contains($rule['selector'], '[data-') ) {
                continue;
            }
            if ( preg_match_all('/\[\s*data-[A-Za-z][A-Za-z0-9_-]*\s*(?:[*^$|~]?=\s*(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\]\s"\']+)\s*(?:[is]\s*)?)?\]/', $rule['selector'], $matches) ) {
                foreach ( $matches[0] as $selector ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return $selectors;
    }

    /**
     * Whether an author selector keys on this instance through its own
     * attributes. Such an instance is a selector hook, not a wrapping
     * convention: removing it silently detaches every rule that needs it as
     * a subject or ancestor. Equality compounds must match the instance's
     * value; any other operator fails closed on the attribute name.
     *
     * @param array<string, true> $selectors
     */
    private function authorAttributeSelectorAddresses(DOMElement $element, array $selectors): bool
    {
        foreach ( array_keys($selectors) as $selector ) {
            $parsed = \Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary::parseAttributeSelector($selector);
            if ( null !== $parsed ) {
                if ( \Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary::matchesElement($element, $selector, array()) ) {
                    return true;
                }
                continue;
            }
            if ( 1 === preg_match('/^\[\s*(data-[A-Za-z][A-Za-z0-9_-]*)/', $selector, $name) && $element->hasAttribute(strtolower($name[1])) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a custom element tag is used as a wrapping convention rather than
     * as a component host.
     *
     * @param array<int, DOMElement> $elements Every instance of the tag.
     */
    private function isWrapperConventionTag(string $tag, array $elements, string $staticCss): bool
    {
        if ( self::WRAPPER_CONVENTION_MIN_INSTANCES > count($elements) ) {
            return false;
        }

        // A tag that carries presentation of its own is not render-neutral.
        // `display:contents` is the exception that proves the rule: it states
        // the element generates no box and renders as its children.
        if ( ! $this->customElementCssIsRenderNeutral($tag, $staticCss) ) {
            return false;
        }

        $shapes = array();
        foreach ( $elements as $element ) {
            $shapes[$this->wrappedContentShape($element)] = true;
            if ( 1 < count($shapes) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every author rule addressing a custom element tag leaves it
     * render-neutral, so replacing instances with their children preserves the
     * authored presentation.
     */
    private function customElementCssIsRenderNeutral(string $tag, string $css): bool
    {
        if ( '' === trim($css) ) {
            return true;
        }

        $pattern = '/(?<![\w-])' . preg_quote($tag, '/') . '(?![\w-])/i';
        if ( 1 !== preg_match($pattern, $css) ) {
            return true;
        }

        foreach ( CssValueSplitter::ruleBlocks($css) as $rule ) {
            if ( 1 !== preg_match($pattern, $rule['selector']) ) {
                continue;
            }
            foreach ( SourceDeclarations::parse($rule['declarations']) as $property => $value ) {
                if ( 'display' !== strtolower(trim($property)) || 'contents' !== strtolower(trim($value)) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Shallow tag skeleton of the content a wrapper carries. Instances of a real
     * component repeat this shape; instances of a wrapper do not.
     */
    private function wrappedContentShape(DOMElement $element, int $depth = 0): string
    {
        $parts = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $parts[] = self::WRAPPER_CONTENT_SHAPE_DEPTH > $depth
                ? $tag . '(' . $this->wrappedContentShape($child, $depth + 1) . ')'
                : $tag;
        }

        return implode(',', $parts);
    }
}

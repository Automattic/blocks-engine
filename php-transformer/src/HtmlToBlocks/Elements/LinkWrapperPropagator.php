<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Pushes a whole-element link wrapper's href onto the blocks it wraps.
 *
 * WordPress blocks cannot nest inside an `<a>`, so a link that wraps a card or
 * row is carried by the converted children instead: images take the link
 * directly, RichText blocks get an inline anchor, and containers pass it down.
 * Blocks that own their own link (buttons, navigation, raw HTML) are left
 * alone.
 */
final class LinkWrapperPropagator
{
    /**
     * Blocks that manage their own link destination and must never receive a
     * propagated card-link wrapper href (core/button owns its `url`,
     * core/navigation-link owns its `url`, core/html is opaque markup, …).
     *
     * @var array<int, string>
     */
    private const LINK_SELF_MANAGING_BLOCKS = array(
        'core/button',
        'core/buttons',
        'core/file',
        'core/html',
        'core/navigation',
        'core/navigation-link',
        'core/navigation-submenu',
    );

    /**
     * RichText content blocks whose stored `content` can carry an inline `<a>`
     * when a whole-element link wrapper is propagated onto them (#260).
     *
     * @var array<int, string>
     */
    private const LINK_BEARING_TEXT_BLOCKS = array(
        'core/heading',
        'core/paragraph',
        'core/list-item',
    );

    /**
     * Marks a RichText block whose text was painted by the source, and into
     * whose content a content-wrapping anchor's link had to be pushed. The
     * synthesized inline `<a>` becomes the text's nearest painted ancestor, so
     * the browser's link colour would replace the source colour; the projected
     * rule makes that anchor inherit its host block's colour instead.
     */
    private const PROPAGATED_LINK_COLOR_CARRIER_CLASS = SourceBlockAttributeProjector::PROPAGATED_LINK_COLOR_CLASS;

    public const PROPAGATED_LINK_CARRIER_CLASS = SourceBlockAttributeProjector::PROPAGATED_LINK_CLASS;

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly BlockFactory $blockFactory
    ) {
    }

    /**
     * The subset of a card-link wrapper's attributes used to propagate the link
     * onto inner blocks: href (sanitized), target, and rel. The wrapper's own
     * class/aria-label stay on the container group, not on each inner block.
     *
     * @return array<string, string>
     */
    public function linkPropagationAttributes(DOMElement $anchor): array
    {
        $href = LinkUrlSanitizer::sanitize($this->attr($anchor, 'href'));
        if ( '' === $href ) {
            return array();
        }

        $declarations = $this->styleResolver->presentationDeclarations($anchor);
        // An author utility such as `text-decoration:none!important` still
        // leaves the wrapper undecorated.
        $textDecoration = CssValueInspector::comparable((string) ($declarations['text-decoration'] ?? ''));

        return array_filter(array(
            'href'           => $href,
            'target'         => $this->attr($anchor, 'target'),
            'rel'            => $this->attr($anchor, 'rel'),
            'textDecoration' => 'none' === $textDecoration ? 'none' : '',
            'color'          => $this->linkWrapperPaintsAuthoredTextColor($anchor) ? 'inherit' : '',
        ), static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * Whether an element strictly inside a content-wrapping anchor paints every
     * run of the anchor's text.
     *
     * Pushing the wrapper's href down onto an inner RichText block (see
     * {@see self::propagateInlineLink()}) rebuilds the anchor INSIDE the block
     * that the painting element became, so the synthesized `<a>` — not that
     * element — is now the text's nearest painted ancestor and the browser's
     * link colour replaces the source colour. Making the anchor inherit
     * restores the source's painted colour, whatever it is, because the block
     * that inherits from still carries the source's own styling hooks.
     *
     * The wrapper's own colour is deliberately not counted. It has no painting
     * element inside the anchor to lose: source rules that reach the anchor by
     * type still reach the rebuilt one, and no repaint is owed. Text sitting
     * directly in the anchor is likewise unpainted from within, so a wrapper
     * that holds any is left exactly as it is today.
     */
    private function linkWrapperPaintsAuthoredTextColor(DOMElement $anchor): bool
    {
        $textOwners = array();
        foreach ( array_merge(array( $anchor ), SourceDom::descendantElements($anchor)) as $element ) {
            foreach ( $element->childNodes as $node ) {
                if ( XML_TEXT_NODE === $node->nodeType && '' !== trim($node->textContent ?? '') ) {
                    $textOwners[] = $element;
                    break;
                }
            }
        }

        if ( array() === $textOwners ) {
            return false;
        }

        foreach ( $textOwners as $textOwner ) {
            if ( ! $this->paintsTextColorBelow($textOwner, $anchor) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the cascade paints `$element` — or an ancestor of it below
     * `$anchor` — with a colour of its own. That is the inheritance chain the
     * rebuilt anchor severs, so it is the chain that decides whether the anchor
     * must inherit.
     *
     * The paint is read through
     * {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver::controlSurfaceResolvedStyle()}
     * because this is a classification signal, not a projection: a capture
     * routinely serialises a site's desktop paint behind a width query, and the
     * static-only view would call such a leaf unpainted — leaving the rebuilt
     * anchor to render the browser's link colour exactly where the source
     * rendered white. Conditions that never hold at the reference viewport
     * still count for nothing, matching what the document renders with.
     */
    private function paintsTextColorBelow(DOMElement $element, DOMElement $anchor): bool
    {
        for ( $node = $element; $node instanceof DOMElement && ! $node->isSameNode($anchor); $node = $node->parentNode ) {
            $declarations = $this->styleResolver->cssDeclarations(
                $this->styleResolver->controlSurfaceResolvedStyle($node)
            );
            $color = strtolower(trim((string) ($declarations['color'] ?? '')));
            if ( '' !== $color && ! in_array($color, array( 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'currentcolor' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk the converted inner blocks of a link wrapper and propagate the
     * anchor's link onto every native link-bearing descendant so the content
     * remains navigable. core/image receives native link attributes;
     * {@see self::LINK_BEARING_TEXT_BLOCKS} get an inline `<a>` around their text
     * content. Layout containers (group/columns/column/…) are recursed into so a
     * card whose heading/image/text lives behind wrapper `<div>`s is still
     * covered. Blocks that manage their own link
     * ({@see self::LINK_SELF_MANAGING_BLOCKS}) are skipped.
     *
     * Returns true when the link was carried onto at least one inner block.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, string> $linkAttrs
     */
    public function propagateLinkWrapper(array &$blocks, array $linkAttrs): bool
    {
        $preserved = false;
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $name = (string) ($block['blockName'] ?? '');

            if ( in_array($name, self::LINK_SELF_MANAGING_BLOCKS, true) ) {
                continue;
            }

            // Companion content frozen from inside this link already carries
            // the link it was restored with (see FallbackEmitter::withEnclosingLink()).
            $content = $block['attrs']['content'] ?? null;
            if ( is_string($content) && str_starts_with($content, '<a href="' . htmlspecialchars((string) ($linkAttrs['href'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"') && str_contains($content, 'class="' . FallbackEmitter::LINK_CONTENTS_CLASS . '"') ) {
                $preserved = true;
                continue;
            }

            if ( 'core/image' === $name ) {
                if ( $this->propagateLinkOntoImage($blocks[$index], $linkAttrs) ) {
                    $preserved = true;
                }
                continue;
            }

            if ( in_array($name, self::LINK_BEARING_TEXT_BLOCKS, true) ) {
                if ( $this->propagateInlineLink($blocks[$index], $linkAttrs) ) {
                    $preserved = true;
                }
                continue;
            }

            if ( isset($blocks[$index]['innerBlocks']) && is_array($blocks[$index]['innerBlocks']) ) {
                if ( $this->propagateLinkWrapper($blocks[$index]['innerBlocks'], $linkAttrs) ) {
                    $preserved = true;
                }
            }
        }

        return $preserved;
    }

    private function propagateLinkOntoImage(array &$block, array $linkAttrs): bool
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        if ( '' !== (string) ($attrs['href'] ?? '') ) {
            return false;
        }

        $href = (string) ($linkAttrs['href'] ?? '');
        if ( '' === $href ) {
            return false;
        }

        $imageLink = array_filter(array(
            'href'            => $href,
            'linkDestination' => 'custom',
            'linkTarget'      => (string) ($linkAttrs['target'] ?? ''),
            'rel'             => (string) ($linkAttrs['rel'] ?? ''),
        ), static fn (string $value): bool => '' !== trim($value));

        $block = $this->blockFactory->rebuild($block, array_merge($attrs, $imageLink));
        return true;
    }

    /**
     * Propagate a card-link wrapper's href onto a RichText content block
     * (heading/paragraph/list-item) by wrapping its text content in an inline
     * `<a>`. Content that is empty or already carries a link is left untouched.
     *
     * @param array<string, mixed> $block
     * @param array<string, string> $linkAttrs
     */
    private function propagateInlineLink(array &$block, array $linkAttrs): bool
    {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $content = (string) ($attrs['content'] ?? '');
        if ( '' === trim($content) ) {
            return false;
        }

        $href = (string) ($linkAttrs['href'] ?? '');
        if ( '' === $href ) {
            return false;
        }

        if ( preg_match('/<a\b/i', $content) ) {
            return false;
        }

        $wrapped = $this->wrapInlineLink($content, $linkAttrs);
        if ( $wrapped === $content ) {
            return false;
        }

        $replacementAttrs = array_merge($attrs, array( 'content' => $wrapped ));
        if ( 'inherit' === (string) ($linkAttrs['color'] ?? '') ) {
            $replacementAttrs['className'] = $this->mergeClassNames(
                (string) ($replacementAttrs['className'] ?? ''),
                self::PROPAGATED_LINK_COLOR_CARRIER_CLASS
            );
        }
        if ( 'none' === (string) ($linkAttrs['textDecoration'] ?? '') ) {
            $replacementAttrs['className'] = $this->mergeClassNames(
                (string) ($replacementAttrs['className'] ?? ''),
                SourceBlockAttributeProjector::SYNTHETIC_ANCHOR_UNDECORATED_CLASS
            );
            $style = is_array($replacementAttrs['style'] ?? null) ? $replacementAttrs['style'] : array();
            $typography = is_array($style['typography'] ?? null) ? $style['typography'] : array();
            $typography['textDecoration'] = 'none';
            $style['typography'] = $typography;
            $replacementAttrs['style'] = $style;
        }

        // The injected anchor wraps every child of the source element. When
        // those children include elements (an icon beside a label), it must
        // not become the element's only layout child, or a flex/grid source
        // row collapses into the anchor's inline flow. Plain text is unaffected.
        if ( str_contains($content, '<') ) {
            $replacementAttrs['className'] = $this->mergeClassNames(
                (string) ($replacementAttrs['className'] ?? ''),
                self::PROPAGATED_LINK_CARRIER_CLASS
            );
        }
        $block = $this->blockFactory->rebuild($block, $replacementAttrs);
        return true;
    }

    /**
     * Wrap a RichText content string in an inline `<a>` carrying the propagated
     * href/target/rel.
     *
     * @param array<string, string> $linkAttrs
     */
    private function wrapInlineLink(string $content, array $linkAttrs): string
    {
        $href = (string) ($linkAttrs['href'] ?? '');
        if ( '' === $href || '' === trim($content) ) {
            return $content;
        }

        $attributes = ' href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ( '' !== trim((string) ($linkAttrs['target'] ?? '')) ) {
            $attributes .= ' target="' . htmlspecialchars($linkAttrs['target'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        if ( '' !== trim((string) ($linkAttrs['rel'] ?? '')) ) {
            $attributes .= ' rel="' . htmlspecialchars($linkAttrs['rel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return '<a' . $attributes . '>' . $content . '</a>';
    }

    private function attr(DOMElement $element, string $name): string
    {
        return SourceDom::attr($element, $name);
    }

    private function mergeClassNames(string ...$classNames): string
    {
        return SourceDom::mergeClassNames(...$classNames);
    }
}

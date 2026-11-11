<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText;

use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CommentTextRunBoundaries;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMDocument;
use DOMElement;
use DOMNode;

final class RichTextStylingHookNormalizer
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier
    ) {
    }

    /** Use the same semantic carriers for native RichText and owned label RichText. */
    public function normalize(DOMElement $body): void
    {
        // Remaining styling hooks stay on RichText-safe marks, without
        // assigning highlighted-text semantics to mechanical carriers.
        foreach ($this->richTextStylingHookElements($body) as $inline) {
            if ('font' === strtolower($inline->tagName) && !$inline->hasAttributes()) {
                SourceDom::unwrapElement($inline);
                continue;
            }
            if ($this->replaceRichTextStylingHookWithMark($inline)) continue;
            if ('span' === strtolower($inline->tagName) || RichTextInlineTags::isUnknownHtmlElement($inline)) {
                SourceDom::unwrapElement($inline);
            }
        }
        foreach ($this->richTextAnchors($body) as $anchor) {
            // Link styles are not a core link-format attribute. Carry their
            // inheritable visual declarations inside the link, just like
            // styled spans; decoration and box values stay owned by the link.
            if ('' !== trim(SourceDom::attr($anchor, 'style'))) {
                $this->projectRichTextLinkOwnedDeclarations($anchor);
                $this->replaceRichTextStylingHookWithMark($anchor);
            }
            $anchor->removeAttribute('style');
        }
    }

    /**
     * Only inherited text styling can move from a link onto a descendant
     * carrier. Text decorations are painted by the decorating box (a
     * descendant's `text-decoration:none` cannot cancel the link's UA
     * underline), and box values (border, padding, background, display…)
     * style the link's own box. Those stay effective on the anchor through a
     * generated class + stylesheet rule — the same carrier projection used for
     * generated geometry — and leave the inline style, so the mark carrier
     * only receives values a descendant can reproduce.
     */
    private function projectRichTextLinkOwnedDeclarations(DOMElement $anchor): void
    {
        $inline = $this->styleResolver->inlineDeclarations($anchor);
        $visual = $this->richTextMaterializer->inlineVisualDeclarations($anchor);
        $owned = array();
        foreach ($inline as $property => $value) {
            $property = strtolower((string) $property);
            $decorates = str_starts_with($property, 'text-decoration') || str_starts_with($property, 'text-underline-');
            // An inline box-shadow paints the link's own box — the outline an
            // `inset 0 0 0 1px` control draws in place of a border. It is a
            // box value the link owns, like border or background: it cannot
            // travel to a descendant, and it must not fall off with the
            // inline style RichText strips from the anchor.
            $paintsBox = 'box-shadow' === $property;
            if (!$decorates && !$paintsBox && (!isset($visual[$property]) || $this->descendantInheritsLinkDeclaration($property))) {
                continue;
            }
            unset($inline[$property]);
            $value = trim(preg_replace('/!\s*important\s*$/i', '', (string) $value) ?? '');
            if ('' === $value || preg_match('/[{}<>;]/', $value)) {
                continue;
            }
            // An inline declaration beats every non-important author rule
            // (including `a:hover`), so the projected rule must too.
            $owned[] = $property . ':' . $value . '!important';
        }
        if (array() === $owned) {
            return;
        }
        $body = '{' . implode(';', $owned) . '}';
        $className = $this->session->layoutGeometryState()->allocateCarrier('rich-text-link-owned' . "\n" . $body);
        $this->session->layoutGeometryState()->registerRule($className, 'a.' . $className . $body);
        $classes = SourceDom::tokens(SourceDom::attr($anchor, 'class'));
        $classes[] = $className;
        $anchor->setAttribute('class', implode(' ', array_values(array_unique(array_filter($classes)))));
        if (array() === $inline) {
            $anchor->removeAttribute('style');
        } else {
            $anchor->setAttribute('style', $this->styleResolver->cssDeclarationString($inline));
        }
    }

    /**
     * A `<span>` whose attributes can be represented by a semantic RichText
     * carrier. Class/id/data identity and inline styles move together onto a
     * `<mark>` so selector hooks survive without storing an invalid span.
     */
    public function isStylingHookSpan(DOMElement $element): bool
    {
        if ( 'span' !== strtolower($element->tagName) ) {
            return false;
        }

        if ( $this->tagNameLoadBearingForUnprojectedPseudoElementRule($element) ) {
            return false;
        }

        $hasStyling = false;
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributeName = strtolower($attribute->nodeName);
            if ( ! in_array($attributeName, array( 'class', 'id', 'style', 'data-blocks-engine-richtext-marker' ), true) && ! str_starts_with($attributeName, 'data-') ) {
                return false;
            }
            if ( in_array($attributeName, array( 'class', 'style', 'data-blocks-engine-richtext-marker' ), true) && '' !== trim($attribute->nodeValue ?? '') ) {
                $hasStyling = true;
            }
        }

        if ( $hasStyling ) {
            return true;
        }

        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static') as $rule ) {
            if ( $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
                return true;
            }
        }

        return false;
    }

    private function isRichTextInlineStylingHookElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( RichTextInlineTags::isUnknownHtmlElement($element) ) {
            return true;
        }
        if ( 'span' === $tagName ) {
            return $this->isStylingHookSpan($element);
        }

        if ( 'font' === $tagName ) {
            return ! $this->tagNameLoadBearingForUnprojectedPseudoElementRule($element);
        }

        if ( ! in_array($tagName, array( 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup' ), true) ) {
            return false;
        }

        $hasStyling = false;
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributeName = strtolower($attribute->nodeName);
            if ( 'class' !== $attributeName && 'style' !== $attributeName ) {
                return false;
            }
            if ( '' !== trim($attribute->nodeValue ?? '') ) {
                $hasStyling = true;
            }
        }

        return $hasStyling;
    }

    /**
     * Whether author CSS addresses this element's own tag name through a
     * pseudo-element rule (`:before`/`:after`) whose base selector the
     * stylesheet projector cannot re-point at a materialized carrier.
     *
     * `AuthorStylesheetProjector` retargets a type selector to the marker
     * that survives RichText lowering (`mark[style*="…"],span[data-…]`) only
     * when the selector itself is one `CssSelectorMatcher` can parse and
     * match. A trailing single-colon `:before`/`:after` is deliberately
     * outside that supported subset (it addresses generated content, not a
     * real node) and rides through unrewritten, still keyed to the literal
     * source tag name. Lowering a span/font carrying that tag name to
     * `<mark>` — the RichText-safe carrier used elsewhere to keep styling
     * hooks alive across serialization — would silently strip the one thing
     * this untouched selector still matches on.
     */
    private function tagNameLoadBearingForUnprojectedPseudoElementRule(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            $selector = (string) ($rule['selector'] ?? '');
            $parsed = $this->session->sourceStyleResolutionState()->parsedSelector($selector);
            $compounds = is_array($parsed) ? ($parsed['compounds'] ?? array()) : array();
            $rightmost = array() === $compounds ? null : $compounds[array_key_last($compounds)];
            if (
                ! is_array($parsed) || ! ($parsed['supported'] ?? false) || ! is_array($rightmost)
                || $tagName !== strtolower((string) ($rightmost['type'] ?? ''))
            ) {
                continue;
            }
            if ( $this->styleResolver->matchesCssSelector($element, $selector) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function richTextStylingHookElements(DOMElement $container): array
    {
        $elements = array();
        foreach ( $container->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $this->isRichTextInlineStylingHookElement($element) ) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function richTextAnchors(DOMElement $container): array
    {
        $anchors = array();
        foreach ( $container->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && ( $anchor->hasAttribute('class') || $anchor->hasAttribute('style') ) ) {
                $anchors[] = $anchor;
            }
        }

        return $anchors;
    }

    /**
     * Source identity that RichText can retain on a semantic inline carrier.
     * Classes, safe ids, and data attributes are selector hooks, unlike an
     * arbitrary inline style that RichText cannot safely round-trip.
     *
     * @return array<string, string>
     */
    public function richTextSafeIdentityAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName);
            if ( 'class' === $name && '' !== trim($attribute->nodeValue ?? '') ) {
                $attributes['class'] = $attribute->nodeValue ?? '';
            } elseif ( 'id' === $name && '' !== SourceDom::safeAnchor($attribute->nodeValue ?? '') ) {
                $attributes['id'] = SourceDom::safeAnchor($attribute->nodeValue ?? '');
            } elseif ( str_starts_with($name, 'data-') && 'data-blocks-engine-richtext-marker' !== $name ) {
                $attributes[$name] = $attribute->nodeValue ?? '';
            }
        }

        return $attributes;
    }

    /** Inherited text styling a descendant carrier reproduces exactly. */
    private function descendantInheritsLinkDeclaration(string $property): bool
    {
        return str_starts_with($property, '--')
            || str_starts_with($property, 'font-')
            || in_array($property, array( 'color', '-webkit-text-fill-color', 'letter-spacing', 'line-height', 'text-transform' ), true);
    }

    private function replaceRichTextStylingHookWithMark(DOMElement $element): bool
    {
        // A carrier this pipeline already materialized states its resolved
        // declarations inline. Resolving it again would read the detached,
        // classless mark against the author cascade, where a universal rule
        // can win and freeze a value the first pass deliberately left to the
        // author's class rules (a responsive padding, say). Keep it as is.
        if ( $this->isMaterializedRichTextCarrier($element) ) {
            return true;
        }

        $sourceIsLink = 'a' === strtolower($element->tagName);
        if ( ! $sourceIsLink && $this->containsUnlowerableMark($element) ) {
            return false;
        }

        // Whether the source author already wrote a literal `<mark>` here.
        // When they did, they own the "this text is highlighted" semantic and
        // it is preserved. Every other source tag (span/font, or a format tag
        // like strong/em getting a nested carrier) is repurposed as `<mark>`
        // purely as a mechanical RichText-safe attribute carrier — the default
        // background/color reset just below exists precisely because no
        // visual highlight is intended. `role="none"` below strips the
        // matching accessible "mark" role so assistive tech does not announce
        // ordinary styled text as highlighted/marked content it never was.
        $sourceTagWasMark = 'mark' === strtolower($element->tagName);

        // Whether the carrier replaces the source element (span/font/mark) or is
        // nested inside it (format tags). Only a replacing carrier keeps the
        // author's classes, so only there can a media-conditional class rule
        // keep answering after conversion.
        $carrierReplacesSource = in_array(strtolower($element->tagName), array( 'span', 'font', 'mark' ), true) || RichTextInlineTags::isUnknownHtmlElement($element);

        $declarations = $this->richTextMaterializer->inlineVisualDeclarations($element);
        if ( $carrierReplacesSource ) {
            // The mark keeps the author's classes, and an inline declaration
            // out-ranks every stylesheet rule — a base value projected here
            // would freeze the breakpoint it came from onto the mark and
            // silence the responsive class rule at every other width.
            $declarations = $this->styleResolver->stripResponsiveClassOwnedDeclarations($element, $declarations);
        }
        if ( ! $carrierReplacesSource && ! $sourceIsLink ) {
            $declarations = $this->richTextMaterializer->withoutKeptFormatTagDeclarations($element, $declarations);
        }
        $existingDeclarations = $this->styleResolver->inlineDeclarations($element);
        if ( $sourceIsLink ) {
            // Only authored inline values move inside links. Author link rules
            // (including hover/media rules) must keep styling the link itself.
            $declarations = array_intersect_key($existingDeclarations, $declarations);
            if ( array() === $declarations ) {
                return false;
            }
        }
        $marker = trim((string) ($existingDeclarations['--blocks-engine-richtext-marker'] ?? ''));
        if ( '' === $marker ) {
            $marker = trim(SourceDom::attr($element, 'data-blocks-engine-richtext-marker'));
        }
        if ( '' === $marker && array() === $declarations && array() === $this->richTextSafeIdentityAttributes($element) ) {
            return false;
        }

        if ( '' !== $marker ) {
            $declarations['--blocks-engine-richtext-marker'] = $marker;
        }

        // A `background` shorthand already sets the background color; a later
        // reset would erase the author's paint (a CSS-drawn dot or bar).
        if ( '' === $marker && ! isset($declarations['background-color']) && ! isset($declarations['background']) ) {
            $declarations['background-color'] = 'transparent';
        }
        if ( '' === $marker && ! isset($declarations['color']) ) {
            $declarations['color'] = 'inherit';
        }

        $document = $element->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return false;
        }

        $mark = $document->createElement('mark');
        foreach ( $sourceIsLink ? array() : $this->richTextSafeIdentityAttributes($element) as $name => $value ) {
            $mark->setAttribute($name, $value);
        }
        $mark->setAttribute('style', $this->styleResolver->cssDeclarationString($declarations));
        if ( ! $sourceTagWasMark ) {
            $mark->setAttribute('role', 'none');
        }
        if ( $sourceIsLink && $element->childElementCount > 0 ) {
            // A carrier around all children changes the link's flex/grid items.
            // Reuse the resolved carrier, but put it around text runs only.
            $this->wrapRichTextLinkTextRuns($element, $mark, $declarations);
            $element->removeAttribute('style');
            return true;
        }
        while ( null !== $element->firstChild ) {
            $mark->appendChild($element->firstChild);
        }
        // RichText discards an empty element unless it matches a registered
        // format, and core's inline color format is `mark.has-inline-color`.
        if ( '' === trim($mark->textContent ?? '') && 0 === $mark->getElementsByTagName('img')->length ) {
            $mark->setAttribute('class', SourceDom::mergeClassNames($mark->getAttribute('class'), 'has-inline-color'));
        }

        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMNode ) {
            return false;
        }

        if ( $carrierReplacesSource ) {
            $parent->replaceChild($mark, $element);
            return true;
        }

        if ( ! $sourceIsLink ) {
            $element->removeAttribute('class');
        }
        $element->removeAttribute('style');
        $element->appendChild($mark);
        return true;
    }

    /**
     * A `<mark>` stating its RichText marker inline is a carrier the conversion
     * produced: source markup carries its marker as a data attribute, and only
     * a materialized carrier states it in its style.
     */
    private function isMaterializedRichTextCarrier(DOMElement $element): bool
    {
        return 'mark' === strtolower($element->tagName)
            && '' !== trim((string) ($this->styleResolver->inlineDeclarations($element)['--blocks-engine-richtext-marker'] ?? ''));
    }

    /**
     * Keep element children as layout items and respect their own text styles.
     *
     * @param array<string, string> $declarations
     */
    private function wrapRichTextLinkTextRuns(DOMElement $element, DOMElement $carrier, array $declarations): void
    {
        foreach ( iterator_to_array($element->childNodes) as $child ) {
            if ( $child instanceof DOMElement ) {
                $owned = $this->richTextMaterializer->inlineVisualDeclarations($child);
                $inherited = array_diff_key($declarations, $owned);
                $this->wrapRichTextLinkTextRuns($child, $carrier, $inherited);
            } elseif ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent) && array() !== $declarations ) {
                $mark = $carrier->cloneNode(false);
                if ( ! $mark instanceof DOMElement ) {
                    continue;
                }
                // A mark's UA defaults must not override descendant styling.
                $style = $declarations + array('color' => 'inherit', 'background-color' => 'transparent');
                $mark->setAttribute('style', $this->styleResolver->cssDeclarationString($style));
                $element->replaceChild($mark, $child);
                $mark->appendChild($child);
            }
        }
    }

    /**
     * Whether a descendant mark forbids lowering this element to a mark
     * carrier.
     *
     * A genuine `<mark>` (the author's own highlight, or one an earlier pass
     * already carried) must not be re-wrapped. The engine's neutral
     * comment-boundary carriers are different: they only keep a framework
     * text-split seam (`CommentTextRunBoundaries`), so a styling hook holding
     * them still lowers and the seam carriers nest inside the new mark.
     * Declining instead would unwrap the styling hook and shed both its
     * identity and a display declaration — a block-level span's own line.
     */
    private function containsUnlowerableMark(DOMElement $element): bool
    {
        if ( 'mark' === strtolower($element->tagName) ) {
            return $element->getElementsByTagName('mark')->length > 0;
        }
        foreach ( $element->getElementsByTagName('mark') as $mark ) {
            if ( $mark instanceof DOMElement && ! CommentTextRunBoundaries::isCarrier($mark) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lift class/style styling hooks out of a block's RichText source so the
     * stored block round-trips through RichText unchanged.
     *
     * Core text blocks store `content`, and core/file stores `fileName`, as
     * RichText. RichText only preserves a fixed set of inline formats (a,
     * strong, em, br, …). A `<span class="…">` / `<span style="…">` is not a format, so
     * RichText drops its attributes on parse: the saved markup no longer matches
     * the re-serialized block ("unexpected or invalid content"), and the class —
     * a styling hook the materialized CSS targets — would be silently lost.
     *
     * The fix keys off STRUCTURE (a content-bearing span carrying only
     * class/style), never on any specific class name:
     *   - A SINGLE styling-hook span wrapping the ENTIRE content is UNWRAPPED and
     *     its class/style are HOISTED onto the block (merged into `className` and
     *     the canonical `style` object). The hook survives where RichText does
     *     preserve it and the inner text/inline-format becomes valid content.
     *     Nested wrappers are peeled across iterations.
     *   - Remaining sibling/partial styling-hook spans are UNWRAPPED to their
     *     inner content. Their per-span class styling cannot ride valid RichText
     *     here, so this is best-effort; the emitted block is always valid.
     * Genuine inline formats (strong/em/a/br/…) are kept, but arbitrary
     * class/style hooks on links are moved to the block wrapper when the link is
     * the sole content wrapper, or dropped when they are partial-content hooks.
     * RichText's link format round-trips href/target/rel, not source CSS hooks.
     *
     * A list item whose content carries block-level children keeps that topology:
     * its inline hooks still become RichText-safe marks, but no wrapper is
     * hoisted onto the list item.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function hoistContentWrappingSpans(string $name, array $attrs): array
    {
        $richTextAttribute = 'core/file' === $name ? 'fileName' : 'content';
        if ( ! in_array($name, array( 'core/file', 'core/paragraph', 'core/heading', 'core/list-item' ), true) ) {
            return $attrs;
        }

        $content = (string) ($attrs[$richTextAttribute] ?? '');
        if ( '' === $content || ( ! preg_match('/<(?:span|font|a|em|i|strong|b|mark|small|sub|sup)\b/i', $content) && ! RichTextInlineTags::containsUnknownElement($content) ) ) {
            return $attrs;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return $attrs;
        }

        $listItemHasBlockContent = 'core/list-item' === $name && $this->sourceElementClassifier->hasBlockContentChildren($body);

        $hoistedClasses      = '';
        $hoistedDeclarations = array();

        // Peel a single styling-hook span wrapping the whole content, hoisting it
        // onto the block. A source identity needs to remain on the inline node so
        // author selectors continue to address the saved RichText carrier.
        while ( ! $listItemHasBlockContent && ( $wrapper = $this->soleStylingHookSpan($body) ) instanceof DOMElement ) {
            $wrapperDeclarations = $this->styleResolver->inlineDeclarations($wrapper);
            if ( array() !== $this->richTextSafeIdentityAttributes($wrapper) || isset($wrapperDeclarations['--blocks-engine-richtext-marker']) ) {
                break;
            }
            $hoistedClasses = trim($hoistedClasses . ' ' . SourceDom::attr($wrapper, 'class'));
            $wrapperStyle   = trim(SourceDom::attr($wrapper, 'style'));
            if ( '' !== $wrapperStyle ) {
                // On an inline wrapper these values restate the parent's size;
                // on the block they would instead inherit from its parent and
                // erase an outer span's size (or a stylesheet heading size).
                $size = strtolower(CssValueInspector::withoutImportant((string) ($wrapperDeclarations['font-size'] ?? '')));
                if ( in_array($size, array( 'inherit', 'unset' ), true)
                    || 1 === preg_match('/^(?:100(?:\.0+)?%|1(?:\.0+)?em)$/', $size) ) {
                    unset($wrapperDeclarations['font-size']);
                }
                $hoistedDeclarations = array_merge($hoistedDeclarations, $wrapperDeclarations);
            }
            SourceDom::unwrapElement($wrapper);
        }

        $this->normalize($body);

        $newContent = $this->styleResolver->innerHtml($body);
        if ( $newContent === $content && '' === $hoistedClasses && array() === $hoistedDeclarations ) {
            return $attrs;
        }

        $attrs[$richTextAttribute] = $newContent;

        if ( '' !== $hoistedClasses ) {
            $promoted = $this->styleResolver->promotedClassName($hoistedClasses);
            if ( '' !== trim($promoted) ) {
                $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), $promoted);
            }
        }

        if ( array() !== $hoistedDeclarations ) {
            $mapped = $this->styleResolver->styleAttributeMapper()->map($hoistedDeclarations)['style'];
            if ( array() !== $mapped ) {
                $existing       = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
                $attrs['style'] = array_replace_recursive($mapped, $existing);
                // A size on the content overrides the host's size in the source
                // cascade too, even when the host carries a reset such as 100%.
                if ( isset($mapped['typography']['fontSize']) ) {
                    $attrs['style']['typography']['fontSize'] = $mapped['typography']['fontSize'];
                }
            }
        }

        return $attrs;
    }

    /**
     * The single styling-hook span that is the container's only significant
     * child, or null when the content is plain text, inline formats, or sibling
     * spans (which must not be hoisted as one block-level styling hook).
     */
    private function soleStylingHookSpan(DOMElement $container): ?DOMElement
    {
        $only = null;
        foreach ( $container->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( null !== $only ) {
                return null;
            }
            $only = $child;
        }

        return $only instanceof DOMElement && $this->isStylingHookSpan($only) ? $only : null;
    }

    /**
     * Inline content as the standalone RichText a block's own content
     * attribute carries — a control label, an in-form introduction, a lowered
     * wrapper paragraph: the materialized source markup, with styling hooks
     * lowered to semantic carriers inline rather than hoisted onto the host.
     */
    public function inlineContent(DOMElement $element): string
    {
        $content = $this->richTextMaterializer->content($element);
        if ('' === $content) return '';
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if (!$body instanceof DOMElement) return $content;
        $this->normalize($body);
        return SourceDom::innerHtml($body);
    }

    /**
     * In-form copy — an introduction above the fields, a note between them —
     * as the paragraph/heading RichText the page conversion keeps, so the form
     * contract carries the copy's formatting instead of its flattened text.
     * Copy without inline markup, and markup a paragraph cannot hold (structure
     * a RichText attribute rejects, or an object the fallback gate reserves for
     * the page's own conversion), returns '': the item's plain text, styles and
     * classes stay the contract there.
     */
    public function formContextContent(DOMElement $element): string
    {
        $content = trim($this->inlineContent($element));
        if ( '' === $content || ! preg_match('/<[a-zA-Z]/', $content)
            || $this->richTextMaterializer->hasStructuralHtml($content)
            || $this->richTextMaterializer->requiresHtmlFallback($content) ) {
            return '';
        }

        return $content;
    }
}

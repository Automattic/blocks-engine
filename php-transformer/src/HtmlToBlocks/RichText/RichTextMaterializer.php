<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText;

use Automattic\BlocksEngine\PhpTransformer\Contract\RichTextInlineTags;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer;
use DOMDocument;
use DOMElement;

/** Materializes source inline markup into content accepted by block RichText. */
final class RichTextMaterializer implements RichTextMaterialization
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly SvgMaterializer $svgMaterializer,
        private readonly HtmlTransformerSession $session,
        private readonly RichTextInlinePolicy $inlinePolicy
    ) {
    }

    /** Convert invalid block wrappers inside a heading into valid RichText breaks. */
    public function headingContent(string $content): string
    {
        if ( ! preg_match('/<\/?(?:div|p)\b/i', $content) ) {
            return $content;
        }
        $content = preg_replace_callback('/<\s*(\/)?\s*(?:div|p)\b[^>]*>/i', static fn (array $match): string => ! empty($match[1]) ? '<br>' : '', $content) ?? $content;
        return preg_replace('/(?:<br>\s*){2,}/i', '<br>', $content) ?? $content;
    }

    public function requiresHtmlFallback(string $content): bool
    {
        return (bool) preg_match('/<(?:svg|canvas|img|picture|video|audio|iframe|object|embed|input|button|select|textarea|form)\b/i', $content);
    }

    public function hasStructuralHtml(string $content): bool
    {
        return (bool) preg_match('/<(?:address|article|aside|blockquote|details|div|dl|figure|h[1-6]|hr|main|menu|nav|ol|p|pre|section|table|ul)\b/i', $content);
    }

    /**
     * Inline elements whose resolved source styling is carried onto the
     * RichText markup. Unknown and custom elements (`<bdt>`, `<x-note>`) are
     * included: they are later normalized onto a RichText-safe carrier, so
     * their class-driven styling must travel with them.
     */
    private static function isMaterializedInline(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'span', 'font', 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup' ), true)
            || RichTextInlineTags::isUnknownHtmlElement($element);
    }

    /** @param array<int, string> $excludedTags */
    public function content(DOMElement $element, array $excludedTags = array()): string
    {
        $content = array() === $excludedTags ? SourceDom::innerHtml($element) : SourceDom::innerHtmlWithoutTags($element, $excludedTags);
        // `<wbr>` is a void presentation hint, not editable RichText markup.
        // Browsers can serialize malformed source as a closing `</wbr>` too;
        // remove both forms so it cannot become structural HTML in a block.
        $content = preg_replace('/<\/?wbr\b[^>]*>/i', '', $content) ?? $content;
        if ( '' === $content || ( ! preg_match('/<(?:span|font|em|i|strong|b|mark|small|sub|sup)\b/i', $content) && ! RichTextInlineTags::containsUnknownElement($content) ) ) {
            return $content;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return $content;
        }

        $sourceInlines = array();
        foreach ( $element->getElementsByTagName('*') as $sourceInline ) {
            if ( $sourceInline instanceof DOMElement && self::isMaterializedInline($sourceInline) ) {
                for ( $parent = $sourceInline->parentNode; $parent instanceof DOMElement && $parent !== $element; $parent = $parent->parentNode ) {
                    if ( in_array(strtolower($parent->tagName), $excludedTags, true) ) {
                        continue 2;
                    }
                }
                $sourceInlines[] = $sourceInline;
            }
        }

        $targetInlines = array();
        foreach ( $body->getElementsByTagName('*') as $targetInline ) {
            if ( $targetInline instanceof DOMElement && self::isMaterializedInline($targetInline) ) {
                $targetInlines[] = $targetInline;
            }
        }

        foreach ( $targetInlines as $index => $targetInline ) {
            $sourceInline = $sourceInlines[$index] ?? null;
            if ( ! $sourceInline instanceof DOMElement ) {
                continue;
            }

            if ( '' === trim($sourceInline->textContent ?? '') && 0 === SourceDom::childElementCount($sourceInline) && ! $this->inlinePolicy->retainsEmptyRichTextInline($sourceInline) ) {
                $targetInline->parentNode?->removeChild($targetInline);
                continue;
            }

            $inline = $this->inlineVisualDeclarations($sourceInline);
            $marker = $this->inlinePolicy->richTextMarker($sourceInline);
            if ( '' !== $marker ) {
                $headerCarrier = array_intersect_key($inline, array( 'place-items' => true, 'box-shadow' => true ));
                if ( array() !== $headerCarrier && SourceDom::hasAncestorTag($sourceInline, array( 'header' )) ) {
                    $selector = RichTextMarkerSelector::carrierSelectorList($marker);
                    $this->session->generatedSupportStylesheetState()->registerHeaderRichText($marker, $selector . '{' . $this->styleResolver->cssDeclarationString($headerCarrier) . '}');
                }
                $inline['--blocks-engine-richtext-marker'] = $marker;
            }
            // The serialized inline keeps the author's classes as selector hooks,
            // and an inline declaration out-ranks every stylesheet rule — a base
            // value projected here would freeze the breakpoint it came from onto
            // the carrier and silence the responsive class rule at every width.
            $inline = $this->styleResolver->stripResponsiveClassOwnedDeclarations($sourceInline, $inline);
            if ( array() === $inline ) {
                continue;
            }

            $existing = $this->styleResolver->cssDeclarations(SourceDom::attr($targetInline, 'style'));
            $targetInline->setAttribute('style', $this->styleResolver->cssDeclarationString(array_merge($inline, $existing)));
        }

        // Comments are authoring metadata that Gutenberg would expose as visible RichText.
        $xpath = new \DOMXPath($document);
        foreach ( $xpath->query('//body//comment()') ?: array() as $comment ) {
            $comment->parentNode?->removeChild($comment);
        }

        return SourceDom::innerHtml($body);
    }

    /** @return array<string, string> */
    public function inlineVisualDeclarations(DOMElement $element): array
    {
        $allowed = array_flip(array(
            '-webkit-background-clip',
            '-webkit-text-fill-color',
            'background',
            'background-clip',
            'background-color',
            'border',
            'border-bottom',
            'border-color',
            'border-left',
            'border-radius',
            'border-right',
            'border-top',
            'box-shadow',
            'color',
            'display',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'letter-spacing',
            'line-height',
            'height',
            'max-height',
            'max-width',
            'margin',
            'margin-bottom',
            'margin-left',
            'margin-right',
            'margin-top',
            'padding',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-top',
            'place-items',
            'text-decoration',
            'text-transform',
            'width',
        ));

        $declarations = $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($element));
        if ( 'font' === strtolower($element->tagName) ) {
            $color = trim(SourceDom::attr($element, 'color'));
            $face = trim(SourceDom::attr($element, 'face'));
            $size = trim(SourceDom::attr($element, 'size'));
            if ( '' !== $color && ! isset($declarations['color']) ) {
                $declarations['color'] = $color;
            }
            if ( '' !== $face && ! isset($declarations['font-family']) ) {
                $declarations['font-family'] = $face;
            }
            $resolvedSize = $this->legacyFontSize($element);
            if ( '' !== $resolvedSize && ! isset($declarations['font-size']) ) {
                $declarations['font-size'] = $resolvedSize;
            }
        }

        if ( 'transparent' === strtolower((string) ($declarations['-webkit-text-fill-color'] ?? '')) ) {
            $declarations['color'] = 'transparent';
        }

        $declarations = array_intersect_key($declarations, $allowed);
        if ( ! SourceDom::hasAncestorTag($element, array( 'header' )) ) {
            unset($declarations['box-shadow'], $declarations['place-items']);
        }
        if ( in_array(strtolower($element->tagName), array( 'em', 'i' ), true) ) {
            if ( 'italic' === strtolower((string) ($declarations['font-style'] ?? '')) ) {
                unset($declarations['font-style']);
            }
            if ( 'inherit' === strtolower((string) ($declarations['font-weight'] ?? '')) ) {
                unset($declarations['font-weight']);
            }
            foreach ( array( 'margin', 'margin-bottom', 'margin-left', 'margin-right', 'margin-top', 'padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top' ) as $property ) {
                if ( isset($declarations[$property]) && ! CssValueInspector::isNonZero($declarations[$property]) ) {
                    unset($declarations[$property]);
                }
            }
        }

        return $declarations;
    }

    public function contentWithoutDecorativeSvg(DOMElement $element): string
    {
        return $this->stripDecorativeSvg(SourceDom::innerHtml($element));
    }

    public function contentWithMaterializedSvgImages(DOMElement $element, string $content): ?string
    {
        if ( 0 === $element->getElementsByTagName('svg')->length ) {
            return $content;
        }

        $generatedAssets = $this->session->assetMaterializationState()->checkpoint();
        foreach ( $element->getElementsByTagName('svg') as $svg ) {
            if ( ! $svg instanceof DOMElement ) {
                continue;
            }
            $image = $this->svgMaterializer->inlineSvgRichTextImageMarkup($svg, false);
            if ( null === $image ) {
                $this->session->assetMaterializationState()->restore($generatedAssets);
                return null;
            }
            // RichText preparation may normalize SVG casing, so DOM serialization
            // is not a stable replacement key.
            $replaced = preg_replace('@<svg\b[^>]*>.*?</svg>@is', $image, $content, 1);
            if ( ! is_string($replaced) || $replaced === $content ) {
                $this->session->assetMaterializationState()->restore($generatedAssets);
                return null;
            }
            $content = $replaced;
        }

        return $content;
    }

    /**
     * When every `<button>` descendant of `$element` is inline-safe, lowers
     * each into a `<mark>` carrier — RichText-legal, and recognized by the
     * engine's own editability policy — instead of the raw `<button>` tag the
     * conservative fallback gate rejects by name alone.
     *
     * The button's presentational class is not copied onto the mark: its
     * resolved visual declarations (author CSS actually matching the source
     * button, e.g. a `color` from a utility class) are baked into the mark's
     * own `style` attribute instead, the same carrier mechanism already used
     * to lower a class-bearing span/font. `role="button"` and `tabindex="0"`
     * keep the control itself labelled and keyboard-reachable; nothing wires
     * it to behavior, because `isInlineSafeButton()` already refused any
     * button that had some.
     */
    public function contentWithInlineSafeButtonsLowered(DOMElement $element, string $content): ?string
    {
        if ( '' === $content || ! preg_match('/<button\b/i', $content) || ! $this->hasOnlyInlineSafeButtons($element) ) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if ( ! $body instanceof DOMElement ) {
            return null;
        }

        $sourceButtons = array();
        foreach ( $element->getElementsByTagName('button') as $sourceButton ) {
            if ( $sourceButton instanceof DOMElement ) {
                $sourceButtons[] = $sourceButton;
            }
        }

        $targetButtons = array();
        foreach ( $body->getElementsByTagName('button') as $targetButton ) {
            if ( $targetButton instanceof DOMElement ) {
                $targetButtons[] = $targetButton;
            }
        }

        foreach ( $targetButtons as $index => $targetButton ) {
            $sourceButton = $sourceButtons[$index] ?? null;
            if ( ! $sourceButton instanceof DOMElement ) {
                continue;
            }
            $this->replaceInlineSafeButtonWithMark($sourceButton, $targetButton);
        }

        return SourceDom::innerHtml($body);
    }

    private function hasOnlyInlineSafeButtons(DOMElement $element): bool
    {
        $buttons = $element->getElementsByTagName('button');
        if ( 0 === $buttons->length ) {
            return false;
        }

        foreach ( $buttons as $button ) {
            if ( ! $button instanceof DOMElement || ! FormControlClassifier::isInlineSafeButton($button) ) {
                return false;
            }
        }

        return true;
    }

    private function replaceInlineSafeButtonWithMark(DOMElement $sourceButton, DOMElement $targetButton): void
    {
        $document = $targetButton->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return;
        }

        $declarations = $this->inlineVisualDeclarations($sourceButton);
        if ( ! isset($declarations['background-color']) ) {
            $declarations['background-color'] = 'transparent';
        }
        if ( ! isset($declarations['color']) ) {
            $declarations['color'] = 'inherit';
        }

        $mark = $document->createElement('mark');
        $mark->setAttribute('style', $this->styleResolver->cssDeclarationString($declarations));
        $mark->setAttribute('role', 'button');
        $mark->setAttribute('tabindex', '0');

        while ( null !== $targetButton->firstChild ) {
            $mark->appendChild($targetButton->firstChild);
        }

        $targetButton->parentNode?->replaceChild($mark, $targetButton);
    }

    public function requiresHtmlFallbackWithoutNativeSvgImageObjects(string $content): bool
    {
        // Generic fallback rejects arbitrary images. Remove only SVG image objects
        // materialized by this transform before applying that conservative gate.
        $content = preg_replace_callback(
            '@<img\b[^>]*\s*/?>@i',
            fn (array $matches): string => $this->isGeneratedInlineSvgSource($this->imageSourceFromMarkup($matches[0])) ? '' : $matches[0],
            $content
        ) ?? $content;
        return $this->requiresHtmlFallback($content);
    }

    public function containsNativeSvgImageObject(string $content): bool
    {
        if ( ! preg_match_all('@<img\b[^>]*\s*/?>@i', $content, $matches) ) {
            return false;
        }

        foreach ( $matches[0] as $markup ) {
            if ( $this->isGeneratedInlineSvgSource($this->imageSourceFromMarkup($markup)) ) {
                return true;
            }
        }

        return false;
    }

    public function stripDecorativeSvg(string $content): string
    {
        $content = preg_replace('/<(?:span|i|b)\b(?=[^>]*\baria-hidden\s*=\s*(["\'])true\1)[^>]*>\s*<svg\b[\s\S]*?<\/svg>\s*<\/(?:span|i|b)>\s*/i', '', $content) ?? $content;
        return preg_replace('/<svg\b(?=[^>]*\baria-hidden\s*=\s*(["\'])true\1)[\s\S]*?<\/svg>\s*/i', '', $content) ?? $content;
    }

    private function legacyFontSize(DOMElement $element): string
    {
        $sizes = array('1' => '10px', '2' => '13px', '3' => '16px', '4' => '18px', '5' => '24px', '6' => '32px', '7' => '48px');
        $level = 3;
        $found = false;
        $fonts = array();
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
            if ( 'font' === strtolower($node->tagName) ) {
                $fonts[] = $node;
            }
        }
        foreach ( array_reverse($fonts) as $font ) {
            $size = trim(SourceDom::attr($font, 'size'));
            if ( preg_match('/^[1-7]$/', $size) ) {
                $level = (int) $size;
                $found = true;
            } elseif ( preg_match('/^[+-]\d+$/', $size) ) {
                $level = min(7, max(1, $level + (int) $size));
                $found = true;
            }
        }
        return $found ? $sizes[(string) $level] : '';
    }

    private function imageSourceFromMarkup(string $markup): string
    {
        return preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $markup, $matches)
            ? html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }

    private function isGeneratedInlineSvgSource(string $source): bool
    {
        return $this->session->assetMaterializationState()->hasInlineSvgSource($source);
    }
}

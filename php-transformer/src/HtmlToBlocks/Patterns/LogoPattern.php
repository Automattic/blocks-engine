<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\InlineStackingClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\AssetMaterializationState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LoweredLinkHost;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMDocument;
use DOMElement;

final class LogoPattern implements PatternRecognizerInterface
{
    /** Inline formatting a logo label keeps; every other element is unwrapped. */
    private const LABEL_TAGS = array( 'a', 'em', 'i', 'strong', 'b', 'mark', 'small', 'sub', 'sup', 'br' );

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $logo = $context->logoContext();
        if ( null === $logo ) {
            return null;
        }

        // A linked text lockup whose spans render as stacked block boxes — a
        // column flex/grid brand, or block-level spans — keeps its two authored
        // lines only as a link wrapper. Flattening it into one label merges
        // the lines into a single run, so decline and let the anchor dispatcher
        // convert it as a link wrapper group.
        if (
            'a' === strtolower($element->tagName)
            && '' !== LinkUrlSanitizer::sanitize($element->hasAttribute('href') ? $element->getAttribute('href') : '')
            && ! $this->hasStructuredAnchorChrome($element)
            && InlineStackingClassifier::stacksInlineChildren($element, $logo->structuralPresentationDeclarations(...))
        ) {
            return null;
        }

        // A refused SVG materialization inside the label is recorded, not
        // silently stripped: the sink accumulates the diagnostics that ride
        // back on the recognition result when the pattern's block wins.
        $fallbacks = array();
        $captureDroppedSvg = static function (DOMElement $svg) use ($logo, &$fallbacks): void {
            $logo->captureSvgFallback($svg, $fallbacks);
        };

        $block = $this->match(
            $element,
            $context->presentationAttributes(...),
            fn (DOMElement $source): string => $logo->richText($source),
            fn (DOMElement $source): string => SourceDom::outerHtml($source),
            fn (DOMElement $source, string $content): ?string => $logo->materializeSvgImages($source, $content),
            $context->createBlock(...),
            $captureDroppedSvg
        );

        if ( null !== $block && 'a' === strtolower($element->tagName) && $this->hasStructuredAnchorChrome($element) ) {
            $context->buttonContext()?->retainSourceDescendants($element, onlyProjectedTopology: true);
        }

        return null === $block ? null : new PatternRecognitionResult($block, $fallbacks);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement): string $outerHtml
     * @param callable(DOMElement, string): ?string $materializeSvgImages
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @param Closure(DOMElement): void|null $captureDroppedSvg Invoked once per SVG the label cannot keep
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $outerHtml, callable $materializeSvgImages, callable $createBlock, ?Closure $captureDroppedSvg = null): ?array
    {
        if ( ! $this->hasLogoSignal($element)
            || ( '' === trim($element->textContent ?? '') && 0 === $element->getElementsByTagName('svg')->length ) ) {
            return null;
        }

        $tagName = strtolower($element->tagName);
        if ( 'a' !== $tagName && $this->containsBlockContent($element) ) {
            return null;
        }

        if ( 'a' === $tagName && $this->hasStructuredAnchorChrome($element) ) {
            // Media is not button chrome. Keep the source anchor and its
            // layout in a native RichText host, without button theme defaults.
            $html = $outerHtml($element);
            $label = $materializeSvgImages($element, $innerHtml($element)) ?? $innerHtml($element);
            $content = substr($html, 0, (int) strpos($html, '>') + 1) . $label . '</a>';
            $attrs = LoweredLinkHost::attributes($presentationAttributes($element), $element);
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), 'blocks-engine-inline-layout-carrier');
            return $createBlock('core/paragraph', array_merge($attrs, array('content' => $content, 'preserveInlineLayoutLeaf' => true)), array(), $element);
        }

        // The logo cue proves branding, not inline semantics. A whole heading
        // already has a native editable owner; the link-wrapper path preserves
        // its level, authored selectors, and home link instead of flattening it.
        if ( 'a' === $tagName && SourceDom::onlyChildHeading($element) instanceof DOMElement ) {
            return null;
        }

        $content = 'a' === $tagName
            ? $this->anchorLogoContent($element, $innerHtml($element), $materializeSvgImages, $captureDroppedSvg)
            : $this->logoLabelHtml($element, $innerHtml($element), $materializeSvgImages, $captureDroppedSvg);
        if ( '' === trim($content) ) {
            return null;
        }

        $attrs = $presentationAttributes($element);
        if ( 'a' === $tagName && str_starts_with($content, '<a') ) {
            // The paragraph only hosts the saved link; the link keeps its classes.
            $attrs = LoweredLinkHost::attributes($attrs, $element);
        }
        if ( 'div' === $tagName ) {
            $attrs = array_replace_recursive(array(
                'style' => array(
                    'spacing' => array(
                        'margin' => array( 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0' ),
                    ),
                ),
            ), $attrs);
        }

        return $createBlock('core/paragraph', array_merge($attrs, array( 'content' => $content )), array(), $element);
    }

    /** @param callable(DOMElement, string): ?string $materializeSvgImages */
    private function anchorLogoContent(DOMElement $anchor, string $html, callable $materializeSvgImages, ?Closure $captureDroppedSvg = null): string
    {
        $label = $this->logoLabelHtml($anchor, $html, $materializeSvgImages, $captureDroppedSvg);
        if ( '' === trim($this->plainText($label)) ) {
            $label = $this->accessibleFallbackLabel($anchor);
        }

        if ( '' === trim($label) ) {
            return '';
        }

        $href = SourceDom::safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : '');
        if ( '' === $href ) {
            return $label;
        }

        $attrs = array( 'href' => $href );
        $class = LoweredLinkHost::classAttribute($anchor);
        if ( '' !== $class ) {
            $attrs = array( 'class' => $class ) + $attrs;
        }
        foreach ( array( 'target', 'rel', 'title' ) as $name ) {
            if ( $anchor->hasAttribute($name) && '' !== trim($anchor->getAttribute($name)) ) {
                $attrs[$name] = $anchor->getAttribute($name);
            }
        }

        return '<a' . SourceDom::htmlAttributeString($attrs) . '>' . $label . '</a>';
    }

    /**
     * @param callable(DOMElement, string): ?string $materializeSvgImages
     * @param Closure(DOMElement): void|null $captureDroppedSvg
     */
    private function logoLabelHtml(DOMElement $element, string $html, callable $materializeSvgImages, ?Closure $captureDroppedSvg = null): string
    {
        $html = preg_replace('/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1[^>]*>/is', '$2', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/is', '', $html) ?? $html;
        $materialized = $materializeSvgImages($element, $html);
        if ( null === $materialized && null !== $captureDroppedSvg ) {
            // RichText cannot carry the raw markup and the materializer refused
            // the artwork. The label keeps its text; the SVG it cannot keep is
            // recorded as fallback evidence instead of being stripped silently.
            foreach ( $element->getElementsByTagName('svg') as $svg ) {
                if ( $svg instanceof DOMElement ) {
                    $captureDroppedSvg($svg);
                }
            }
        }
        $html = $materialized ?? (preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html);
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = $this->semanticMarkerSpansAsMarks($html);
        $html = trim($html);
        $text = $this->plainText($html);
        if ( '' === $text && ! preg_match('/<img\b[^>]*>/i', $html) ) {
            return '';
        }

        // An empty inline box that survived RichText materialization is a
        // CSS-painted decoration (a dot between two words). It stays in place
        // so block creation lowers it onto the same RichText carrier the
        // paragraph path uses, instead of being unwrapped into nothing.
        // A materialized inline SVG image object is the native RichText
        // carrier for the source artwork, so it is label content too.
        $body = $this->labelFragment($html);
        if ( null === $body ) {
            return $html;
        }
        // An image-only label is not editable text. Declining lets the generic
        // element path convert the artwork to a native image block that keeps
        // its source link, instead of pinning a bare image object inside a
        // text paragraph.
        if ( '' === $text && $this->carriesMaterializedSvgImage($body) ) {
            return '';
        }

        $unsupported = array();
        foreach ( $body->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement
                && ! in_array(strtolower($descendant->tagName), self::LABEL_TAGS, true)
                && ! $this->isMaterializedSvgImage($descendant)
                && ! ( 'span' === strtolower($descendant->tagName) && SourceDom::isEmptyInlineBox($descendant) ) ) {
                $unsupported[] = $descendant;
            }
        }
        if ( array() === $unsupported ) {
            return $html;
        }

        // Unwrap each unsupported element into its children, keeping a space
        // where it opened so adjacent words stay apart.
        foreach ( $unsupported as $descendant ) {
            $parent = $descendant->parentNode;
            if ( null === $parent ) {
                continue;
            }
            $parent->insertBefore($body->ownerDocument->createTextNode(' '), $descendant);
            while ( null !== $descendant->firstChild ) {
                $parent->insertBefore($descendant->firstChild, $descendant);
            }
            $parent->removeChild($descendant);
        }

        $unwrapped = trim($this->fragmentHtml($body));
        return '' !== $unwrapped ? $unwrapped : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A materialized inline SVG image object is the native RichText carrier for the source artwork. */
    private function isMaterializedSvgImage(DOMElement $element): bool
    {
        return 'img' === strtolower($element->tagName)
            && AssetMaterializationState::isInlineSvgAssetReference(trim($element->getAttribute('src')));
    }

    private function carriesMaterializedSvgImage(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('img') as $image ) {
            if ( $image instanceof DOMElement && $this->isMaterializedSvgImage($image) ) {
                return true;
            }
        }

        return false;
    }

    /** Parses label markup into a detached `<body>` holding it, or null when it does not parse. */
    private function labelFragment(string $html): ?DOMElement
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;

        return $body instanceof DOMElement ? $body : null;
    }

    private function fragmentHtml(DOMElement $body): string
    {
        $content = '';
        foreach ( $body->childNodes as $child ) {
            $content .= $body->ownerDocument->saveHTML($child);
        }

        return $content;
    }

    private function semanticMarkerSpansAsMarks(string $html): string
    {
        if ( ! str_contains($html, '--blocks-engine-richtext-marker:') ) {
            return $html;
        }

        $body = $this->labelFragment($html);
        if ( null === $body ) {
            return $html;
        }

        $spans = array();
        foreach ( $body->getElementsByTagName('span') as $span ) {
            if ( $span instanceof DOMElement && str_contains($span->getAttribute('style'), '--blocks-engine-richtext-marker:') ) {
                $spans[] = $span;
            }
        }
        foreach ( $spans as $span ) {
            $mark = $body->ownerDocument->createElement('mark');
            $mark->setAttribute('style', $span->getAttribute('style'));
            while ( null !== $span->firstChild ) {
                $mark->appendChild($span->firstChild);
            }
            $span->parentNode?->replaceChild($mark, $span);
        }

        return $this->fragmentHtml($body);
    }

    private function unwrapPresentationalSpan(string $html): string
    {
        while ( preg_match('/^<span\b[^>]*>(.*)<\/span>$/is', $html, $matches) === 1 && $this->spanWrapsEntireContent($matches[1]) ) {
            $html = trim($matches[1]);
        }

        return $html;
    }

    private function spanWrapsEntireContent(string $inner): bool
    {
        $depth = 0;
        if ( preg_match_all('/<(\/?)span\b[^>]*>/i', $inner, $matches) ) {
            foreach ( $matches[1] as $slash ) {
                $depth += '' === $slash ? 1 : -1;
                if ( $depth < 0 ) {
                    return false;
                }
            }
        }

        return 0 === $depth;
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function accessibleFallbackLabel(DOMElement $element): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            if ( '' !== $label ) {
                return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $image = $element->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $alt = trim($image->hasAttribute('alt') ? $image->getAttribute('alt') : '');
            if ( '' !== $alt ) {
                return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $title = $element->getElementsByTagName('title')->item(0);
        if ( $title instanceof DOMElement && '' !== trim($title->textContent ?? '') ) {
            return htmlspecialchars(trim($title->textContent ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }
    private function hasLogoSignal(DOMElement $element): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'logo', 'brand', 'branding' ), true) ) {
                    return true;
                }
            }

            if ( preg_match('/(?:^|[^a-z0-9])site-(?:logo|title)(?:[^a-z0-9]|$)/i', $value) ) {
                return true;
            }
        }

        return false;
    }

    private function containsBlockContent(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( $this->isBlockContentTag(strtolower($child->tagName)) ) {
                return true;
            }

            if ( $this->containsBlockContent($child) ) {
                return true;
            }
        }

        return false;
    }

    private function hasStructuredAnchorChrome(DOMElement $anchor): bool
    {
        foreach ( $anchor->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'img', 'picture', 'svg' ), true) ) {
                return true;
            }
        }

        foreach ( $anchor->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'true' !== strtolower(trim($child->getAttribute('aria-hidden'))) ) {
                continue;
            }

            if ( '' !== trim($child->getAttribute('class'))
                || '' !== trim($child->getAttribute('id'))
                || '' !== trim($child->getAttribute('style')) ) {
                return true;
            }
        }

        return false;
    }

    private function isBlockContentTag(string $tagName): bool
    {
        return in_array($tagName, array(
            'address',
            'article',
            'aside',
            'blockquote',
            'details',
            'div',
            'dl',
            'fieldset',
            'figcaption',
            'figure',
            'footer',
            'form',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'header',
            'hr',
            'li',
            'main',
            'nav',
            'ol',
            'p',
            'pre',
            'section',
            'table',
            'ul',
        ), true);
    }
}

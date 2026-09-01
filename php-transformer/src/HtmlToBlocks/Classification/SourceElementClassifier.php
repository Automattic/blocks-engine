<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\InlineContentElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use DOMElement;

/**
 * The engine's structural source-element vocabulary.
 *
 * Answers what a source element *is* (inline content, a visual layer,
 * card-like, an expanded carousel state, a runtime media placeholder) and what
 * it *has* (block content children, responsive image sources, gallery items, a
 * logo brand signal) — using nothing but the element and its subtree.
 *
 * Every predicate here is a pure function of the passed {@see DOMElement}. They
 * read no transform state, hold none, mutate nothing, and take no collaborators,
 * so a caller can construct this with `new SourceElementClassifier()` and assert
 * one predicate against one HTML fragment without building a transformer.
 *
 * That constructor is the boundary. A question needing resolved styles,
 * materialized assets, runtime-island evidence, or source provenance is
 * *presentational* classification, not structural, and stays with the
 * collaborator that owns the evidence — adding a dependency here would take
 * this vocabulary back out of independent reach.
 */
final class SourceElementClassifier
{
    public function hasRepeatedDirectChildTags(DOMElement $element): bool
    {
        $counts = array();
        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            if (2 <= $counts[$tag]) {
                return true;
            }
        }
        return false;
    }

    public function isRuntimeMediaSurfaceElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'iframe', 'canvas', 'embed', 'object' ), true) ) {
            return true;
        }

        return str_contains($tagName, '-')
            && 1 === preg_match('/(?:^|-)(?:audio|carousel|gallery|iframe|media|player|slideshow|video)(?:-|$)/', $tagName);
    }

    public function isDependentRuntimeMediaMask(DOMElement $element): bool
    {
        if ( '' !== trim($this->attr($element, 'aria-label'))
            || in_array(strtolower(trim($this->attr($element, 'role'))), array( 'img', 'graphics-document', 'graphics-symbol' ), true)
            || 0 < $element->getElementsByTagName('title')->length
            || 0 < $element->getElementsByTagName('desc')->length
        ) {
            return false;
        }

        $identity = strtolower(implode(' ', array(
            $this->attr($element, 'id'),
            $this->attr($element, 'class'),
            $this->attr($element, 'data-role'),
        )));
        if ( 1 === preg_match('/\b(?:clip|mask|overlay)\b/', $identity) ) {
            return true;
        }

        $paths = $element->getElementsByTagName('path');
        $path = 1 === $paths->length ? $paths->item(0) : null;
        return $path instanceof DOMElement
            && 1 === $element->getElementsByTagName('*')->length
            && '' !== trim($this->attr($path, 'd'))
            && '' === trim($this->attr($element, 'fill'))
            && '' === trim($this->attr($element, 'stroke'))
            && '' === trim($this->attr($path, 'fill'))
            && '' === trim($this->attr($path, 'stroke'));
    }

    public function isStructuralTransparentCustomWrapperChild(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'article', 'aside', 'blockquote', 'div', 'dl', 'figure', 'footer', 'form', 'header', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' ), true);
    }

    public function hasGalleryMediaItems(DOMElement $element): bool
    {
        $items = 0;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || 'figcaption' === strtolower($child->tagName) ) {
                if ( ! $child instanceof DOMElement ) {
                    return false;
                }
                continue;
            }
            if ( ! in_array(strtolower($child->tagName), array( 'figure', 'img', 'picture' ), true) ) {
                return false;
            }
            ++$items;
        }

        return $items >= 2;
    }

    /** @param array<string, string> $declarations */
    public function hasResolvedInset(array $declarations): bool
    {
        foreach ( array( 'inset', 'inset-block', 'inset-inline', 'inset-block-start', 'inset-block-end', 'inset-inline-start', 'inset-inline-end', 'top', 'right', 'bottom', 'left' ) as $property ) {
            $value = strtolower(trim((string) ($declarations[$property] ?? '')));
            if ( ! in_array($value, array( '', 'auto', 'inherit', 'initial', 'unset', '0', '0px', '0rem', '0em', '0%' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $block */
    public function isLayoutShellBlock(array $block): bool
    {
        return str_ends_with((string) ($block['blockName'] ?? ''), '/layout-shell')
            && 0 < count(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
    }

    /** @param array<string, mixed> $block */
    public function isSingleGroupShellCandidate(array $block): bool
    {
        if ('core/group' !== ($block['blockName'] ?? null)
            || 1 !== count(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array())
            || isset($block['_binding_token'])
            || in_array(strtolower((string) ($block['attrs']['tagName'] ?? 'div')), array('ul', 'ol', 'li'), true)
        ) {
            return false;
        }
        return true;
    }

    /** @param array<string, mixed> $block */
    public function hasSourceProjectionClass(array $block): bool
    {
        return (bool) preg_match('/(?:^|\s)blocks-engine-(?:attribute|css-owned|editor-anchor|semantic|source)-/', (string) ($block['attrs']['className'] ?? ''));
    }

    public function isLayoutShellSerializableStyle(string $style): bool
    {
        // React style objects cannot express declaration priority. Other
        // canonical serialized values remain strings and are parsed directly
        // by layout-shell without a normalizing CSSOM round trip.
        return !preg_match('/!\s*important/i', $style);
    }

    public function hasMotionStructureToken(DOMElement $element): bool
    {
        $identity = strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:band|carousel|loop|marquee|mask|rail|scroller|slider|ticker|track|viewport)(?:[^a-z0-9]|$)/', $identity);
    }

    public function isNonNeutralVisualTopologyDeclaration(string $property, string $value): bool
    {
        $value = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value));
        if ( '' === $value || in_array($value, array( 'auto', 'none', 'normal', 'static', 'visible', 'transparent', 'inherit', 'initial', 'revert', 'revert-layer', 'unset' ), true) ) {
            return false;
        }
        if ( in_array($property, array( 'color', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'line-height', 'text-align', 'text-decoration' ), true) ) {
            return false;
        }
        return 1 === preg_match('/^(?:align-|appearance|aspect-ratio|background|border|bottom|box-shadow|column|contain|cursor|display|filter|flex|gap|grid|height|inset|isolation|left|margin|max-|min-|opacity|outline|overflow|padding|perspective|position|right|row-gap|table-layout|top|transform|vertical-align|width|z-index)/', $property);
    }

    public function isPositiveCssLength(string $value): bool
    {
        if ( ! preg_match('/^([+]?(?:\d+(?:\.\d+)?|\.\d+))(?:px|em|rem|ex|ch|cm|mm|in|pt|pc|vw|vh|vmin|vmax)$/i', trim($value), $matches) ) {
            return false;
        }

        return (float) $matches[1] > 0;
    }

    public function isVisibleEmptyVisualPaint(string $value): bool
    {
        $value = strtolower(trim($value));
        if ( '' === $value || 'none' === $value || 'transparent' === $value || preg_match('/^rgba?\([^)]*,\s*0(?:\.0+)?\s*\)$/', $value) ) {
            return false;
        }

        return ! preg_match('/^#[0-9a-f]{4}$|^#[0-9a-f]{8}$/i', $value) || ! str_ends_with($value, '0');
    }

    public function isVisibleEmptyVisualBorder(string $value): bool
    {
        return ! str_contains(strtolower($value), 'transparent')
            && ! preg_match('/rgba?\([^)]*,\s*0(?:\.0+)?\s*\)/i', $value)
            && $this->isVisibleEmptyVisualPaint($value)
            && ! preg_match('/(?:^|\s)0(?:\.0+)?(?:px|em|rem|ex|ch|cm|mm|in|pt|pc|vw|vh|vmin|vmax)?(?:\s|$)/i', trim($value));
    }

    public function isInlineContentElement(string $tagName): bool
    {
        return InlineContentElementConverter::handlesTag($tagName);
    }

    public function isInlineSourceElement(string $tagName): bool
    {
        return $this->isInlineContentElement($tagName)
            || in_array($tagName, array( 'a', 'audio', 'bdi', 'bdo', 'button', 'canvas', 'data', 'del', 'dfn', 'img', 'ins', 'label', 'meter', 'output', 'picture', 'progress', 'q', 's', 'select', 'svg', 'textarea', 'u', 'video' ), true);
    }

    public function hasBlockContentChildren(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            $tagName = $child instanceof DOMElement ? strtolower($child->tagName) : '';
            if ( $child instanceof DOMElement && 'br' !== $tagName && ! $this->isInlineContentElement($tagName) ) {
                return true;
            }
        }

        return false;
    }

    public function hasLogoBrandSignal(DOMElement $element): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($this->attr($element, $attribute))) ?: array() as $token ) {
                if ( in_array($token, array( 'logo', 'brand', 'branding' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasInlineTokenGroupSignal(DOMElement $element): bool
    {
        if ( $this->hasInlineTokenSignal($element) ) {
            return true;
        }

        $tokenChildren = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isInlineTokenItemElement($child) ) {
                ++$tokenChildren;
            }
        }

        return 1 < $tokenChildren;
    }

    public function isInlineTokenItemElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'a', 'button' ), true) && ! $this->isInlineContentElement($tagName) ) {
            return false;
        }

        return $this->hasInlineTokenSignal($element);
    }

    public function hasInlineTokenSignal(DOMElement $element): bool
    {
        $tokens = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'role'),
            $this->attr($element, 'data-filter'),
            $this->attr($element, 'data-tag'),
        ))));

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:chips?|pills?|badges?|tags?|filters?|facets?)(?:[^a-z0-9]|$)/', $tokens);
    }

    public function hasOnlyPhrasingChildren(DOMElement $element): bool
    {
        $nonAnchorText = false;

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    $nonAnchorText = true;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'a' === $tagName ) {
                continue;
            }

            if ( 'br' === $tagName || $this->isInlineContentElement($tagName) ) {
                $nonAnchorText = true;
                continue;
            }

            return false;
        }

        return $nonAnchorText;
    }

    public function isClassedPhrasingItem(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'br' === $tagName || ( 'a' !== $tagName && ! $this->isInlineContentElement($tagName) ) ) {
            return false;
        }

        return '' !== trim($this->attr($element, 'class')) || '' !== trim($this->attr($element, 'style'));
    }

    public function isCardLikeElement(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        return 'article' === strtolower($element->tagName) || (bool) preg_match('/(?:^|[\s_-])(?:card|feature|service|provider|resource|post|project|stat|badge|tile|panel|item)(?:$|[\s_-])/', $className);
    }

    public function isVisualLayerElement(DOMElement $element): bool
    {
        $context = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'aria-label'),
        ))));
        $style = strtolower($this->attr($element, 'style'));

        if ( preg_match('/(?:^|[\s_-])(?:hero|decor|decorative|layer|overlay|grain|noise|texture|glow|atmosphere|ambient|aura|orb|blob|backdrop|background|bg)(?:$|[\s_-])/', $context) ) {
            return true;
        }

        return (bool) ( preg_match('/(?:^|;)\s*position\s*:\s*(?:fixed|absolute)\b/', $style)
            && preg_match('/(?:^|;)\s*(?:inset|top|right|bottom|left|z-index|pointer-events|mix-blend-mode|opacity|filter|background|background-image)\s*:/', $style) );
    }

    /**
     * @param array<int, string> $tokens
     */
    public function hasCommerceToken(DOMElement $element, array $tokens): bool
    {
        foreach ( array( 'class', 'id', 'itemprop' ) as $attribute ) {
            $value = strtolower($this->attr($element, $attribute));
            foreach ( preg_split('/[^a-z0-9]+/', $value) ?: array() as $token ) {
                if ( in_array($token, $tokens, true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isMetadataLayoutStyle(string $style): bool
    {
        return 1 === preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?(?:grid|flex)\b/i', $style);
    }

    public function isFlexMetadataStyle(string $style): bool
    {
        return 1 === preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/i', $style);
    }

    public function isPresentationalAnimationSelector(string $selector): bool
    {
        $name = '';
        if ( preg_match('/\[(data-[A-Za-z][A-Za-z0-9_-]*)/', $selector, $match) ) {
            $name = substr(strtolower((string) $match[1]), 5);
        } elseif ( preg_match('/^(?:[a-z][a-z0-9-]*\.|\.)([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        } elseif ( preg_match('/^#([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        }

        if ( '' === $name ) {
            return false;
        }

        foreach ( preg_split('/[^a-z0-9]+/', $name) ?: array() as $token ) {
            if ( in_array($token, array( 'animate', 'animation', 'appear', 'count', 'counter', 'delay', 'fade', 'motion', 'parallax', 'reveal', 'scroll', 'stagger', 'transition' ), true) ) {
                return true;
            }
        }

        return false;
    }

    public function hasDirectMediaChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'img', 'picture', 'svg', 'video', 'audio' ), true) ) {
                return true;
            }
        }

        return false;
    }

    public function isInlineCommerceRowChild(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'span', 'strong', 'em', 'small', 'time' ), true) ) {
            return ! $this->hasBlockContentChildren($element);
        }

        return false;
    }

    public function isNameElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'name', 'title', 'product', 'dish', 'item', 'plan', 'tier' )) || preg_match('/^h[1-6]$/', strtolower($element->tagName));
    }

    public function isLabelValueValueElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'value', 'detail', 'title', 'name', 'content', 'description', 'desc', 'meta', 'session', 'event', 'location', 'venue' ))
            || preg_match('/^h[1-6]$/', strtolower($element->tagName));
    }

    public function isDayElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'day', 'date', 'label' )) || (bool) preg_match('/\b(?:mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?|weekdays?|weekends?)\b/i', $element->textContent ?? '');
    }

    public function isTimeValueElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'time', 'hours', 'value', 'closed' )) || (bool) preg_match('/\b(?:closed|open|\d{1,2}(?::\d{2})?\s*(?:am|pm)?\s*(?:[\x{2013}\x{2014}-]|to)\s*\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\b/iu', $element->textContent ?? '');
    }

    public function isNavigationSectionHeading(DOMElement $element): bool
    {
        if ( preg_match('/^h[1-6]$/i', $element->tagName) ) {
            return true;
        }

        if ( ! in_array(strtolower($element->tagName), array( 'div', 'p', 'span' ), true) || '' === trim($element->textContent ?? '') ) {
            return false;
        }

        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id') . ' ' . $this->attr($element, 'role') . ' ' . $this->attr($element, 'aria-label')));
        return (bool) preg_match('/(?:^|[\s_-])(?:heading|label|title)(?:$|[\s_-])/', $name);
    }

    public function hasSoftNavigationSectionHeadingSignal(DOMElement $element): bool
    {
        return ! preg_match('/^h[1-6]$/i', $element->tagName) && $this->isNavigationSectionHeading($element);
    }

    public function hasNavigationContainerSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id')));
        return (bool) preg_match('/(?:^|[\s_-])(?:nav|navbar|navigation|menu|links)(?:$|[\s_-])/', $name);
    }

    public function hasDirectChildElement(DOMElement $element, string $tagName): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $tagName === strtolower($child->tagName) ) {
                return true;
            }
        }

        return false;
    }

    public function hasPictureSourceSelection(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('source') as $source ) {
            if ( $source instanceof DOMElement && '' !== $this->attr($source, 'srcset') ) {
                return true;
            }
        }

        return false;
    }

    public function isImageCarrierButton(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') || 'submit' === strtolower($this->attr($element, 'type')) ) {
            return false;
        }

        return 0 < $element->getElementsByTagName('img')->length;
    }

    public function hasResponsiveImageSources(DOMElement $element): bool
    {
        if ( 'img' === strtolower($element->tagName) ) {
            return '' !== $this->attr($element, 'srcset') || '' !== $this->attr($element, 'sizes');
        }

        if ( $this->hasPictureSourceSelection($element) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('img') as $image ) {
            if ( $image instanceof DOMElement && ( '' !== $this->attr($image, 'srcset') || '' !== $this->attr($image, 'sizes') ) ) {
                return true;
            }
        }

        return false;
    }

    public function hasCapturedMediaContent(DOMElement $element): bool
    {
        return 0 < $element->getElementsByTagName('img')->length
            || 0 < $element->getElementsByTagName('svg')->length
            || 0 < $element->getElementsByTagName('video')->length;
    }

    public function hasCarouselIdentity(DOMElement $element): bool
    {
        $identity = strtolower(implode(' ', array(
            $element->tagName,
            $this->attr($element, 'id'),
            $this->attr($element, 'class'),
            $this->attr($element, 'role'),
            $this->attr($element, 'data-hook'),
            $this->attr($element, 'data-testid'),
        )));

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:carousel|gallery|slider|slideshow)(?:[^a-z0-9]|$)/', $identity);
    }

    public function isCarouselList(DOMElement $element): bool
    {
        return 'list' === strtolower(trim($this->attr($element, 'role')))
            || in_array(strtolower($element->tagName), array('ol', 'ul'), true);
    }

    public function isExpandedCarouselState(DOMElement $element, DOMElement $root): bool
    {
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && $ancestor !== $root; $ancestor = $ancestor->parentNode ) {
            $identity = strtolower(implode(' ', array(
                $ancestor->tagName,
                $this->attr($ancestor, 'class'),
                $this->attr($ancestor, 'role'),
                $this->attr($ancestor, 'data-hook'),
            )));
            if ( 1 === preg_match('/(?:^|[^a-z0-9])(?:dialog|expanded|lightbox|modal)(?:[^a-z0-9]|$)/', $identity) ) {
                return true;
            }
        }

        return false;
    }

    public function hasOnlySvgDefinitions(DOMElement $element): bool
    {
        $hasDefinition = false;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), array( 'defs', 'symbol' ), true) ) {
                return false;
            }
            $hasDefinition = true;
        }

        return $hasDefinition;
    }

    public function isDescendantOf(DOMElement $element, DOMElement $ancestor): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->isSameNode($ancestor) ) {
                return true;
            }
        }

        return false;
    }

    public function isUnsafeIframeDestination(string $value): bool
    {
        return 1 === preg_match('/^(?:javascript|data|vbscript)\s*:/i', $value);
    }

    public function isSafeVisualIframeUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && '' !== trim((string) ($parts['host'] ?? ''))
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    public function isPositiveIframeDimension(string $value): bool
    {
        if ( ! preg_match('/^(?:\d+|\d*\.\d+)(?:px)?$/i', $value, $matches) ) {
            return false;
        }

        return (float) $matches[0] > 0;
    }

    public function isRelativeIframeDimension(string $value): bool
    {
        return (bool) preg_match('/^(?:\d+|\d*\.\d+)%$/', $value)
            && (float) $value > 0;
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    private function childElementCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }
}

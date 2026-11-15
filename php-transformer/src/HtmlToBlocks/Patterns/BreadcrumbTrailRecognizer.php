<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use DOMElement;

/**
 * Recognizes a source breadcrumb trail: an ordered list of links, named as a
 * breadcrumb by itself or by the landmark around it. Core renders that trail
 * from the page hierarchy, so the source maps onto the breadcrumbs block's
 * own attributes instead of becoming a menu entity per document.
 */
final class BreadcrumbTrailRecognizer
{
    /**
     * A recognized breadcrumb trail: a breadcrumb-labeled ordered list of
     * links whose items walk one ancestor chain and end at the current
     * document. Core renders that trail from the page hierarchy, so the
     * source trail maps onto the block's own attributes instead of becoming
     * a menu entity per document. The trail's list is the recognized
     * element, whether it reaches this recognizer directly or wrapped in
     * layout its landmark owns.
     *
     * @param callable $createBlock
     */
    public function breadcrumbTrail(DOMElement $element, callable $createBlock, ?NavigationPatternContext $navigationContext): ?PatternRecognitionResult
    {
        if ( null === $navigationContext || $navigationContext->isRuntimeDomTarget($element) ) {
            return null;
        }
        $list = $this->breadcrumbTrailList($element);
        $links = null === $list ? null : $this->qualifiedTrailLinks($list, $navigationContext);
        if ( null === $links ) {
            return null;
        }

        return new PatternRecognitionResult($this->breadcrumbTrailBlock($list, $element, $links, $createBlock, $navigationContext));
    }

    /**
     * The trail list an element stands for: the ordered list itself, or the
     * one ordered list a trail landmark of its own holds directly. The breadcrumb
     * signal may name the list, the landmark, or a landmark around the list
     * — layout wrappers between a breadcrumb landmark and its list keep the
     * list's own naming empty, and stay ordinary groups.
     */
    private function breadcrumbTrailList(DOMElement $element): ?DOMElement
    {
        $tag = strtolower($element->tagName);
        if ( 'ol' === $tag ) {
            $list = $element;
        } else {
            // Only a trail's own landmark absorbs its list in place: a holder
            // the breadcrumb signal names, or a nav landmark. An unsignaled
            // layout wrapper keeps its own box for recursion to reach the list.
            if ( ! $this->hasBreadcrumbSignal($element) && ! MenuVocabulary::isMenuLandmark($element) ) {
                return null;
            }
            $lists = array();
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && 'ol' === strtolower($child->tagName) ) {
                    $lists[] = $child;
                }
            }
            // The landmark must hold the trail and nothing else: anything
            // beside it has no home in the single core block.
            $list = 1 === count($lists) && $this->soleElementChild($element, $lists[0]) ? $lists[0] : null;
        }
        if ( null === $list ) {
            return null;
        }
        if ( ! $this->hasBreadcrumbSignal($element) && ! $this->hasBreadcrumbSignal($list) && ! $this->enclosingBreadcrumbSignal($list) ) {
            return null;
        }

        return $list;
    }

    /** Whether a landmark around the list carries the breadcrumb signal. */
    private function enclosingBreadcrumbSignal(DOMElement $list): bool
    {
        for ( $ancestor = $list->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null ) {
            if ( $this->hasBreadcrumbSignal($ancestor) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A qualified trail list the element wraps deeper in its subtree, beneath
     * layout between the landmark and the list. The list keeps the
     * recognition; the element only steps aside for it, so a list that only
     * resembles a trail never gives up its landmark's menu conversion.
     */
    public function wrappedBreadcrumbTrailList(DOMElement $element, NavigationPatternContext $navigationContext): ?DOMElement
    {
        if ( 'ol' === strtolower($element->tagName) ) {
            return null;
        }
        $signaled = $this->hasBreadcrumbSignal($element);
        foreach ( $element->getElementsByTagName('ol') as $list ) {
            if ( ! $list instanceof DOMElement
                || ( ! $signaled && ! $this->hasBreadcrumbSignal($list) && ! $this->enclosingBreadcrumbSignal($list) ) ) {
                continue;
            }
            if ( null !== $this->qualifiedTrailLinks($list, $navigationContext) ) {
                return $list;
            }
        }

        return null;
    }

    /**
     * The trail's links when its items walk one ancestor chain that ends at
     * the current document; null when the list is not a qualified trail.
     *
     * @return list<array{item: DOMElement, anchor: DOMElement, href: string}>|null
     */
    private function qualifiedTrailLinks(DOMElement $list, NavigationPatternContext $navigationContext): ?array
    {
        $items = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( 'li' !== strtolower($child->tagName) ) {
                return null;
            }
            $items[] = $child;
        }
        if ( count($items) < 2 ) {
            return null;
        }

        // One link per item: separators live in generated content, not in
        // source nodes, and a label cannot nest another link.
        $links = array();
        foreach ( $items as $item ) {
            $children = array();
            foreach ( $item->childNodes as $child ) {
                if ( $child instanceof DOMElement ) {
                    $children[] = $child;
                }
            }
            if ( 1 !== count($children) || 'a' !== strtolower($children[0]->tagName) ) {
                return null;
            }
            $anchor = $children[0];
            $href = trim(SourceDom::attr($anchor, 'href'));
            if ( '' === $href || '' === trim($anchor->textContent ?? '') || 0 !== $anchor->getElementsByTagName('a')->length ) {
                return null;
            }
            $links[] = array( 'item' => $item, 'anchor' => $anchor, 'href' => $href );
        }

        // The trail is recognized only when its items walk one ancestor
        // chain that ends at the current document; anything else stays on
        // the ordinary menu path.
        $document = $navigationContext->sourcePath();
        $routes = array();
        foreach ( $links as $link ) {
            $route = self::trailRoute((string) $link['href'], $document);
            if ( null === $route ) {
                return null;
            }
            $routes[] = $route;
        }
        for ( $depth = 1; $depth < count($routes); $depth++ ) {
            if ( ! str_starts_with($routes[$depth], $routes[$depth - 1]) || strlen($routes[$depth]) <= strlen($routes[$depth - 1]) ) {
                return null;
            }
        }
        if ( ! $navigationContext->targetsCurrentDocument((string) $links[count($links) - 1]['href']) ) {
            return null;
        }

        return $links;
    }

    /**
     * The trail block: the source separator, link color, surface and padding
     * mapped onto the block's own attributes, with no route-named attribute
     * that would make each document need its own menu entity.
     *
     * The separator's own color (the source often paints the glyph, e.g.
     * `#888`) has no home: core/breadcrumbs styles the separator character
     * only, so that color stays on the projected author styles.
     *
     * @param list<array<string, mixed>> $links
     * @param callable $createBlock
     */
    private function breadcrumbTrailBlock(DOMElement $list, DOMElement $element, array $links, callable $createBlock, NavigationPatternContext $navigationContext): array
    {
        $attributes = array();
        $className = SourceDom::mergeClassNames(SourceDom::attr($element, 'class'), SourceDom::attr($list, 'class'));
        if ( '' !== $className ) {
            $attributes['className'] = $className;
        }
        $separator = $this->trailSeparator(array_column($links, 'item'), $navigationContext);
        if ( '' !== $separator && '/' !== $separator ) {
            $attributes['separator'] = $separator;
        }
        if ( '/' !== self::trailRoute((string) $links[0]['href'], $navigationContext->sourcePath()) ) {
            $attributes['showHomeItem'] = false;
        }
        $style = $this->trailStyleAttributes($list, $links[0]['anchor'], $navigationContext);
        if ( array() !== $style ) {
            $attributes['style'] = $style;
        }

        // Core labels crumbs with page titles; the source's own labels travel
        // by resolved route so the theme runtime can restore them.
        $trail = array();
        foreach ( $links as $link ) {
            $trail[] = array(
                'route' => self::trailRoute((string) $link['href'], $navigationContext->sourcePath()),
                'label' => trim(preg_replace('/\s+/', ' ', $link['anchor']->textContent ?? '') ?? ''),
            );
        }
        $attributes['metadata']['blocksEngineBreadcrumbTrail'] = $trail;

        // Core renders the source classes on its wrapper and a bare <ol>
        // inside; rules written for the source list address that <ol>.
        $navigationContext->recordInnerSubject($list, '.wp-block-breadcrumbs ol');

        return $createBlock('core/breadcrumbs', $attributes, array(), $element);
    }

    /** Whether the trail list is the element's only element child. */
    private function soleElementChild(DOMElement $element, DOMElement $child): bool
    {
        foreach ( $element->childNodes as $sibling ) {
            if ( $sibling instanceof DOMElement && $sibling !== $child ) {
                return false;
            }
        }

        return true;
    }

    private function hasBreadcrumbSignal(DOMElement $element): bool
    {
        if ( 1 === preg_match('/breadcrumb/i', SourceDom::attr($element, 'aria-label')) ) {
            return true;
        }
        foreach ( SourceDom::tokens(SourceDom::attr($element, 'class')) as $token ) {
            if ( 1 === preg_match('/breadcrumb/i', $token) ) {
                return true;
            }
        }

        return false;
    }

    /** The separator source rules paint between the trail's items. */
    private function trailSeparator(array $items, NavigationPatternContext $navigationContext): string
    {
        for ( $depth = 1; $depth < count($items); $depth++ ) {
            $separator = $navigationContext->generatedPseudoContent($items[$depth], 'before');
            if ( '' !== $separator ) {
                return $separator;
            }
        }
        for ( $depth = 0; $depth < count($items) - 1; $depth++ ) {
            $separator = $navigationContext->generatedPseudoContent($items[$depth], 'after');
            if ( '' !== $separator ) {
                return $separator;
            }
        }

        return '';
    }

    /**
     * The trail's own presentation that the block's style attributes keep:
     * the list's surface, and the links' color as the block's link color.
     *
     * @param array<int, array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private function trailStyleAttributes(DOMElement $list, DOMElement $anchor, NavigationPatternContext $navigationContext): array
    {
        $style = array();
        $trail = $navigationContext->resolvedStyleAttributes($list);
        if ( isset($trail['color']['background']) ) {
            $style['color']['background'] = $trail['color']['background'];
        }
        if ( isset($trail['spacing']['padding']) ) {
            $style['spacing']['padding'] = $trail['spacing']['padding'];
        }
        $linkColor = trim((string) ( $navigationContext->resolvedDeclarations($anchor)['color'] ?? '' ));
        if ( '' !== $linkColor ) {
            $style['color']['link'] = $linkColor;
        }

        return $style;
    }

    /** The site-rooted route an href names, in `/segment/` form; null when unresolvable. */
    private static function trailRoute(string $href, string $document): ?string
    {
        $href = ArtifactPath::stripQueryAndFragment($href);
        if ( '' === $href || 1 === preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $href) ) {
            return null;
        }
        $rooted = str_starts_with($href, '/');
        $path = ArtifactPath::resolveRelativePath($rooted ? ltrim($href, '/') : $href, $rooted ? '' : $document);
        if ( '' === $path ) {
            return $rooted ? '/' : null;
        }
        // Directory-index normalization: the index names its directory, so
        // `/about/index.html` and `/about/` address one route, and the site
        // root's own `index.html` addresses the root route itself.
        if ( str_ends_with($path, '/index.html') ) {
            $path = substr($path, 0, -strlen('/index.html'));
        } elseif ( 'index.html' === $path ) {
            $path = '';
        } elseif ( str_ends_with($path, '.html') ) {
            $path = substr($path, 0, -strlen('.html'));
        } else {
            $path = rtrim($path, '/');
        }
        if ( '' === $path ) {
            return '/';
        }
        if ( str_contains($path, '.') ) {
            return null;
        }

        return '/' . $path . '/';
    }
}

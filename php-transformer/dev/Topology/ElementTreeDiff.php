<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Topology;

use DOMElement;
use DOMNode;

/**
 * Compares a source element tree with the element tree the engine renders for
 * it, and reports every place where they diverge.
 *
 * Author CSS is written against the source tree. Wherever the rendered tree
 * adds, removes, wraps or retags an element, author selectors (child and
 * sibling combinators, `:nth-*`, tag selectors) stop matching what the author
 * targeted. This diff names those places so they can be removed, declared, or
 * gated.
 *
 * Elements are identified by tag, id and source classes; engine-owned classes
 * (`wp-*`, `blocks-engine-*`, `has-*`, `is-*`, `be-*`, alignment) are ignored
 * for identity. Children are aligned as ordered sequences: strict identity
 * first, then id/classes with a different tag (retagged), then equal text.
 * Unmatched rendered elements are reported as `added` and their children are
 * aligned in their place, so an inserted wrapper does not hide the identity of
 * what it wraps; a source wrapper the engine dropped is reported as
 * `removed-wrapper` the same way. Remaining source elements are `removed`.
 */
final class ElementTreeDiff
{
    public const ADDED = 'added';
    public const REMOVED = 'removed';
    public const REMOVED_WRAPPER = 'removed-wrapper';
    public const RETAGGED = 'retagged';

    /** Elements that carry no box of their own in the compared trees. */
    private const IGNORED_TAGS = array( 'script', 'style', 'noscript', 'template', 'link', 'meta', 'title', 'base' );

    /** Elements whose inner structure is replaced content, not author layout. */
    private const LEAF_TAGS = array( 'svg', 'select', 'iframe', 'video', 'audio', 'picture', 'canvas', 'object' );

    private const MAX_DEPTH = 6;

    /** @var array<int, array{kind: string, tag: string, marker: string, context: string}> */
    private array $events = array();

    private int $matched = 0;

    /**
     * @return array{matched: int, events: array<int, array{kind: string, tag: string, marker: string, context: string}>}
     */
    public static function compare(DOMElement $sourceRoot, DOMElement $renderedRoot): array
    {
        $diff = new self();
        $diff->sequence(self::children($sourceRoot), self::children($renderedRoot), 'body', 0);

        return array( 'matched' => $diff->matched, 'events' => $diff->events );
    }

    /**
     * Source classes of an element, sorted, with engine-owned classes removed.
     *
     * @return array<int, string>
     */
    public static function sourceClasses(DOMElement $element): array
    {
        $classes = array_values(array_filter(
            preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(),
            static fn (string $class): bool => '' !== $class && ! self::isEngineClass($class)
        ));
        sort($classes);

        return $classes;
    }

    public static function isEngineClass(string $class): bool
    {
        return 1 === preg_match('/^(wp-|blocks-engine-|be-|has-|is-|align(left|right|center|wide|full)$|are-vertically-|items-justified-|screen-reader-text$)/', $class);
    }

    /**
     * The most specific engine class naming why an element exists, without its
     * per-site hash: `blocks-engine-synthetic-paragraph`, `wp-block-buttons`.
     */
    public static function marker(DOMElement $element): string
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array();
        foreach ( array( '/^blocks-engine-(?!source-|root-child|css-owned|specificity|role-|subject)[a-z-]+/', '/^wp-block-[a-z-]+$/' ) as $pattern ) {
            foreach ( $classes as $class ) {
                if ( 1 === preg_match($pattern, $class) ) {
                    return (string) preg_replace('/-[0-9a-f]{12}(-\d+)?$/', '', $class);
                }
            }
        }

        return '';
    }

    /** @return array<int, DOMElement> */
    private static function children(DOMNode $node): array
    {
        $children = array();
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMElement && ! in_array(strtolower($child->tagName), self::IGNORED_TAGS, true) ) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private static function tag(DOMElement $element): string
    {
        return strtolower($element->tagName);
    }

    private static function identity(DOMElement $element): string
    {
        return self::tag($element) . '#' . $element->getAttribute('id') . '.' . implode('.', self::sourceClasses($element));
    }

    private static function looseIdentity(DOMElement $element): string
    {
        $classes = self::sourceClasses($element);
        $id = $element->getAttribute('id');

        return ( array() !== $classes || '' !== $id ) ? '#' . $id . '.' . implode('.', $classes) : '';
    }

    private static function textIdentity(DOMElement $element): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $element->textContent));

        return '' !== $text ? $text : '';
    }

    /**
     * Longest common subsequence of two element lists under a key; empty keys
     * never match.
     *
     * @param array<int, DOMElement> $left
     * @param array<int, DOMElement> $right
     * @return array<int, array{0: int, 1: int}>
     */
    private static function anchors(array $left, array $right, callable $key): array
    {
        $n = count($left);
        $m = count($right);
        if ( 0 === $n || 0 === $m ) {
            return array();
        }
        $leftKeys = array_map($key, $left);
        $rightKeys = array_map($key, $right);
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ( $i = $n - 1; $i >= 0; $i-- ) {
            for ( $j = $m - 1; $j >= 0; $j-- ) {
                $lengths[$i][$j] = ( '' !== $leftKeys[$i] && $leftKeys[$i] === $rightKeys[$j] )
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $pairs = array();
        $i = 0;
        $j = 0;
        while ( $i < $n && $j < $m ) {
            if ( '' !== $leftKeys[$i] && $leftKeys[$i] === $rightKeys[$j] ) {
                $pairs[] = array( $i, $j );
                ++$i;
                ++$j;
            } elseif ( $lengths[$i + 1][$j] >= $lengths[$i][$j + 1] ) {
                ++$i;
            } else {
                ++$j;
            }
        }

        return $pairs;
    }

    /**
     * @param array<int, DOMElement> $source
     * @param array<int, DOMElement> $rendered
     */
    private function sequence(array $source, array $rendered, string $context, int $depth, int $level = 0): void
    {
        $keys = array(
            array( self::class, 'identity' ),
            array( self::class, 'looseIdentity' ),
            array( self::class, 'textIdentity' ),
        );
        if ( $level >= count($keys) ) {
            $this->unmatched($source, $rendered, $context, $depth);
            return;
        }
        $pairs = self::anchors($source, $rendered, $keys[$level]);
        $pairs[] = array( count($source), count($rendered) );
        $sourceIndex = 0;
        $renderedIndex = 0;
        foreach ( $pairs as [$a, $b] ) {
            $sourceGap = array_slice($source, $sourceIndex, $a - $sourceIndex);
            $renderedGap = array_slice($rendered, $renderedIndex, $b - $renderedIndex);
            if ( array() !== $sourceGap || array() !== $renderedGap ) {
                $this->sequence($sourceGap, $renderedGap, $context, $depth, $level + 1);
            }
            if ( $a < count($source) ) {
                $this->pair($source[$a], $rendered[$b]);
            }
            $sourceIndex = $a + 1;
            $renderedIndex = $b + 1;
        }
    }

    private function pair(DOMElement $source, DOMElement $rendered): void
    {
        // A wrapper around the source element is engine-added, whether it was
        // paired by equal text or took the source element's classes; the
        // element it wraps is the counterpart.
        while ( self::tag($source) !== self::tag($rendered) ) {
            $children = self::children($rendered);
            if ( 1 !== count($children) ) {
                break;
            }
            $sameText = '' === self::looseIdentity($rendered) && self::textIdentity($children[0]) === self::textIdentity($rendered);
            if ( ! $sameText && self::tag($children[0]) !== self::tag($source) ) {
                break;
            }
            $this->record(self::ADDED, self::tag($rendered), self::marker($rendered), self::identity($source));
            $rendered = $children[0];
        }
        ++$this->matched;
        if ( self::tag($source) !== self::tag($rendered) ) {
            $this->record(self::RETAGGED, self::tag($source) . '>' . self::tag($rendered), self::marker($rendered), self::identity($source));
        }
        if ( in_array(self::tag($source), self::LEAF_TAGS, true) ) {
            return;
        }
        $this->sequence(self::children($source), self::children($rendered), self::identity($source), 0);
    }

    /**
     * @param array<int, DOMElement> $source
     * @param array<int, DOMElement> $rendered
     */
    private function unmatched(array $source, array $rendered, string $context, int $depth): void
    {
        if ( $depth >= self::MAX_DEPTH || array() === $rendered ) {
            foreach ( $source as $element ) {
                $this->record(self::REMOVED, self::tag($element), '', $context);
            }
            foreach ( $rendered as $element ) {
                $this->record(self::ADDED, self::tag($element), self::marker($element), $context);
            }
            return;
        }
        $flattened = array();
        foreach ( $rendered as $element ) {
            array_push($flattened, ...self::children($element));
        }
        $sourceChildren = array();
        foreach ( $source as $element ) {
            array_push($sourceChildren, ...self::children($element));
        }
        // A source wrapper the engine dropped: its children align with the
        // rendered elements themselves, not with anything they wrap.
        if ( array() !== $sourceChildren && $this->alignable($sourceChildren, $rendered) && ! $this->alignable($source, $flattened) ) {
            foreach ( $source as $element ) {
                $this->record(self::REMOVED_WRAPPER, self::tag($element), '', $context);
            }
            $this->sequence($sourceChildren, $rendered, $context, $depth + 1);
            return;
        }
        // Rendered elements without a source counterpart are engine-added;
        // align what they wrap in their place.
        foreach ( $rendered as $element ) {
            $this->record(self::ADDED, self::tag($element), self::marker($element), $context);
        }
        $this->sequence($source, $flattened, $context, $depth + 1);
    }

    /**
     * @param array<int, DOMElement> $source
     * @param array<int, DOMElement> $rendered
     */
    private function alignable(array $source, array $rendered): bool
    {
        return array() !== self::anchors($source, $rendered, array( self::class, 'identity' ))
            || array() !== self::anchors($source, $rendered, array( self::class, 'looseIdentity' ));
    }

    private function record(string $kind, string $tag, string $marker, string $context): void
    {
        $this->events[] = array( 'kind' => $kind, 'tag' => $tag, 'marker' => $marker, 'context' => $context );
    }
}

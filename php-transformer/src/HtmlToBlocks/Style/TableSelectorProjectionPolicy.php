<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/** Identifies table selectors whose source structure changes in core/table markup. */
final class TableSelectorProjectionPolicy
{
    /** @param array<string, mixed> $parsed */
    public static function needsStructuralProjection(array $parsed, DOMElement $element): bool
    {
        $classes = array();
        $ids = array();
        $attributes = array();
        foreach ( $parsed['compounds'] ?? array() as $compound ) {
            if ( in_array(strtolower((string) ($compound['type'] ?? '')), array( 'thead', 'tbody', 'tfoot' ), true)
                && ( null !== $compound['nth_child'] || $compound['first_child'] || $compound['last_child'] )
            ) {
                return true;
            }
            foreach ( $compound['classes'] ?? array() as $className ) {
                $classes[$className] = true;
            }
            foreach ( $compound['ids'] ?? array() as $id ) {
                $ids[$id] = true;
            }
            foreach ( $compound['attributes'] ?? array() as $attribute ) {
                if ( is_string($attribute['name'] ?? null) && ! in_array($attribute['name'], array( 'class', 'id' ), true) ) {
                    $attributes[$attribute['name']] = true;
                }
            }
        }

        if ( in_array(strtolower($element->tagName), array( 'td', 'th' ), true)
            && array() === $classes && array() === $ids && array() === $attributes
        ) {
            return true;
        }

        for ( $node = $element; $node instanceof DOMElement && 'table' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $nodeClasses = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: array();
            if ( array_intersect(array_keys($classes), $nodeClasses) ) {
                return true;
            }
            if ( isset($ids[$node->getAttribute('id')]) ) {
                return true;
            }
            foreach ( array_keys($attributes) as $attributeName ) {
                if ( $node->hasAttribute($attributeName) ) {
                    return true;
                }
            }
        }
        return false;
    }
}

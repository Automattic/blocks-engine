<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use DOMElement;

/**
 * Whether a link's tag-wise inline children render as stacked block boxes.
 *
 * A header brand lockup like `<a href><span>Name</span><span>Role</span></a>`
 * keeps every child tag-wise inline, so tag-based classification reads it as a
 * single line of text. When the author stacks the children — a column flex or
 * grid link whose children are blockified, or children displayed block-level —
 * the link owns multiple visual lines and must not be flattened into one run.
 */
final class InlineStackingClassifier
{
    /**
     * Displays that give an inline-tag child its own block box in the parent's
     * flow. Inline-level displays (inline, inline-block, inline-flex, …) keep
     * the child on the parent's text line and never stack.
     */
    private const STACKED_CHILD_DISPLAYS = array( 'block', 'flow-root', 'flex', 'grid', 'list-item', 'table' );

    /**
     * Container displays that blockify every child into a flex or grid item.
     * A row flex container lines its items up horizontally, so only a column
     * main axis stacks them; grid placement is treated as stacked because a
     * single run cannot represent a track layout at all.
     */
    private const STACKING_CONTAINER_DISPLAYS = array( 'flex', 'inline-flex', 'grid', 'inline-grid' );

    /**
     * @param callable(DOMElement): array<string, string> $structuralDeclarations
     *        resolves the authored declarations an element receives from
     *        matched stylesheet rules and its inline style.
     */
    public static function stacksInlineChildren(DOMElement $element, callable $structuralDeclarations): bool
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( in_array(strtolower($child->tagName), array( 'script', 'style', 'template' ), true) ) {
                    continue;
                }
                $children[] = $child;
                continue;
            }
            // Loose text between the children participates in the same inline
            // run; a link holding any is a single line, however its spans sit.
            if ( '' !== trim((string) ($child->textContent ?? '')) ) {
                return false;
            }
        }
        if ( count($children) < 2 ) {
            return false;
        }

        $display = self::declared($element, $structuralDeclarations, 'display');
        if ( in_array($display, array( 'grid', 'inline-grid' ), true) ) {
            return true;
        }
        if ( in_array($display, array( 'flex', 'inline-flex' ), true) ) {
            $direction = self::declared($element, $structuralDeclarations, 'flex-direction');

            return in_array($direction, array( 'column', 'column-reverse' ), true);
        }

        foreach ( $children as $child ) {
            if ( in_array(self::declared($child, $structuralDeclarations, 'display'), self::STACKED_CHILD_DISPLAYS, true) ) {
                return true;
            }
        }

        return false;
    }

    /** @param callable(DOMElement): array<string, string> $structuralDeclarations */
    private static function declared(DOMElement $element, callable $structuralDeclarations, string $property): string
    {
        return strtolower(trim(
            (string) preg_replace('/\s*!important\s*$/i', '', (string) ($structuralDeclarations($element)[$property] ?? ''))
        ));
    }
}

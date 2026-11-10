<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

/**
 * Removes non-rendered head metadata a source leaks into <body> before any
 * element dispatch observes it.
 */
final class NonRenderedMetadata
{
    /**
     * Tags that are always metadata content under the HTML content model: never
     * a rendered box, and — once a document has already been captured and
     * rendered by a real browser, which is what this transformer receives —
     * never a behavior the conversion needs to preserve. `<style>` is
     * deliberately excluded: it has no rendered box either, but its rules apply
     * wherever it sits in the DOM, so it is not metadata noise.
     */
    private const ALWAYS_PRUNED_METADATA_TAGS = array( 'meta', 'base', 'title' );

    /**
     * Remove non-rendered metadata elements a source leaks into `<body>`.
     *
     * `<link>`, `<meta>`, `<base>` and `<title>` are metadata content: the UA
     * stylesheet gives them no rendered box, and they belong in `<head>`.
     * Browsers tolerate them anywhere in `<body>` and site builders occasionally
     * emit them there — a stray RSS `<link rel="alternate">` inside a template
     * partial, a duplicated `<meta>` from a widget include. Left in the tree
     * they carry no visual or textual content of their own, yet their raw tag
     * still matches the generic gates every other converter relies on (a
     * paragraph's rich-text fallback check, a component recognizer's child
     * inspection), so an otherwise convertible parent falls back to a
     * core/html block or the tag leaks verbatim into RichText content. Pruning
     * this element class before any element dispatch begins keeps every
     * downstream pass — style matching, selector projection, rich-text
     * materialization, conversion itself — from ever observing it.
     *
     * A `<link rel="stylesheet">` is excluded: unlike the other rel values,
     * loading a stylesheet is a real behavior, not metadata. So is any `<link>`
     * nested in `<svg><defs>`, where an external stylesheet reference is a
     * recognized, safety-checked authoring pattern (see the `link` branch of
     * SvgMaterializer::isPassiveSvgMarkup).
     */
    public static function prune(DOMElement $body): void
    {
        foreach ( self::ALWAYS_PRUNED_METADATA_TAGS as $tag ) {
            foreach ( iterator_to_array($body->getElementsByTagName($tag)) as $element ) {
                if ( $element instanceof DOMElement ) {
                    // SVG titles name artwork; they are not HTML head metadata.
                    if ('title' === $tag && SourceDom::hasAncestorTag($element, array('svg'))) {
                        continue;
                    }
                    $element->parentNode?->removeChild($element);
                }
            }
        }

        foreach ( iterator_to_array($body->getElementsByTagName('link')) as $link ) {
            if ( $link instanceof DOMElement && self::isNonRenderedMetadataLink($link) ) {
                $link->parentNode?->removeChild($link);
            }
        }
    }

    private static function isNonRenderedMetadataLink(DOMElement $link): bool
    {
        if ( 'stylesheet' === strtolower(trim(SourceDom::attr($link, 'rel'))) ) {
            return false;
        }

        return ! SourceDom::hasAncestorTag($link, array( 'svg', 'defs' ));
    }
}

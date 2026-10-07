<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * The id convention for captured responsive document variants.
 *
 * A capture ships one copy of the page per responsive variant (for example a
 * Wix desktop and mobile document). When both copies of a source id land on
 * the same WordPress page, the non-default copy is renamed with a suffix
 * (`#SITE_FOOTER` / `#SITE_FOOTER--dla-mobile`) so the page keeps unique ids.
 * This class owns that suffix, and keeps the author stylesheets that are
 * delivered to the page pointing at the renamed elements.
 */
final class DocumentVariantIds
{
    public const MOBILE_DOCUMENT_CLASS = 'data-liberation-mobile-document';
    public const DESKTOP_DOCUMENT_CLASS = 'data-liberation-desktop-document';

    /**
     * The id suffix a document variant root class gives its copies of shared
     * ids: `--dla-mobile` for the mobile document, `--dla-<name>` for a named
     * `site-document-variant-<name>`, '' for the default (desktop) variant,
     * and null for a class that does not declare a variant.
     */
    public static function suffixForClass(string $class): ?string
    {
        if ( self::MOBILE_DOCUMENT_CLASS === $class ) {
            return '--dla-mobile';
        }
        if ( self::DESKTOP_DOCUMENT_CLASS === $class || 'site-document-variant-default' === $class ) {
            return '';
        }
        if ( 1 === preg_match('/^site-document-variant-([a-z][a-z0-9_-]{0,31})$/', $class, $match) ) {
            return '--dla-' . $match[1];
        }

        return null;
    }

    /**
     * Point id selectors written for a non-default document variant at that
     * variant's renamed copies as well as the bare id.
     *
     * Capture stylesheets scope variant rules by the variant root
     * (`:where(.data-liberation-mobile-document) #comp-x {…}`). After the
     * variant's shared ids are suffixed, a bare `#comp-x` no longer reaches the
     * mobile copy, so the whole mobile presentation silently drops out. Each
     * `#id` in such a selector becomes `:is(#id,#id--dla-mobile)`: it matches
     * whichever id the element ended up with, and `:is()` takes its most
     * specific argument, so the rule keeps the exact specificity of the source
     * selector and its place in the cascade. An id unique to the variant keeps
     * its bare id and still matches through the first argument.
     */
    public static function scopeIdSelectors(string $stylesheet): string
    {
        if ( ! str_contains($stylesheet, self::MOBILE_DOCUMENT_CLASS) && ! str_contains($stylesheet, 'site-document-variant-') ) {
            return $stylesheet;
        }

        return ( new CssStylesheetTransformer() )->transform(
            $stylesheet,
            static function (string $prelude): string {
                $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
                if ( null === $selectors ) {
                    return $prelude;
                }
                $changed = false;
                foreach ( $selectors as $index => $selector ) {
                    $rewritten = self::scopeIdSelector($selector);
                    if ( $rewritten !== $selector ) {
                        $selectors[$index] = $rewritten;
                        $changed = true;
                    }
                }

                return $changed ? implode(',', $selectors) : $prelude;
            }
        );
    }

    /** Rewrite the id selectors of one complex selector scoped to a non-default variant. */
    public static function scopeIdSelector(string $selector): string
    {
        $suffix = self::selectorVariantSuffix($selector);
        if ( null === $suffix || '' === $suffix ) {
            return $selector;
        }

        $result = '';
        $length = strlen($selector);
        $bracket = 0;
        $quote = '';
        for ( $i = 0; $i < $length; ++$i ) {
            $char = $selector[$i];
            if ( '' !== $quote ) {
                $result .= $char;
                if ( '\\' === $char && $i + 1 < $length ) {
                    $result .= $selector[++$i];
                } elseif ( $char === $quote ) {
                    $quote = '';
                }
                continue;
            }
            if ( '"' === $char || "'" === $char ) {
                $quote = $char;
                $result .= $char;
                continue;
            }
            if ( '[' === $char ) {
                ++$bracket;
            } elseif ( ']' === $char && $bracket > 0 ) {
                --$bracket;
            }
            if ( '#' === $char && 0 === $bracket && 1 === preg_match('/\G#(-?[_a-zA-Z][\w-]*)(?![\\\\\w-])/', $selector, $match, 0, $i) ) {
                $id = $match[1];
                // Engine specificity shims (`:not(#blocks-engine-specificity-id-…)`)
                // name no element; an id already carrying the suffix is final.
                $result .= str_ends_with($id, $suffix) || str_starts_with($id, 'blocks-engine-') ? '#' . $id : ':is(#' . $id . ',#' . $id . $suffix . ')';
                $i += strlen($match[0]) - 1;
                continue;
            }
            $result .= $char;
        }

        return $result;
    }

    /**
     * The suffix of the document variant a selector is scoped to, from the
     * first variant root class it names outside a negation; null when it names
     * none.
     */
    private static function selectorVariantSuffix(string $selector): ?string
    {
        $positive = preg_replace('/:not\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', '', $selector) ?? $selector;
        if ( ! preg_match_all('/\.(-?[_a-zA-Z][\w-]*)/', $positive, $matches) ) {
            return null;
        }
        foreach ( $matches[1] as $class ) {
            $suffix = self::suffixForClass($class);
            if ( null !== $suffix ) {
                return $suffix;
            }
        }

        return null;
    }
}

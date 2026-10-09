<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;

/** Canonical selector transport for a source anchor re-parented by Core Social Links. */
final class SocialAnchorSelectorProjector
{
    public static function project(string $selector, AuthorStylesheetProjectionContext $context): ?string
    {
        if (!$context->selectorProjections->hasSocialAnchors()) return null;
        $tokens = CssSelectorTokenizer::tokenize($selector);
        if (!$tokens['supported']) return null;
        $last = count($tokens['compounds']) - 1;
        $compound = $tokens['compounds'][$last];
        // Match the whole authored selector against the source, not only its
        // subject: `.strip > *` must not claim anchors nested deeper in .strip.
        $parsed = CssSelectorMatcher::parse(self::declaredSelector($selector));
        if (!$parsed['supported']) return null;
        $markers = array();
        $otherSubjects = false;
        foreach ($context->authorStyles->selectorCandidates($parsed) as $element) {
            if (!CssSelectorMatcher::matches($element, $parsed, true, $context->authorStyles->selectorMatchCache())['matches']) continue;
            $marker = $context->selectorProjections->socialAnchorMarker($element->getNodePath() ?? '');
            if ('' !== $marker) $markers[] = $marker;
            else $otherSubjects = true;
        }
        if (array() === $markers) return null;
        $span = $tokens['compound_spans'][$last];
        $prefix = substr($selector, 0, $span['start']);
        // The inserted ul/li are transport, not source ancestors. Keep the
        // authored ancestry and its live conditions, but cross that transport
        // for this known set of source anchors only. No state moves onto li.
        if ('>' === ($tokens['combinators'][$last - 1] ?? '')) $prefix = preg_replace('/>\s*$/', ' ', $prefix) ?? $prefix;
        $guard = ':where(.' . implode(',.', array_unique($markers)) . ')';
        $pseudo = '';
        if (preg_match('/(::?(?:before|after))$/', $compound, $suffix)) {
            $pseudo = $suffix[1];
            $compound = substr($compound, 0, -strlen($pseudo));
        }
        $sourceCompound = $compound;
        $compound = self::sourceClassAttribute($compound);
        // A common native-role scope outranks Core's anchor defaults. Every
        // authored competitor receives the same scope; its own specificity,
        // condition and position in the stylesheet remain authoritative.
        // Core's color reset has four class subjects (container, block/item,
        // anchor). Use the existing collision-free shim for that common role
        // budget, including on base rules, rather than boosting only a state.
        $scope = '.wp-block-social-link-anchor' . str_repeat(':not(.' . $context->authorStyles->classSpecificityShim() . ')', 3);
        $native = $prefix . $compound . $scope . $guard . $pseudo;
        if (!$otherSubjects) return $native;
        // Shared class/type rules keep their ordinary source subjects. Exclude
        // only the attributed anchors from that arm of the same authored rule.
        return substr($selector, 0, $span['start']) . $sourceCompound . ':not(' . $guard . ')' . $pseudo . ',' . $native;
    }

    /** Match the declared selector, while leaving every live state in emitted CSS. */
    private static function declaredSelector(string $selector): string
    {
        $state = CssSyntaxScanner::state();
        $result = '';
        for ($offset = 0, $length = strlen($selector); $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state) && ':' === $selector[$offset]
                && preg_match('/^:(?:hover|focus-visible|focus-within|focus|active|visited)\b/', substr($selector, $offset), $match)) {
                $offset += strlen($match[0]);
                continue;
            }
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if (null === $next) return $selector;
            $result .= substr($selector, $offset, $next - $offset);
            $offset = $next;
        }
        return preg_replace('/::?(?:before|after)$/', '', $result) ?? $result;
    }

    /** Core and semantic classes are transport; class-attribute predicates read the source value. */
    private static function sourceClassAttribute(string $compound): string
    {
        $state = CssSyntaxScanner::state();
        $result = '';
        for ($offset = 0, $length = strlen($compound); $offset < $length;) {
            $attribute = CssSyntaxScanner::isTopLevel($state) && '[' === $compound[$offset];
            $next = CssSyntaxScanner::consume($compound, $offset, $state);
            if (null === $next) return $compound;
            if ($attribute) {
                while (!CssSyntaxScanner::isTopLevel($state) && $next < $length) {
                    $next = CssSyntaxScanner::consume($compound, $next, $state);
                    if (null === $next) return $compound;
                }
            }
            $text = substr($compound, $offset, $next - $offset);
            $result .= $attribute ? preg_replace('/^(\[\s*)class(?=\s*(?:[~|^$*]?=|\]))/i', '$1data-blocks-engine-social-source-class', $text) ?? $text : $text;
            $offset = $next;
        }
        return $result;
    }
}

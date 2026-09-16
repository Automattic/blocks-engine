<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;

/**
 * The single owner of navigation/menu class token heuristics.
 *
 * Five copies of the menu token set had diverged:
 *   - NavigationPattern.php:271 used 'nav|navbar|navigation|menu|links'
 *   - NavigationPattern.php:2300 used 'nav|navbar|navigation|menu' (missing 'links')
 *   - SourceDom.php:498 used 'nav|navbar|navigation|menu|links'
 *   - WordPressCompatCss.php:225 used 'nav|navbar|navigation|menu' (missing 'links')
 *   - HighValueStyleBoundaryPolicy.php:41 used 'nav|menu' folded into a mega-regex
 *
 * This class consolidates them. The canonical set (MENU_TOKENS) includes
 * 'links' because the majority of sites (3 of 5) included it, and because
 * footer-links clusters are a real navigation pattern. Callers that only
 * need the narrower, always-on subset (no 'links') use
 * UNCONDITIONAL_MENU_TOKENS instead - that preserves every site's existing
 * 'links' behavior exactly (conditional at NavigationPattern.php:2300 via
 * containsLinksToken() + isContactLinkCluster(), absent entirely at
 * WordPressCompatCss.php and HighValueStyleBoundaryPolicy.php, which never
 * had a 'links' token to begin with).
 *
 * Reconciliation - where this consolidation changes prior behavior:
 *   - HighValueStyleBoundaryPolicy.php widens from 'nav|menu' to the full
 *     unconditional set 'nav|navbar|navigation|menu' ('navbar'/'navigation'
 *     are newly recognized as high-value style boundaries). Verified against
 *     the full `composer test` suite, including all 311 parity fixtures.
 *   - NavigationPattern.php:271 keeps its original '\s_-'-delimited word
 *     boundary (rather than the '[^a-z0-9]+' split every other site uses)
 *     because collapsing it into the looser tokenization regressed
 *     tests/unit/pattern-registry-staged-dispatch.php's punctuation case
 *     (class="nav.foo" must NOT read as a menu signal there). The token
 *     *list* is still sourced from this class via menuTokenRegexFragment();
 *     only the delimiter set stays call-site-specific.
 *   - The menu-landmark predicate below (isMenuLandmark) recognizes <nav> by
 *     tag in addition to role="navigation". NavigationPattern.php's two call
 *     sites already excluded/guarded the tag='nav' case before reaching
 *     their token check, and SourceDom.php's caller pre-filters to <ul>/<ol>,
 *     so this addition is a no-op at all three consolidated call sites.
 *
 * @see https://github.com/Automattic/blocks-engine/issues/1860
 */
final class MenuVocabulary
{
    /**
     * The canonical menu class/id token set.
     *
     * @var array<int, string>
     */
    public const MENU_TOKENS = array('nav', 'navbar', 'navigation', 'menu', 'links');

    /**
     * Tokens that always indicate menu context, without conditional checks.
     *
     * @var array<int, string>
     */
    public const UNCONDITIONAL_MENU_TOKENS = array('nav', 'navbar', 'navigation', 'menu');

    /**
     * Test whether an attribute value contains any menu token.
     *
     * Word boundaries use the same pattern as the original sites: splits on
     * non-alphanumeric characters.
     */
    public static function containsMenuToken(string $value): bool
    {
        foreach (preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token) {
            if (in_array($token, self::MENU_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test whether an attribute value contains an unconditional menu token.
     *
     * This excludes 'links', which some callers treat conditionally (e.g.
     * excluding contact link clusters).
     */
    public static function containsUnconditionalMenuToken(string $value): bool
    {
        foreach (preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token) {
            if (in_array($token, self::UNCONDITIONAL_MENU_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test whether an attribute value contains the 'links' token.
     */
    public static function containsLinksToken(string $value): bool
    {
        foreach (preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token) {
            if ('links' === $token) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the element is a menu landmark (<nav> or role="navigation").
     */
    public static function isMenuLandmark(DOMElement $element): bool
    {
        if ('nav' === strtolower($element->tagName)) {
            return true;
        }

        $role = $element->hasAttribute('role') ? $element->getAttribute('role') : '';

        return 'navigation' === strtolower($role);
    }

    /**
     * Whether the element is a header landmark (<header> or role="banner").
     *
     * Delegates to ShellLandmarkPolicy for tag/role semantics.
     */
    public static function isHeaderLandmark(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        $role = $element->hasAttribute('role') ? $element->getAttribute('role') : '';

        return 'header' === ShellLandmarkPolicy::landmarkKind($tagName, $role);
    }

    /**
     * Returns the regex fragment for unconditional menu tokens.
     *
     * For use in compound regexes where word boundaries are handled by the
     * caller. Does NOT include 'links' - use menuTokenRegexFragment() for that.
     */
    public static function unconditionalMenuTokenRegexFragment(): string
    {
        return implode('|', self::UNCONDITIONAL_MENU_TOKENS);
    }

    /**
     * Returns the regex fragment for all menu tokens.
     *
     * For use in compound regexes where word boundaries are handled by the
     * caller.
     */
    public static function menuTokenRegexFragment(): string
    {
        return implode('|', self::MENU_TOKENS);
    }
}

<?php
declare(strict_types=1);

/**
 * Contract for a desktop Superfish menu merged with its mobile-only accordion
 * copy into one core/navigation (#6697, #6868).
 *
 * - Core repeats the source list classes on the `<nav>` and on its top-level
 *   `ul.wp-block-navigation__container`, so the authored submenu rule
 *   `.sf-menu ul{position:absolute;top:-99999em}` must not reach that list.
 * - The desktop copy's mobile hide (`#superfish-1{display:none}` at
 *   max-width:780px) swapped it for the absorbed accordion; it must not hide
 *   the merged navigation, which is the only menu at that width.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$links = '<li><a href="/about/">About Us</a><ul><li><a href="/about/chair/">Chair</a></li></ul></li><li><a href="/people/">People</a></li><li><a href="/research/">Research</a></li>';
$html = '<style>.sf-menu,.sf-menu ul{list-style:none;margin:0;padding:0}.sf-menu ul{position:absolute;top:-99999em;left:0;width:12em}'
    . "\n" . '.sf-menu li{float:left;position:relative}.sf-accordion{display:block}.sf-accordion li{float:none;background-color:#ffdd55}.sf-accordion a{padding:8px 20px;color:#222}'
    . "\n" . '@media(min-width:781px){.data-blocks-engine-capture-mobile-only{display:none!important}}'
    . "\n" . '@media(max-width:780px){#superfish-1{display:none!important}}</style>'
    . "\n" . '<div id="nav"><div class="sf-accordion-toggle sf-style-none data-blocks-engine-capture-mobile-only"></div>'
    . "\n" . '<ul id="superfish-1" class="menu sf-menu sf-main-menu sf-horizontal">' . $links . '</ul>'
    . "\n" . '<ul id="superfish-1-accordion" class="menu sf-menu sf-main-menu sf-accordion sf-hidden data-blocks-engine-capture-mobile-only">' . $links . '</ul></div>';

$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(1 === substr_count($markup, '<!-- wp:navigation '), 'the two menus merge into one navigation');
preg_match('/<!-- wp:navigation (.*?) -->/', $markup, $match);
$attrs = json_decode($match[1] ?? '{}', true);
$assert(is_array($attrs) && 'never' === ($attrs['overlayMenu'] ?? null), 'the expanded mobile copy does not become a hamburger');
foreach ( array( 'About Us', 'Chair', 'People', 'Research' ) as $label ) {
    $assert(str_contains($markup, '"label":"' . $label . '"'), 'merged navigation keeps ' . $label);
}

$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( 'css' === ($asset['kind'] ?? '') ) {
        $css .= (string) ($asset['content'] ?? '') . "\n";
    }
}
$assert(str_contains($css, '.sf-menu ul'), 'the authored submenu rule is still emitted');
$assert(str_contains($css, '@media(max-width:780px)') && str_contains($css, 'flex-direction:column!important'), 'the merged menu stacks at the source breakpoint');
$assert(str_contains($css, 'background-color:#ffdd55') && str_contains($css, 'padding:8px 20px'), 'mobile item and link author presentation survives the merge');
$assert(! str_contains($attrs['className'] ?? '', 'blocks-engine-native-responsive-navigation'), 'the expanded menu does not receive overlay host repairs');

$collapsed = str_replace('<div class="sf-accordion-toggle sf-style-none data-blocks-engine-capture-mobile-only"></div>', '<button class="menu-toggle data-blocks-engine-capture-mobile-only" aria-label="Open menu">☰</button>', $html);
$collapsed = str_replace('</style>', '@media(max-width:780px){#superfish-1-accordion{display:none}}</style>', $collapsed);
$collapsedResult = (new HtmlTransformer())->transform($collapsed)->toArray();
$assert(str_contains($collapsedResult['serialized_blocks'], '"overlayMenu":"mobile"'), 'a real toggle with a closed mobile list retains the overlay');

// Core's render of the kept navigation: the anchor and className land on the
// `<nav>` and, through the block wrapper attributes, on the top-level list.
$id = htmlspecialchars((string) ($attrs['anchor'] ?? ''));
$className = htmlspecialchars((string) ($attrs['className'] ?? ''));
$identity = ('' === $id ? '' : ' id="' . $id . '"');
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<html><body><div id="nav" class="wp-block-group">'
    . '<nav' . $identity . ' class="wp-block-navigation is-responsive ' . $className . '">'
    . '<button class="wp-block-navigation__responsive-container-open"></button>'
    . '<div class="wp-block-navigation__responsive-container"><div class="wp-block-navigation__responsive-close">'
    . '<div class="wp-block-navigation__responsive-dialog"><div class="wp-block-navigation__responsive-container-content">'
    . '<ul' . $identity . ' class="wp-block-navigation__container ' . $className . '">'
    . '<li class="wp-block-navigation-item has-child wp-block-navigation-submenu"><a class="wp-block-navigation-item__content" href="/about/">About Us</a>'
    . '<ul class="wp-block-navigation__submenu-container wp-block-navigation-submenu"><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/about/chair/">Chair</a></li></ul></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/people/">People</a></li>'
    . '</ul></div></div></div></div></nav></div></body></html>');
$navigation = $document->getElementsByTagName('nav')->item(0);
$lists = $document->getElementsByTagName('ul');
$container = $lists->item(0);
$submenu = $lists->item(1);
$assert($navigation instanceof DOMElement && $container instanceof DOMElement && $submenu instanceof DOMElement, 'rendered fixture parses');

$submenuOffScreen = false;
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selectorList, string $body, array $conditions) use ($assert, $navigation, $container, $submenu, &$submenuOffScreen): void {
    $conditions = array_map(static fn ($condition): string => str_replace(' ', '', strtolower((string) $condition)), $conditions);
    $declarations = SourceDeclarations::parse($body);
    $hides = 'none' === CssValueInspector::declared($declarations, 'display');
    $position = strtolower(CssValueInspector::withoutImportant(trim((string) CssValueInspector::declared($declarations, 'position'))));
    $top = trim((string) CssValueInspector::declared($declarations, 'top'));
    $movesOffScreen = 'absolute' === $position || str_contains($top, '-99999');
    $mobile = in_array('@media(max-width:780px)', $conditions, true);
    if ( ! ($hides && $mobile) && ! $movesOffScreen ) {
        return;
    }
    foreach ( CssStylesheetTransformer::splitSelectorList($selectorList) ?? array() as $selector ) {
        $parsed = CssSelectorMatcher::parse($selector);
        $relevant = str_contains($selector, 'superfish-1') || str_contains($selector, 'sf-menu');
        $assert(! $relevant || ($parsed['supported'] ?? false), 'selector is checkable: ' . $selector);
        if ( $hides && $mobile ) {
            foreach ( array( 'navigation' => $navigation, 'top-level list' => $container ) as $role => $element ) {
                $match = CssSelectorMatcher::matches($element, $parsed, true);
                $assert(! ($match['matches'] ?? false), 'no mobile display:none targets the merged ' . $role . ': ' . $selector);
            }
        }
        if ( $movesOffScreen ) {
            $match = CssSelectorMatcher::matches($container, $parsed, true);
            $assert(! ($match['matches'] ?? false), 'the submenu rule does not move the top-level list: ' . $selector);
            if ( str_contains($top, '-99999') && (CssSelectorMatcher::matches($submenu, $parsed, true)['matches'] ?? false) ) {
                $submenuOffScreen = true;
            }
        }
    }
});
$assert($submenuOffScreen, 'the authored rule still parks the closed submenu off-screen');

echo "Merged accordion navigation visibility contract passed\n";

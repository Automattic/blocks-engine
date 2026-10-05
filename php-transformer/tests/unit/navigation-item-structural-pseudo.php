<?php
declare(strict_types=1);

/**
 * A structural pseudo-class authored on a direct navigation anchor keeps its
 * meaning after core/navigation re-parents that anchor into its own list item.
 *
 * `nav a:last-child` selects the last link of a source menu whose anchors are
 * direct children of the `<nav>`. core/navigation-link renders every such
 * anchor as `<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content">`,
 * so each rendered anchor is the only child of its item and the authored
 * selector matches every link. The projected rule has to move the structural
 * pseudo-class onto the item (`:where(.wp-block-navigation-item):last-child > a`)
 * so that only the last link keeps the authored box.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
/** The projected author stylesheet alone; engine support CSS is not under test. */
$css = static function (array $result): string {
    $parts = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') && ! str_contains((string) ($asset['path'] ?? ''), 'engine-support') ) {
            $parts[] = (string) ($asset['content'] ?? '');
        }
    }
    return implode("\n", $parts);
};
/** @return list<array{selector: string, body: string, conditions: list<string>}> */
$rules = static function (string $stylesheet): array {
    $rules = array();
    ( new CssStylesheetTransformer() )->visitStyleRules($stylesheet, static function (string $selector, string $body, array $conditions) use (&$rules): void {
        foreach ( CssStylesheetTransformer::splitSelectorList($selector) ?? array( $selector ) as $single ) {
            $rules[] = array( 'selector' => trim($single), 'body' => trim($body), 'conditions' => $conditions );
        }
    });
    return $rules;
};
/** @return list<string> */
$selectorsDeclaring = static function (array $rules, string $declaration, array $conditions = array()): array {
    $selectors = array();
    foreach ( $rules as $rule ) {
        if ( str_contains($rule['body'], $declaration) && $conditions === $rule['conditions'] ) {
            $selectors[] = $rule['selector'];
        }
    }
    return $selectors;
};

// --- Direct anchors: the menu's links are direct children of the <nav>. -------

$direct = $transform(
    '<style>'
    . '.menu nav a{font-size:18px;text-decoration:none;color:#333}'
    . '.menu nav a:hover{color:#000}'
    . '.menu nav a:first-child{font-weight:700}'
    . '.menu nav a:not(:last-child){margin-right:12px}'
    . '.menu nav a:last-child{padding:7px 11px;border:1px solid #999;border-radius:999px}'
    . '.menu nav a:nth-child(2){letter-spacing:.1em}'
    . '@media (max-width:720px){.menu nav a:last-child{padding:4px 8px}}'
    . '</style>'
    . '<header><div class="menu"><a class="brand" href="/"><b>Brand</b></a><button>Menu</button>'
    . '<nav><a href="#alpha">Alpha</a><a href="#beta">Beta</a><a href="#gamma">Gamma</a><a href="other.html">Other</a></nav>'
    . '</div></header>'
);
$directCss = $css($direct);
$directRules = $rules($directCss);
$directMarkup = (string) ($direct['serialized_blocks'] ?? '');
preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $directMarkup, $navigationOpener);
preg_match('/blocks-engine-source-nav-[A-Za-z0-9-]+/', $navigationOpener[1] ?? '', $markerMatch);
$navMarker = $markerMatch[0] ?? '';
$assert('' !== $navMarker && 4 === substr_count($directMarkup, '<!-- wp:navigation-link '), 'the direct-anchor menu becomes a core/navigation with one navigation-link per source anchor');

$scope = '.menu :where(.' . $navMarker . '):not(blocks-engine-specificity-site-0) ';
$item = ':where(.wp-block-navigation-item)';
// The rendered anchor keeps the authored type specificity through the same
// shim every projected type selector receives.
$anchor = '>:where(.wp-block-navigation-item__content):not(blocks-engine-specificity-site-0)';

$borderSelectors = $selectorsDeclaring($directRules, 'border-radius:999px');
$assert(
    array( $scope . $item . ':last-child' . $anchor ) === $borderSelectors,
    'a:last-child moves onto the rendered navigation item: ' . json_encode($borderSelectors)
);
$assert(
    array( $scope . $item . ':first-child' . $anchor ) === $selectorsDeclaring($directRules, 'font-weight:700'),
    'a:first-child moves onto the rendered navigation item'
);
// Margin declarations carry the projector's own trailing priority shim.
$gapSelectors = $selectorsDeclaring($directRules, 'margin-right:12px');
$assert(
    1 === count($gapSelectors) && str_starts_with($gapSelectors[0], $scope . $item . ':not(:last-child)' . $anchor),
    'a:not(:last-child) moves onto the rendered navigation item: ' . json_encode($gapSelectors)
);
$assert(
    array( $scope . $item . ':nth-child(2)' . $anchor ) === $selectorsDeclaring($directRules, 'letter-spacing:.1em'),
    'a:nth-child(n) moves onto the rendered navigation item'
);
$assert(
    array( $scope . $item . ':last-child' . $anchor ) === $selectorsDeclaring($directRules, 'padding:4px 8px', array( '@media (max-width:720px)' )),
    'a conditional copy of the structural rule is rewritten inside its media query'
);
$assert(
    array( $scope . 'a:hover' ) === $selectorsDeclaring($directRules, 'color:#000'),
    'a dynamic-state anchor rule without a structural pseudo-class is left alone'
);
$assert(
    array( $scope . 'a' ) === $selectorsDeclaring($directRules, 'text-decoration:none'),
    'the plain anchor rule is left alone'
);
$assert(
    ! preg_match('/a:(?:first-child|last-child|nth-child\([^)]*\)|not\(:last-child\))\s*\{/', $directCss),
    'no projected rule keeps a structural pseudo-class on the anchor itself'
);

// Evaluate the projected rules against core/navigation's rendered markup: only
// the last rendered anchor may receive the authored pill box.
$rendered = new DOMDocument();
libxml_use_internal_errors(true);
$rendered->loadHTML(
    '<!DOCTYPE html><html><body><header class="wp-block-group"><div class="wp-block-group menu">'
    . '<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="/"><b>Brand</b></a></div></div>'
    . '<nav class="wp-block-navigation ' . $navMarker . ' is-layout-flex"><ul class="wp-block-navigation__container">'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#alpha"><span class="wp-block-navigation-item__label">Alpha</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#beta"><span class="wp-block-navigation-item__label">Beta</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#gamma"><span class="wp-block-navigation-item__label">Gamma</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="other.html"><span class="wp-block-navigation-item__label">Other</span></a></li>'
    . '</ul></nav></div></header></body></html>'
);
libxml_clear_errors();
libxml_use_internal_errors(false);
$renderedAnchors = array();
foreach ( $rendered->getElementsByTagName('a') as $renderedAnchor ) {
    if ( $renderedAnchor instanceof DOMElement && str_contains($renderedAnchor->getAttribute('class'), 'wp-block-navigation-item__content') ) {
        $renderedAnchors[] = $renderedAnchor;
    }
}
/** @return list<string> hrefs of rendered anchors the declaration reaches */
$reached = static function (array $rules, string $declaration) use ($renderedAnchors): array {
    $hrefs = array();
    foreach ( $rules as $rule ) {
        if ( ! str_contains($rule['body'], $declaration) || array() !== $rule['conditions'] ) {
            continue;
        }
        $parsed = CssSelectorMatcher::parse($rule['selector']);
        foreach ( $renderedAnchors as $anchor ) {
            $match = CssSelectorMatcher::matches($anchor, $parsed, true);
            if ( $match['supported'] && $match['matches'] ) {
                $hrefs[$anchor->getAttribute('href')] = true;
            }
        }
    }
    return array_keys($hrefs);
};
$assert(4 === count($renderedAnchors), 'the rendered fixture carries four navigation anchors');
$assert(array( 'other.html' ) === $reached($directRules, 'border-radius:999px'), 'only the last rendered link receives the authored pill border');
$assert(array( '#alpha' ) === $reached($directRules, 'font-weight:700'), 'only the first rendered link receives the authored weight');
$assert(array( '#alpha', '#beta', '#gamma' ) === $reached($directRules, 'margin-right:12px'), 'every rendered link but the last keeps the authored gap');
$assert(array( '#beta' ) === $reached($directRules, 'letter-spacing:.1em'), 'only the second rendered link receives the authored tracking');
$assert(array( '#alpha', '#beta', '#gamma', 'other.html' ) === $reached($directRules, 'text-decoration:none'), 'the plain anchor rule still reaches every rendered link');

// --- List-backed menu: the source <li> already is the rendered item. ----------

$list = $transform(
    '<style>'
    . '.menu nav li:last-child a{border:1px solid #999}'
    . '.menu nav li a:last-child{text-decoration:none}'
    . '.menu nav li:not(:last-child){margin-right:12px}'
    . '</style>'
    . '<header><div class="menu"><nav><ul>'
    . '<li><a href="#alpha">Alpha</a></li><li><a href="#beta">Beta</a></li><li><a href="other.html">Other</a></li>'
    . '</ul></nav></div></header>'
);
$listCss = $css($list);
$listRules = $rules($listCss);
$assert(str_contains((string) ($list['serialized_blocks'] ?? ''), '<!-- wp:navigation '), 'the list-backed menu becomes a core/navigation');
$listBorder = $selectorsDeclaring($listRules, 'border:1px solid #999');
$assert(
    1 === count($listBorder) && str_ends_with($listBorder[0], ':last-child a') && ! str_contains($listBorder[0], '.wp-block-navigation-item'),
    'li:last-child a keeps its structural pseudo-class on the source list item: ' . json_encode($listBorder)
);
$listAnchor = $selectorsDeclaring($listRules, 'text-decoration:none');
$assert(
    1 === count($listAnchor) && str_ends_with($listAnchor[0], ' a:last-child') && ! str_contains($listAnchor[0], '.wp-block-navigation-item'),
    'li a:last-child is not rewritten when the anchor already sits in a source list item: ' . json_encode($listAnchor)
);
$listGap = $selectorsDeclaring($listRules, 'margin-right:12px');
$assert(
    1 === count($listGap) && str_contains($listGap[0], ':not(:last-child)') && ! str_contains($listGap[0], '.wp-block-navigation-item'),
    'li:not(:last-child) is not rewritten: ' . json_encode($listGap)
);

// --- Abandoned menu: the pattern builds a link, then gives the container up. ---
// The first anchor was recorded while its link block was built, but a separator
// child makes the pattern bail, so the anchors render as plain links. The
// structural rule must stay on the anchor: the item it would move to never renders.

$abandoned = $transform(
    '<style>.menu nav a:first-child{border:1px solid #999}</style>'
    . '<div class="menu"><nav><a href="#alpha">Alpha</a><span>|</span><a href="#beta">Beta</a></nav></div>'
);
$abandonedRules = $rules($css($abandoned));
$abandonedBorder = $selectorsDeclaring($abandonedRules, 'border:1px solid #999');
$assert(! str_contains((string) ($abandoned['serialized_blocks'] ?? ''), 'wp:navigation'), 'the separator menu is not emitted as core/navigation');
$assert(
    1 === count($abandonedBorder) && str_ends_with($abandonedBorder[0], ' a:first-child') && ! str_contains($abandonedBorder[0], '.wp-block-navigation-item'),
    'an anchor whose navigation-link block never reached the block tree keeps the authored selector: ' . json_encode($abandonedBorder)
);

// --- Mixed match set: the rule also reaches anchors outside the menu. ---------

$mixed = $transform(
    '<style>.site a:last-child{border:1px solid #999}</style>'
    . '<div class="site"><nav><a href="#alpha">Alpha</a><a href="#beta">Beta</a></nav><footer><a href="#one">One</a><a href="#two">Two</a></footer></div>'
);
$mixedRules = $rules($css($mixed));
$mixedBorder = $selectorsDeclaring($mixedRules, 'border:1px solid #999');
$assert(
    in_array('.site :where(.wp-block-navigation-item):last-child' . $anchor, $mixedBorder, true),
    'a rule reaching both menu and non-menu anchors still projects the item-positioned selector: ' . json_encode($mixedBorder)
);
$assert(
    in_array('.site a:last-child', $mixedBorder, true),
    'a rule reaching both menu and non-menu anchors keeps the authored selector for the non-menu anchors: ' . json_encode($mixedBorder)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "navigation-item-structural-pseudo: {$failures} failure(s), {$passes} pass(es)" . PHP_EOL);
    exit(1);
}
echo "navigation-item-structural-pseudo: {$passes} assertions passed" . PHP_EOL;

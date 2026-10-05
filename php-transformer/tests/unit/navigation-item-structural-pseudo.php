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
/**
 * @param list<DOMElement> $anchors
 * @return list<string> hrefs of the given rendered anchors the declaration reaches
 */
$reachedAnchors = static function (array $anchors, array $rules, string $declaration): array {
    $hrefs = array();
    foreach ( $rules as $rule ) {
        if ( ! str_contains($rule['body'], $declaration) || array() !== $rule['conditions'] ) {
            continue;
        }
        $parsed = CssSelectorMatcher::parse($rule['selector']);
        if ( ! $parsed['supported'] ) {
            // An unparseable projected selector must surface, not silently match nothing.
            $hrefs['UNSUPPORTED:' . $rule['selector']] = true;
            continue;
        }
        foreach ( $anchors as $anchor ) {
            $match = CssSelectorMatcher::matches($anchor, $parsed, true);
            if ( $match['supported'] && $match['matches'] ) {
                $hrefs[$anchor->getAttribute('href')] = true;
            }
        }
    }
    return array_keys($hrefs);
};
$reached = static fn (array $rules, string $declaration): array => $reachedAnchors($renderedAnchors, $rules, $declaration);
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
    '<style>'
    . '.site a:last-child{border:1px solid #999}'
    . '.site a:last-child:hover{color:#000}'
    . '@media (max-width:720px){.site a:last-child{padding:4px 8px}}'
    . '</style>'
    . '<div class="site"><nav><a href="#alpha">Alpha</a><a href="#beta">Beta</a></nav><footer><a href="#one">One</a><a href="#two">Two</a></footer></div>'
);
$mixedRules = $rules($css($mixed));
$mixedBorder = $selectorsDeclaring($mixedRules, 'border:1px solid #999');
$assert(
    in_array('.site :where(.wp-block-navigation-item):last-child' . $anchor, $mixedBorder, true),
    'a rule reaching both menu and non-menu anchors still projects the item-positioned selector: ' . json_encode($mixedBorder)
);
// The authored selector stays for the non-menu anchors, but every rendered
// menu anchor is the only child of its item, so it has to stop reaching them.
// The exclusion is zero-specificity and sits before any dynamic state.
$mixedExclusion = ':not(:where(.wp-block-navigation-item__content))';
$assert(
    in_array('.site a:last-child' . $mixedExclusion, $mixedBorder, true),
    'the kept authored selector excludes rendered menu anchors without changing specificity: ' . json_encode($mixedBorder)
);
$assert(
    in_array('.site a:last-child' . $mixedExclusion . ':hover', $selectorsDeclaring($mixedRules, 'color:#000'), true),
    'the exclusion sits before the authored dynamic state'
);
$assert(
    in_array('.site a:last-child' . $mixedExclusion, $selectorsDeclaring($mixedRules, 'padding:4px 8px', array( '@media (max-width:720px)' )), true),
    'the exclusion is applied inside a conditional copy of the rule'
);
$assert(
    ! preg_match('/\.site a:last-child\s*[,{]/', $css($mixed)),
    'no copy of the authored selector is left reaching every rendered menu anchor'
);

// Evaluate against markup carrying both the rendered menu and the plain footer.
$mixedRendered = new DOMDocument();
libxml_use_internal_errors(true);
$mixedRendered->loadHTML(
    '<!DOCTYPE html><html><body><div class="wp-block-group site">'
    . '<nav class="wp-block-navigation is-layout-flex"><ul class="wp-block-navigation__container">'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#alpha"><span class="wp-block-navigation-item__label">Alpha</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#beta"><span class="wp-block-navigation-item__label">Beta</span></a></li>'
    . '</ul></nav>'
    . '<footer class="wp-block-group"><p><a href="#one">One</a></p><p><a href="#one-b">One B</a><a href="#two">Two</a></p></footer>'
    . '</div></body></html>'
);
libxml_clear_errors();
libxml_use_internal_errors(false);
$mixedAnchors = array();
foreach ( $mixedRendered->getElementsByTagName('a') as $mixedAnchor ) {
    if ( $mixedAnchor instanceof DOMElement ) {
        $mixedAnchors[] = $mixedAnchor;
    }
}
$assert(5 === count($mixedAnchors), 'the mixed rendered fixture carries two menu anchors and three footer anchors');
$mixedReached = $reachedAnchors($mixedAnchors, $mixedRules, 'border:1px solid #999');
sort($mixedReached);
$assert(
    array( '#beta', '#one', '#two' ) === $mixedReached,
    'only the last menu link and the footer anchors that are last children receive the border: ' . json_encode($mixedReached)
);

// --- Item-owned hooks: core renders the anchor's class and id on the <li>. ----

$hooks = $transform(
    '<style>'
    . '.menu nav a{color:#333}'
    . '.menu nav a.lang:last-child{border:1px solid #999}'
    . '.menu nav a.lang:last-child:hover{color:#000}'
    . '.menu nav a#other:last-child{letter-spacing:.1em}'
    . '.menu nav a:not(.lang):not(:last-child){margin-right:12px}'
    . '</style>'
    . '<header><div class="menu"><nav><a href="#alpha">Alpha</a><a href="#beta">Beta</a><a href="#gamma" id="other" class="lang">Gamma</a></nav></div></header>'
);
$hooksMarkup = (string) ($hooks['serialized_blocks'] ?? '');
$gammaAttrs = array();
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $hooksMarkup, $hookLinks);
foreach ( $hookLinks[1] as $json ) {
    $attrs = json_decode($json, true);
    if ( is_array($attrs) && 'Gamma' === ($attrs['label'] ?? '') ) {
        $gammaAttrs = $attrs;
    }
}
$assert(
    in_array('lang', preg_split('/\s+/', (string) ($gammaAttrs['className'] ?? '')) ?: array(), true) && 'other' === ($gammaAttrs['anchor'] ?? null),
    'the source anchor class and id travel on the navigation-link block, which core renders on the <li>'
);
preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $hooksMarkup, $hooksOpener);
preg_match('/blocks-engine-source-nav-[A-Za-z0-9-]+/', $hooksOpener[1] ?? '', $hooksMarkerMatch);
$hooksScope = '.menu :where(.' . ($hooksMarkerMatch[0] ?? '') . '):not(blocks-engine-specificity-site-0) ';
$hooksRules = $rules($css($hooks));
$assert(
    array( $hooksScope . $item . '.lang:last-child' . $anchor ) === $selectorsDeclaring($hooksRules, 'border:1px solid #999'),
    'an anchor class moves onto the item compound with the structural pseudo-class: ' . json_encode($selectorsDeclaring($hooksRules, 'border:1px solid #999'))
);
$assert(
    array( $hooksScope . $item . '.lang:last-child' . $anchor . ':hover' ) === $selectorsDeclaring($hooksRules, 'color:#000'),
    'the dynamic state stays on the content anchor while the class moves to the item'
);
$assert(
    array( $hooksScope . $item . '#other:last-child' . $anchor ) === $selectorsDeclaring($hooksRules, 'letter-spacing:.1em'),
    'an anchor id moves onto the item compound: ' . json_encode($selectorsDeclaring($hooksRules, 'letter-spacing:.1em'))
);
$hooksGap = $selectorsDeclaring($hooksRules, 'margin-right:12px');
$assert(
    1 === count($hooksGap) && str_starts_with($hooksGap[0], $hooksScope . $item . ':not(.lang):not(:last-child)' . $anchor),
    'a class negation moves onto the item compound next to the structural negation: ' . json_encode($hooksGap)
);
$hooksRendered = new DOMDocument();
libxml_use_internal_errors(true);
$hooksRendered->loadHTML(
    '<!DOCTYPE html><html><body><header class="wp-block-group"><div class="wp-block-group menu">'
    . '<nav class="wp-block-navigation ' . ($hooksMarkerMatch[0] ?? '') . ' is-layout-flex"><ul class="wp-block-navigation__container">'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#alpha"><span class="wp-block-navigation-item__label">Alpha</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#beta"><span class="wp-block-navigation-item__label">Beta</span></a></li>'
    . '<li class="wp-block-navigation-item lang wp-block-navigation-link" id="other"><a class="wp-block-navigation-item__content" href="#gamma"><span class="wp-block-navigation-item__label">Gamma</span></a></li>'
    . '</ul></nav></div></header></body></html>'
);
libxml_clear_errors();
libxml_use_internal_errors(false);
$hooksAnchors = array();
foreach ( $hooksRendered->getElementsByTagName('a') as $hooksAnchor ) {
    if ( $hooksAnchor instanceof DOMElement ) {
        $hooksAnchors[] = $hooksAnchor;
    }
}
$assert(array( '#gamma' ) === $reachedAnchors($hooksAnchors, $hooksRules, 'border:1px solid #999'), 'only the rendered link whose item carries the moved class receives the border');
$assert(array( '#gamma' ) === $reachedAnchors($hooksAnchors, $hooksRules, 'letter-spacing:.1em'), 'only the rendered link whose item carries the moved id receives the tracking');
$assert(array( '#alpha', '#beta' ) === $reachedAnchors($hooksAnchors, $hooksRules, 'margin-right:12px'), 'the class negation keeps reaching the other rendered links');

// --- Positions that are not one-to-one with the rendered items. -------------
// A heading inside the menu, or a direct anchor before a wrapper of further
// anchors, shifts source sibling positions away from item positions. The
// structural pseudo-class then has to stay as authored rather than point at
// the wrong item.

$positional = array(
    'labeled section' => array(
        '.menu a:nth-child(2){border:1px solid #999}',
        '<div class="menu"><h3>Sections</h3><a href="#a">A</a><a href="#b">B</a><a href="#c">C</a></div>',
        ' a:nth-child(2)',
    ),
    'wrapper after a direct anchor' => array(
        '.links a:first-child{border:1px solid #999}',
        '<header><nav><a href="#home">Home</a><div class="links"><a href="#a">A</a><a href="#b">B</a></div></nav></header>',
        ' a:first-child',
    ),
);
foreach ( $positional as $name => [$positionalRule, $positionalMarkup, $suffix] ) {
    $positionalResult = $transform('<style>' . $positionalRule . '</style>' . $positionalMarkup);
    $positionalSelectors = $selectorsDeclaring($rules($css($positionalResult)), 'border:1px solid #999');
    $assert(str_contains((string) ($positionalResult['serialized_blocks'] ?? ''), '<!-- wp:navigation '), $name . ': the fixture becomes a core/navigation');
    $assert(
        1 === count($positionalSelectors) && str_ends_with($positionalSelectors[0], $suffix) && ! str_contains($positionalSelectors[0], '.wp-block-navigation-item'),
        $name . ': source positions do not map one-to-one onto items, so the structural pseudo-class stays as authored: ' . json_encode($positionalSelectors)
    );
}

// A wrapper that is the menu's only source of items maps one-to-one.
$soleWrapper = $transform('<style>nav a:last-child{border:1px solid #999}</style><header><nav><div class="links"><a href="#a">A</a><a href="#b">B</a><a href="#c">C</a></div></nav></header>');
$soleWrapperBorder = $selectorsDeclaring($rules($css($soleWrapper)), 'border:1px solid #999');
$assert(
    1 === count($soleWrapperBorder) && str_ends_with($soleWrapperBorder[0], ' ' . $item . ':last-child' . $anchor),
    'anchors of a wrapper that is the only source of items are still rewritten: ' . json_encode($soleWrapperBorder)
);

// --- Every simple-selector kind a compound can carry. -------------------------
// Bare and universal subjects, attribute selectors (class/id render on the
// <li>, href on the <a>, others are not retained), escaped identifiers, and a
// child combinator before the subject (core puts a <ul> between the nav and
// its items, so a one-to-one menu relaxes it to a descendant).

$kinds = $transform(
    '<style>'
    . '.menu nav a{color:#333}'
    . '.menu nav > :last-child{border:1px solid #999}'
    . '.menu nav *:last-child{padding:4px 8px}'
    . '.menu nav a[class~="lang"]:last-child{letter-spacing:.1em}'
    . '.menu nav a[id="first"]:first-child{font-weight:700}'
    . '.menu nav a[href^="#g"]:last-child{text-transform:uppercase}'
    . '.menu nav a[rel]:last-child{color:#000}'
    . '.menu nav .foo\\26 bar:last-child{margin-right:12px}'
    . '.menu nav #\\31 23:last-child{opacity:.5}'
    . '.menu nav > a:last-child{outline:1px solid red}'
    . '.menu nav a:not(:hover):last-child{text-decoration:none}'
    . '</style>'
    . '<header><div class="menu"><nav>'
    . '<a id="first" href="#alpha">Alpha</a><a href="#beta">Beta</a><a class="lang foo&amp;bar" id="123" rel="nofollow" href="#gamma">Gamma</a>'
    . '</nav></div></header>'
);
$kindsMarkup = (string) ($kinds['serialized_blocks'] ?? '');
$kindsLinks = array();
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $kindsMarkup, $kindLinkMatches);
foreach ( $kindLinkMatches[1] as $json ) {
    $attrs = json_decode($json, true);
    if ( is_array($attrs) ) {
        $kindsLinks[(string) ($attrs['label'] ?? '')] = $attrs;
    }
}
$gammaClasses = preg_split('/\s+/', (string) ($kindsLinks['Gamma']['className'] ?? '')) ?: array();
$assert(
    'first' === ($kindsLinks['Alpha']['anchor'] ?? null) && in_array('lang', $gammaClasses, true) && in_array('foo&bar', $gammaClasses, true),
    'the fixture carries the id and classes core renders on the <li>: ' . json_encode(array( $kindsLinks['Alpha']['anchor'] ?? null, $gammaClasses ))
);
preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $kindsMarkup, $kindsOpener);
preg_match('/blocks-engine-source-nav-[A-Za-z0-9-]+/', $kindsOpener[1] ?? '', $kindsMarkerMatch);
$kindsMarker = $kindsMarkerMatch[0] ?? '';
$kindsScope = '.menu :where(.' . $kindsMarker . '):not(blocks-engine-specificity-site-0)';
$kindsRules = $rules($css($kinds));
$content = '>:where(.wp-block-navigation-item__content)';
$bareBorder = $selectorsDeclaring($kindsRules, 'border:1px solid #999');
$assert(
    in_array($kindsScope . ' ' . $item . ':last-child' . $content, $bareBorder, true)
        && in_array($kindsScope . ' > :last-child' . $mixedExclusion, $bareBorder, true),
    'a bare structural subject after a child combinator is projected onto the item and kept for other elements without the rendered anchors: ' . json_encode($bareBorder)
);
$universalPadding = $selectorsDeclaring($kindsRules, 'padding:4px 8px');
$assert(
    in_array($kindsScope . ' ' . $item . ':last-child' . $content, $universalPadding, true)
        && in_array($kindsScope . ' *:last-child' . $mixedExclusion, $universalPadding, true),
    'a universal structural subject is projected onto the item and kept for other elements without the rendered anchors: ' . json_encode($universalPadding)
);
$assert(
    array( $kindsScope . ' ' . $item . '[class~="lang"]:last-child' . $anchor ) === $selectorsDeclaring($kindsRules, 'letter-spacing:.1em'),
    'a class attribute selector moves onto the item compound: ' . json_encode($selectorsDeclaring($kindsRules, 'letter-spacing:.1em'))
);
$assert(
    in_array($kindsScope . ' ' . $item . '[id="first"]:first-child' . $anchor, $selectorsDeclaring($kindsRules, 'font-weight:700'), true),
    'an id attribute selector moves onto the item compound: ' . json_encode($selectorsDeclaring($kindsRules, 'font-weight:700'))
);
$assert(
    array( $kindsScope . ' ' . $item . ':last-child' . $anchor . '[href^="#g"]' ) === $selectorsDeclaring($kindsRules, 'text-transform:uppercase'),
    'an href attribute selector stays on the content anchor: ' . json_encode($selectorsDeclaring($kindsRules, 'text-transform:uppercase'))
);
$assert(
    array( $kindsScope . ' a[rel]:last-child' ) === $selectorsDeclaring($kindsRules, 'color:#000'),
    'an attribute core does not render on the anchor declines the projection and keeps the authored selector: ' . json_encode($selectorsDeclaring($kindsRules, 'color:#000'))
);
$escapedClass = $selectorsDeclaring($kindsRules, 'margin-right:12px');
$assert(
    1 === count($escapedClass) && str_starts_with($escapedClass[0], $kindsScope . ' ' . $item . '.foo\\26 bar:last-child' . $content),
    'a hex-escaped class with its whitespace terminator moves whole onto the item compound: ' . json_encode($escapedClass)
);
$assert(
    array( $kindsScope . ' ' . $item . '#\\31 23:last-child' . $content ) === $selectorsDeclaring($kindsRules, 'opacity:.5'),
    'a hex-escaped id moves whole onto the item compound: ' . json_encode($selectorsDeclaring($kindsRules, 'opacity:.5'))
);
$assert(
    array( $kindsScope . ' ' . $item . ':last-child' . $anchor ) === $selectorsDeclaring($kindsRules, 'outline:1px solid red'),
    'a child combinator before the anchor relaxes to a descendant, since core renders a <ul> between the nav and its items: ' . json_encode($selectorsDeclaring($kindsRules, 'outline:1px solid red'))
);
$assert(
    array( $kindsScope . ' ' . $item . ':last-child' . $anchor . ':not(:hover)' ) === $selectorsDeclaring($kindsRules, 'text-decoration:none'),
    'a resting-state negation stays on the content anchor: ' . json_encode($selectorsDeclaring($kindsRules, 'text-decoration:none'))
);
$assert(
    ! preg_match('/:where\(\.wp-block-navigation-item\)[^>{]*>[^,{]* (?:bar|23)[\s,{]/', $css($kinds)),
    'no escaped identifier is split across the item and anchor compounds'
);

// Rendered as core does: ids and classes on the <li>, href on the <a>.
$kindsRendered = new DOMDocument();
libxml_use_internal_errors(true);
$kindsRendered->loadHTML(
    '<!DOCTYPE html><html><body><header class="wp-block-group"><div class="wp-block-group menu">'
    . '<nav class="wp-block-navigation ' . $kindsMarker . ' is-layout-flex"><ul class="wp-block-navigation__container">'
    . '<li class="wp-block-navigation-item wp-block-navigation-link" id="first"><a class="wp-block-navigation-item__content" href="#alpha"><span class="wp-block-navigation-item__label">Alpha</span></a></li>'
    . '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#beta"><span class="wp-block-navigation-item__label">Beta</span></a></li>'
    . '<li class="wp-block-navigation-item lang foo&amp;bar wp-block-navigation-link" id="123"><a class="wp-block-navigation-item__content" href="#gamma"><span class="wp-block-navigation-item__label">Gamma</span></a></li>'
    . '</ul></nav></div></header></body></html>'
);
libxml_clear_errors();
libxml_use_internal_errors(false);
$kindsAnchors = array();
foreach ( $kindsRendered->getElementsByTagName('a') as $kindsAnchor ) {
    if ( $kindsAnchor instanceof DOMElement ) {
        $kindsAnchors[] = $kindsAnchor;
    }
}
$assert(array( '#gamma' ) === $reachedAnchors($kindsAnchors, $kindsRules, 'border:1px solid #999'), 'the bare subject rule reaches only the last rendered link');
$assert(array( '#gamma' ) === $reachedAnchors($kindsAnchors, $kindsRules, 'padding:4px 8px'), 'the universal subject rule reaches only the last rendered link');
$assert(array( '#gamma' ) === $reachedAnchors($kindsAnchors, $kindsRules, 'letter-spacing:.1em'), 'the class attribute rule reaches only the rendered link whose item carries the class');
$assert(array( '#alpha' ) === $reachedAnchors($kindsAnchors, $kindsRules, 'font-weight:700'), 'the id attribute rule reaches only the rendered link whose item carries the id');
$assert(array( '#gamma' ) === $reachedAnchors($kindsAnchors, $kindsRules, 'text-transform:uppercase'), 'the href attribute rule reaches only the last rendered link');
$assert(array( '#gamma' ) === $reachedAnchors($kindsAnchors, $kindsRules, 'margin-right:12px'), 'the escaped class rule reaches only the rendered link whose item carries the class');
$assert(array( '#gamma' ) === $reachedAnchors($kindsAnchors, $kindsRules, 'outline:1px solid red'), 'the child-combinator rule reaches only the last rendered link through the <ul>');
// The engine only carries an id that starts with a letter onto the item, so
// `#\31 23` is checked by shape above; it cannot reach rendered markup.

if ( $failures > 0 ) {
    fwrite(STDERR, "navigation-item-structural-pseudo: {$failures} failure(s), {$passes} pass(es)" . PHP_EOL);
    exit(1);
}
echo "navigation-item-structural-pseudo: {$passes} assertions passed" . PHP_EOL;

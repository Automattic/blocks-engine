<?php
declare(strict_types=1);

/**
 * A rule authored on a menu's list items reaches the rendered navigation items.
 *
 * core/navigation-link renders every source `<li>` as
 * `<li class="wp-block-navigation-item">`. The projector used to rewrite the
 * `li` type to the source-type marker (`:where(.blocks-engine-source-li-…)`),
 * which only list items lowered to groups carry, so `#menu li { display:inline;
 * padding-right:15px }` matched nothing in WordPress: the block's `blockGap`
 * is `0px` for a source list, core resets `ul li { padding:0 }`, and the labels
 * ran together. The item compound now moves onto the class core hard-codes on
 * the rendered item, the same way a structural pseudo-class on a direct anchor
 * already does.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
/** The projected author stylesheet alone; engine support CSS is not under test. */
$authorCss = static function (array $result): string {
    $parts = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') && ! str_contains((string) ($asset['path'] ?? ''), 'engine-support') && ! str_contains((string) ($asset['path'] ?? ''), 'editor-static-state') ) {
            $parts[] = (string) ($asset['content'] ?? '');
        }
    }
    return implode("\n", $parts);
};
/** @return list<array{selector: string, body: string}> */
$rules = static function (string $stylesheet): array {
    $rules = array();
    ( new CssStylesheetTransformer() )->visitStyleRules($stylesheet, static function (string $selector, string $body) use (&$rules): void {
        foreach ( CssStylesheetTransformer::splitSelectorList($selector) ?? array( $selector ) as $single ) {
            $rules[] = array( 'selector' => trim($single), 'body' => preg_replace('/\s+/', '', $body) ?? $body );
        }
    });
    return $rules;
};
/** @return list<string> */
$selectorsDeclaring = static function (array $rules, string $declaration): array {
    $selectors = array();
    foreach ( $rules as $rule ) {
        if ( str_contains($rule['body'], $declaration) ) {
            $selectors[] = $rule['selector'];
        }
    }
    return $selectors;
};
$item = '.wp-block-navigation-item';

// --- Horizontal inline menu: the item's own padding is the spacing. --------

$horizontal = $transform(
    '<style>'
    . '#header{overflow:hidden}#header img{float:left}'
    . '#header ul#menu{float:right;width:360px;font-size:12px;font-weight:bold}'
    . '#menu li{display:inline;padding-right:15px}'
    . '#menu li.promo{margin-left:30px}'
    . '#menu li:first-child{padding-left:0}'
    . '#menu li:hover{background:#eee}'
    . '#menu li a{color:#666;text-decoration:none}'
    . '</style>'
    . '<div id="header"><img src="/logo.png" alt="Logo" width="243" height="56">'
    . '<ul id="menu"><li><a href="#service">Service</a></li><li><a href="#work">Work</a></li><li><a href="#about">About</a></li>'
    . '<li class="promo"><a href="#contact">Contact</a></li><li><a href="/privacy.html">Privacy</a></li></ul></div>'
    . '<main><p>Body copy.</p></main>'
);
$markup = (string) ( $horizontal['serialized_blocks'] ?? '' );
$assert(str_contains($markup, '<!-- wp:navigation ') && 5 === substr_count($markup, '<!-- wp:navigation-link '), 'the list becomes a core/navigation with one navigation-link per item', substr($markup, 0, 300));
$horizontalRules = $rules($authorCss($horizontal));

$spacing = $selectorsDeclaring($horizontalRules, 'padding-right:15px');
$assert(
    1 === count($spacing) && str_contains($spacing[0], '#menu ' . $item) && ! str_contains($spacing[0], 'blocks-engine-source-li'),
    'the item padding rule addresses the rendered navigation item, not the source-type marker',
    json_encode($spacing)
);
$assert(
    1 === count($spacing) && str_contains($spacing[0], $item . ':not(blocks-engine-specificity-site-0)'),
    'the moved `li` type keeps its specificity through the type shim',
    json_encode($spacing)
);
$promo = $selectorsDeclaring($horizontalRules, 'margin-left:30px');
$assert(
    1 === count($promo) && str_contains($promo[0], $item . '.promo'),
    'an item class moves with the compound (core renders the source item class on the rendered item)',
    json_encode($promo)
);
$first = $selectorsDeclaring($horizontalRules, 'padding-left:0');
$assert(
    1 === count($first) && str_contains($first[0], $item . ':first-child'),
    'a structural pseudo-class stays on the item',
    json_encode($first)
);
$hover = $selectorsDeclaring($horizontalRules, 'background:#eee');
$assert(
    1 === count($hover) && str_contains($hover[0], $item . ':not(blocks-engine-specificity-site-0):hover'),
    'a dynamic state on the item stays on the rendered item',
    json_encode($hover)
);
$assert(
    ! str_contains($markup, '"blockGap":"15px"') && str_contains($markup, '"blockGap":"0px"'),
    'the block keeps the source list\'s zero gap: the carried item padding is the spacing, so it is not counted twice',
    substr($markup, 0, 400)
);

// --- Vertical menu: block items spaced by margin. --------------------------

$vertical = $transform(
    '<style>.side li{display:block;margin-bottom:10px;border-bottom:1px solid #ddd}.side li a{display:block}</style>'
    . '<header><p>Site</p></header><div class="side"><nav><ul><li><a href="/one/">One</a></li><li><a href="/two/">Two</a></li><li><a href="/three/">Three</a></li></ul></nav></div><main><p>Body copy.</p></main>'
);
$verticalMarkup = (string) ( $vertical['serialized_blocks'] ?? '' );
$assert(3 === substr_count($verticalMarkup, '<!-- wp:navigation-link '), 'the vertical list becomes a core/navigation', substr($verticalMarkup, 0, 300));
$verticalSpacing = $selectorsDeclaring($rules($authorCss($vertical)), 'margin-bottom:10px');
$assert(
    1 === count($verticalSpacing) && str_contains($verticalSpacing[0], $item) && ! str_contains($verticalSpacing[0], 'blocks-engine-source-li'),
    'a vertical item margin reaches the rendered item too',
    json_encode($verticalSpacing)
);

// --- A rule that also reaches list items outside the menu is left as authored.

$mixed = $transform(
    '<style>li{margin:0;padding:0}#menu li{display:inline;padding-right:15px}</style>'
    . '<div id="header"><ul id="menu"><li><a href="#a">Alpha</a></li><li><a href="#b">Beta</a></li></ul></div>'
    . '<main><ul class="facts"><li>One fact</li><li>Two facts</li></ul></main>'
);
$mixedRules = $rules($authorCss($mixed));
$reset = $selectorsDeclaring($mixedRules, 'margin:0');
$assert(
    1 === count($reset) && str_contains($reset[0], 'blocks-engine-source-li') && ! str_contains($reset[0], $item),
    'a `li` rule that also matches list items outside the menu keeps the source-type marker',
    json_encode($reset)
);
$mixedSpacing = $selectorsDeclaring($mixedRules, 'padding-right:15px');
$assert(
    1 === count($mixedSpacing) && str_contains($mixedSpacing[0], $item),
    'the menu-only rule in the same sheet still moves onto the rendered item',
    json_encode($mixedSpacing)
);

// --- Inline items separated by whitespace keep the space between them. -----
//
// Source inline list items sit in one line box, so the whitespace between
// `</li>` and `<li>` renders as one space of the list's font. core renders the
// items as flex items with no text between them, so that space is lost unless
// the item carries it. It is carried as a no-break space after every item but
// the last, which keeps it out of the start of a wrapped line, as in the source.

$separator = 'content:"\a0"';
/** @return list<string> Selectors of the rules that carry the inter-item space. */
$separators = static fn (array $result): array => $selectorsDeclaring($rules($authorCss($result)), $separator);
$inlineMenu = static fn (string $css, string $listAttributes = 'id="menu"', string $between = "\n\t\t"): string =>
    '<style>#header img{float:left}' . $css . '</style>'
    . '<div id="header"><img src="/logo.png" alt="Logo" width="243" height="56">'
    . '<ul ' . $listAttributes . '>' . $between . '<li><a href="#service">Service</a></li>' . $between . '<li><a href="#work">Work</a></li>'
    . $between . '<li><a href="#about">About</a></li>' . $between . '</ul></div><main><p>Body copy.</p></main>';

$spaced = $transform($inlineMenu('#header ul#menu{float:right;width:360px;font-size:12px}#menu li{display:inline;padding-right:15px}'));
$spacedSeparators = $separators($spaced);
$assert(
    1 === count($spacedSeparators)
        && str_contains($spacedSeparators[0], '#menu ' . $item)
        && str_ends_with($spacedSeparators[0], ':not(:last-child)::after'),
    'inline items separated by whitespace keep one space after every item but the last',
    json_encode($spacedSeparators)
);

// A reset (`li{font-size:100%}`) restates the list's font; it is not a font of the item's own.
$resetFont = $separators($transform($inlineMenu('ul,li{margin:0;padding:0;font-size:100%}#header ul#menu{font-size:12px}#menu li{display:inline;padding-right:15px}')));
$assert(1 === count($resetFont), 'a reset that restates the list font on the item keeps the space', json_encode($resetFont));

$commented = $separators($transform($inlineMenu('#menu li{display:inline;padding-right:15px}', 'id="menu"', "\n<!-- item -->\n")));
$assert(1 === count($commented), 'a comment between the items does not hide the whitespace around it', json_encode($commented));

$inlineBlock = $separators($transform($inlineMenu('#menu{font-size:13px}#menu li{display:inline-block;margin-right:10px}')));
$assert(1 === count($inlineBlock), 'inline-block items separated by whitespace keep the space too', json_encode($inlineBlock));

$conditional = $authorCss($transform($inlineMenu('@media (min-width: 600px){#menu li{display:inline;padding-right:15px}}')));
$assert(
    1 === preg_match('/@media\s*\(min-width:\s*600px\)\s*\{(?:[^{}]|\{[^{}]*\})*#menu [^{}]*:not\(:last-child\)::after\s*\{\s*content:\s*"\\\\a0"\s*\}/', $conditional),
    'a conditional inline rule carries the space under the same condition',
    $conditional
);

/** Where the source drew no space, the item rule still moves but no space is added. */
$keepsNoSpace = static function (array $result, string $itemDeclaration, string $message) use ($assert, $rules, $authorCss, $selectorsDeclaring, $separator, $item): void {
    $sheet = $rules($authorCss($result));
    $moved = $selectorsDeclaring($sheet, $itemDeclaration);
    $assert(1 === count($moved) && str_contains($moved[0], $item), $message . ': the item rule still reaches the rendered item', json_encode($moved));
    $assert(array() === $selectorsDeclaring($sheet, $separator), $message, json_encode($selectorsDeclaring($sheet, $separator)));
};
$keepsNoSpace($horizontal, 'padding-right:15px', 'inline items written with no whitespace between them gain no space');
$keepsNoSpace($transform($inlineMenu('#menu{font-size:0}#menu li{display:inline-block;font-size:14px;margin-right:10px}')), 'margin-right:10px', 'a list that zeroes its font size to remove the whitespace gap gains no space');
$keepsNoSpace($transform($inlineMenu('#menu{display:flex}#menu li{display:inline;padding-right:15px}')), 'padding-right:15px', 'items of a flex list are blockified, so their whitespace never rendered');
$keepsNoSpace($transform($inlineMenu('#menu li{display:inline;padding-right:15px}#header #menu li{float:left}')), 'padding-right:15px', 'floated items are blockified, so their whitespace never rendered');
$keepsNoSpace($transform($inlineMenu('#menu li{display:block;margin-bottom:10px}')), 'margin-bottom:10px', 'block items are not separated by a space');
$keepsNoSpace($transform($inlineMenu('#menu li{display:inline;padding-right:15px}#header #menu li{display:block}')), 'padding-right:15px', 'an inline rule that loses the cascade to a block rule adds no space');
$keepsNoSpace($transform($inlineMenu('#menu{white-space:pre}#menu li{display:inline;padding-right:15px}')), 'padding-right:15px', 'preserved whitespace is not one collapsed space');
$keepsNoSpace($transform($inlineMenu('#menu{font-size:12px}#menu li{display:inline;font-size:16px;padding-right:15px}')), 'padding-right:15px', 'an item font that differs from the list font would size the space wrongly');

$keepsNoSpace($transform($inlineMenu('#menu li{display:inline;padding-right:15px}#menu li:after{content:"|";padding-left:15px}')), 'padding-right:15px', 'an authored `li:after` separator keeps its own content');
$beforeSeparator = $separators($transform($inlineMenu('#menu li{display:inline;padding-right:15px}#menu li+li:before{content:"|"}')));
$assert(1 === count($beforeSeparator), 'an authored `li+li:before` separator does not compete with the trailing space', json_encode($beforeSeparator));

// A wrapping nav with one list keeps the space; with two lists core gathers
// both into one container, where the last item of the first list would gain a
// space it did not have in the source (the lists were separate boxes).
$wrapped = static fn (int $lists): string => '<style>.menus li{display:inline;padding-right:15px}</style><header><nav class="menus">'
    . str_repeat("\n<ul>\n<li><a href=\"/a/\">Alpha</a></li>\n<li><a href=\"/b/\">Beta</a></li>\n</ul>", $lists)
    . "\n</nav></header><main><p>Body copy.</p></main>";
$oneList = $separators($transform($wrapped(1)));
$assert(1 === count($oneList) && str_contains($oneList[0], '.menus ' . $item), 'a list inside a wrapping nav keeps the space', json_encode($oneList));
$twoLists = $transform($wrapped(2));
$assert(
    1 === substr_count((string) $twoLists['serialized_blocks'], '<!-- wp:navigation ') && 4 === substr_count((string) $twoLists['serialized_blocks'], '<!-- wp:navigation-link '),
    'two lists in one nav become one navigation with four items',
    substr((string) $twoLists['serialized_blocks'], 0, 300)
);
$keepsNoSpace($twoLists, 'padding-right:15px', 'items gathered from two source lists into one container gain no space');

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation item spacing tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Navigation item spacing tests: {$passes} passed\n");

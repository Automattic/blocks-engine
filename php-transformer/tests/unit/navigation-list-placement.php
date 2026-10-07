<?php
declare(strict_types=1);

/**
 * A placement rule authored on the menu list core/navigation stands in for
 * reaches the navigation block, not only the inner list copy.
 *
 * When the source list is the element the block replaces, WordPress renders
 * `<nav class="wp-block-navigation [classes]" id="[id]">` and copies the same
 * classes and id onto the inner `<ul class="wp-block-navigation__container">`.
 * A rule keyed by class or id therefore hits both; a rule qualified by the
 * list type (`#header ul#nav { float:right; width:360px; position:relative;
 * top:20px }`) hits only the inner `<ul>`, which is a flex item (float is
 * ignored) whose placement the engine resets on purpose, so the menu lost its
 * place. The type now addresses the block itself.
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
/** @return array{author: string, support: string} */
$css = static function (array $result): array {
    $author = array();
    $support = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' !== ($asset['kind'] ?? '') ) {
            continue;
        }
        $path = (string) ($asset['path'] ?? '');
        if ( str_contains($path, 'engine-support') ) {
            $support[] = (string) ($asset['content'] ?? '');
        } elseif ( ! str_contains($path, 'editor-static-state') ) {
            $author[] = (string) ($asset['content'] ?? '');
        }
    }
    return array( 'author' => implode("\n", $author), 'support' => implode("\n", $support) );
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
$wrapper = ':where(.wp-block-navigation:not(.wp-block-navigation__container))';

// --- The list is the block: a float/offset rule on `ul#menu` places the block.

$floated = $transform(
    '<style>'
    . '#header{overflow:hidden}#header img{float:left}'
    . '#header ul#menu{float:right;width:360px;position:relative;top:20px;right:-25px;font-size:12px}'
    . '#header ul#menu li{display:inline;padding-right:15px}'
    . '#header ul#menu li a{color:#666}'
    . '</style>'
    . '<div id="header"><img src="/logo.png" alt="Logo" width="243" height="56">'
    . '<ul id="menu"><li><a href="#service">Service</a></li><li><a href="#work">Work</a></li><li><a href="#about">About</a></li><li><a href="#contact">Contact</a></li></ul></div>'
    . '<main><p>Body copy.</p></main>'
);
$markup = (string) ( $floated['serialized_blocks'] ?? '' );
$assert(str_contains($markup, '<!-- wp:navigation {"anchor":"menu"') && 4 === substr_count($markup, '<!-- wp:navigation-link '), 'the list itself becomes the core/navigation block (its id is the block anchor)', substr($markup, 0, 300));
$floatedCss = $css($floated);
$floatedRules = $rules($floatedCss['author']);

$placement = $selectorsDeclaring($floatedRules, 'float:right');
$assert(
    1 === count($placement) && str_contains($placement[0], '#header ' . $wrapper) && str_contains($placement[0], '#menu') && ! str_contains($placement[0], 'ul#menu'),
    'the `ul` type moves onto the navigation block and stays off the inner list copy',
    json_encode($placement)
);
$assert(
    1 === count($placement) && str_contains($placement[0], $wrapper . ':not(blocks-engine-specificity-site-0)#menu'),
    'the moved type keeps its specificity through the type shim',
    json_encode($placement)
);
$placementBodies = array_values(array_filter($floatedRules, static fn (array $rule): bool => str_contains($rule['body'], 'float:right')));
$assert(
    1 === count($placementBodies) && str_contains($placementBodies[0]['body'], 'width:360px') && str_contains($placementBodies[0]['body'], 'position:relative') && str_contains($placementBodies[0]['body'], 'top:20px') && str_contains($placementBodies[0]['body'], 'right:-25px'),
    'width, position and offsets travel with the float onto the block',
    json_encode($placementBodies)
);
$assert(
    str_contains($floatedCss['support'], '.wp-block-navigation__container{position:static!important;inset:auto!important}'),
    'the inner list copy keeps the placement reset, so the offsets apply once'
);
// Rules that reach into the list keep addressing the inner list copy, which core
// still renders as the `<ul>` the items sit in.
$itemSpacing = $selectorsDeclaring($floatedRules, 'padding-right:15px');
$assert(
    1 === count($itemSpacing) && str_contains($itemSpacing[0], '#header ul#menu '),
    'a descendant rule keeps `ul#menu` as its ancestor compound',
    json_encode($itemSpacing)
);
$linkColor = $selectorsDeclaring($floatedRules, 'color:#666');
$assert(
    1 === count($linkColor) && str_contains($linkColor[0], '#header ul#menu '),
    'an anchor rule under the list keeps `ul#menu` as its ancestor compound',
    json_encode($linkColor)
);

// --- `float:left` is symmetric, and a class-qualified list type moves too. ------

$left = $transform(
    '<style>.bar{overflow:hidden}.bar img{float:right}.bar ul.links{float:left;width:50%}</style>'
    . '<div class="bar"><img src="/logo.png" alt="Logo" width="100" height="40"><ul class="links"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li><li><a href="/c/">C</a></li></ul></div><main><p>Body copy.</p></main>'
);
$leftRules = $rules($css($left)['author']);
$leftPlacement = $selectorsDeclaring($leftRules, 'float:left');
$assert(
    1 === count($leftPlacement) && str_contains($leftPlacement[0], $wrapper) && ! str_contains($leftPlacement[0], 'ul.links') && str_contains($leftPlacement[0], '.links'),
    'a class-qualified `ul.links` placement moves onto the block as well, keeping its class',
    json_encode($leftPlacement)
);

// --- A wrapper absorbed into the block keeps the list rule on the inner list. --
// `div.bar` has only the list inside it, so the block stands in for the div and
// carries both class sets; `ul.links` then still names the inner list copy.

$absorbed = $transform(
    '<style>.bar{overflow:hidden}.bar ul.links{float:left;width:50%}</style>'
    . '<div class="bar"><ul class="links"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li><li><a href="/c/">C</a></li></ul></div><main><p>Body copy.</p></main>'
);
$absorbedMarkup = (string) ( $absorbed['serialized_blocks'] ?? '' );
$absorbedPlacement = $selectorsDeclaring($rules($css($absorbed)['author']), 'float:left');
$assert(
    str_contains($absorbedMarkup, '"className":"bar blocks-engine-list-navigation links"') && 1 === count($absorbedPlacement) && str_contains($absorbedPlacement[0], 'ul.links') && ! str_contains($absorbedPlacement[0], $wrapper),
    'when the block stands in for the wrapper, the list-type rule is left as authored',
    json_encode($absorbedPlacement)
);

// --- A list inside a wrapping `nav` is not the block; its own rules are kept. --

$wrapped = $transform(
    '<style>nav.main{position:relative}nav.main ul.panel{position:absolute;top:100%;left:0}</style>'
    . '<header><nav class="main"><ul class="panel"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li></ul></nav></header><main><p>Body copy.</p></main>'
);
$wrappedRules = $rules($css($wrapped)['author']);
$panel = $selectorsDeclaring($wrappedRules, 'top:100%');
$assert(
    1 === count($panel) && str_contains($panel[0], 'ul.panel') && ! str_contains($panel[0], $wrapper),
    'a list the source places inside its own nav keeps its authored selector',
    json_encode($panel)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation list placement tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Navigation list placement tests: {$passes} passed\n");

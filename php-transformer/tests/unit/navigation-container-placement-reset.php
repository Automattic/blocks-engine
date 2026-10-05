<?php
declare(strict_types=1);

/**
 * WordPress copies a navigation block's classes onto the `nav` and its inner
 * `wp-block-navigation__container` list, so a source rule that places the menu
 * (position, offsets, transform) is charged twice: the `nav` becomes a
 * zero-size positioned box and the list is placed once more inside it, where
 * it shrinks to its longest word and wraps every item onto its own line.
 *
 * The menu's placement must be stated once, on the `nav`. This holds for every
 * class WordPress copies, not only the source's own classes: a class-less
 * `<nav>` reached through the `nav` type selector carries only the engine's
 * source-tag marker, and that marker is copied too.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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

$compile = static function (string $html, string $css): string {
    $result = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html, 'styles.css' => $css ),
    ))->toArray();
    $out = '';
    foreach ( $result['source_reports']['compiled_site']['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? '') ) {
            $out .= (string) ($asset['content'] ?? '');
        }
    }
    return $out;
};

/** Every rule whose subject is the navigation container and whose body resets placement. */
$placementResets = static function (string $css): array {
    $rules = array();
    foreach ( explode('}', $css) as $chunk ) {
        $brace = strrpos($chunk, '{');
        if ( false === $brace ) {
            continue;
        }
        $selector = substr($chunk, 0, $brace);
        $body = substr($chunk, $brace + 1);
        if ( str_contains($selector, '.wp-block-navigation__container') && str_contains($body, 'position:static!important') ) {
            $rules[] = trim($chunk) . '}';
        }
    }
    return $rules;
};

$document = static fn (string $nav): string => '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body>'
    . '<header><div class="site-bar"><strong class="brand">Brand</strong><button type="button">Menu</button>' . $nav . '</div></header>'
    . '<main><section id="alpha"><h2>Alpha</h2><p>First section copy.</p></section>'
    . '<section id="beta"><h2>Beta</h2><p>Second section copy.</p></section>'
    . '<section id="gamma"><h2>Gamma</h2><p>Third section copy.</p></section></main></body></html>';

$links = '<a href="#alpha">Alpha</a><a href="#beta">Beta</a><a href="#gamma">Gamma</a><a href="#delta">Delta</a>';
$barCss = '.site-bar{position:relative;display:flex;align-items:center;height:72px}';

// The observed shape: a class-less <nav> placed at the bar's right edge through
// the `nav` type selector. The only class WordPress copies onto the list is the
// engine's source-tag marker, so the reset must hang off that marker.
$typed = $compile(
    $document('<nav>' . $links . '</nav>'),
    $barCss . '.site-bar nav{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;align-items:center;gap:22px}.site-bar nav a{text-decoration:none}'
);
$typedResets = $placementResets($typed);
$assert(1 === count($typedResets), 'a type-selected positioned menu records one container placement reset (' . count($typedResets) . ' found)');
$typedReset = $typedResets[0] ?? '';
$assert(1 === preg_match('/\.wp-block-navigation\.blocks-engine-source-nav-[a-f0-9]{12}-\d+[^ ]* \.wp-block-navigation__container\{/', $typedReset), 'the placement reset is keyed on the source-tag marker WordPress copies onto the list: ' . $typedReset);
$assert(str_contains($typedReset, 'transform:none!important'), 'the placement reset drops the duplicated menu transform');
$assert(str_contains($typedReset, 'inset:auto!important'), 'the placement reset drops the duplicated menu offsets');
$assert(! str_contains($typedReset, 'padding:0!important') && ! str_contains($typedReset, 'background:none!important'), 'a placement-only menu records no frame or paint reset');
$assert(1 === preg_match('/\.site-bar :where\(\.blocks-engine-source-nav-[a-f0-9]{12}-\d+\)[^{]*\{[^}]*position:absolute/', $typed), 'the nav itself keeps the authored placement');
$assert(! str_contains($typedReset, 'display:') && ! str_contains($typedReset, 'gap:'), 'the list keeps the menu display and gap it needs to lay items out');

// A menu placed through its own class records the same reset keyed on that class.
$classed = $compile(
    $document('<nav class="site-menu">' . $links . '</nav>'),
    $barCss . '.site-menu{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;gap:22px}.site-menu a{text-decoration:none}'
);
$classedResets = $placementResets($classed);
$assert(1 === count($classedResets), 'a class-selected positioned menu records one container placement reset');
$assert(str_contains($classedResets[0] ?? '', '.wp-block-navigation.site-menu') && str_contains($classedResets[0] ?? '', 'transform:none!important'), 'the class-keyed placement reset neutralizes position and transform');

// A list that is itself the menu is placed once as well.
$list = $compile(
    $document('<ul class="site-menu"><li><a href="#alpha">Alpha</a></li><li><a href="#beta">Beta</a></li><li><a href="#gamma">Gamma</a></li><li><a href="#delta">Delta</a></li></ul>'),
    $barCss . '.site-menu{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;gap:22px;list-style:none;margin:0;padding:0}.site-menu a{text-decoration:none}'
);
$assert(1 === count($placementResets($list)), 'a positioned source list records one container placement reset');

// Placement states only on the nav, in flow, is not a placement to neutralize;
// a transform alone still is, since the list would be translated twice.
$plain = $compile(
    $document('<nav class="plain-menu">' . $links . '</nav>'),
    $barCss . '.plain-menu{display:flex;gap:1rem;margin-left:auto}.plain-menu a{text-decoration:none}'
);
$assert(array() === $placementResets($plain), 'an in-flow menu records no placement reset');
$translated = $compile(
    $document('<nav class="shifted-menu">' . $links . '</nav>'),
    $barCss . '.shifted-menu{display:flex;gap:1rem;transform:translateY(-4px)}.shifted-menu a{text-decoration:none}'
);
$assert(1 === count($placementResets($translated)), 'a transformed in-flow menu records a placement reset');

// A source that places its own list inside the nav (a panel dropping below the
// bar) keeps that list placement: the nav's `position:relative` is the
// containing block, not a placement the list must shed.
$dropdown = $compile(
    $document('<nav class="drop-menu"><ul><li><a href="#alpha">Alpha</a></li><li><a href="#beta">Beta</a></li><li><a href="#gamma">Gamma</a></li></ul></nav>'),
    $barCss . '.drop-menu{position:relative;display:block}.drop-menu>ul{position:absolute;top:100%;left:0;margin:0;padding:0;list-style:none}.drop-menu a{text-decoration:none}'
);
$assert(array() === $placementResets($dropdown), 'a list the source places itself keeps its placement');

// A brand anchor beside a link cluster is hoisted out of the nav, and
// core/navigation then stands in for the cluster. A cluster pinned to the
// bar's edge must be placed once as well.
$carrier = $compile(
    '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body>'
    . '<header><nav class="bar"><a class="brand" href="/">Brand</a><div class="links">' . $links . '</div></nav></header>'
    . '<main><section id="alpha"><h2>Alpha</h2><p>First section copy.</p></section><section id="beta"><h2>Beta</h2><p>Second section copy.</p></section></main></body></html>',
    '.bar{position:relative;display:flex;align-items:center;height:72px}.brand{font-weight:700}.links{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;gap:16px}.links a{text-decoration:none}'
);
$carrierResets = $placementResets($carrier);
$assert(1 === count($carrierResets) && str_contains($carrierResets[0], '.wp-block-navigation.links ') && ! str_contains($carrierResets[0], '.bar'), 'a hoisted-brand navigation keys the cluster placement reset on the cluster class');

// A fixed `<nav>` around a list splits: a core/group keeps the nav and its
// placement, and core/navigation stands in for the list. Nothing of the nav is
// then copied onto the container, so only the list's own declarations count.
$sidebar = static fn (string $listCss): string => $compile(
    '<!doctype html><html><head><link rel="stylesheet" href="styles.css"></head><body>'
    . '<nav class="side-nav"><ul class="side-list"><li><a href="#alpha">Alpha</a></li><li><a href="#beta">Beta</a></li><li><a href="#gamma">Gamma</a></li></ul></nav>'
    . '<main><section id="alpha"><h2>Alpha</h2><p>First section copy.</p></section><section id="beta"><h2>Beta</h2><p>Second section copy.</p></section></main></body></html>',
    '.side-nav{position:fixed;left:0;top:0;height:100vh;width:220px;padding:20px}.side-list{list-style:none;margin:0;padding:0;' . $listCss . '}.side-nav a{text-decoration:none}'
);
$assert(array() === $placementResets($sidebar('')), 'a split fixed nav records no placement reset for its in-flow list');
$splitResets = $placementResets($sidebar('transform:translateY(12px)'));
$assert(1 === count($splitResets) && str_contains($splitResets[0], '.wp-block-navigation.side-list ') && ! str_contains($splitResets[0], 'side-nav'), 'a split nav keys the list placement reset on the list class the navigation block carries');

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation container placement reset tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Navigation container placement reset tests: {$passes} passed" . PHP_EOL);

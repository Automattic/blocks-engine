<?php
declare(strict_types=1);

/**
 * Source nav anchor selectors are replayed against core/navigation's wrapper
 * markup. A responsive menu states its compact anchor box inside a breakpoint,
 * so the replay has to follow the source into its conditional groups.
 *
 * A selector that names the source list belongs to the structure pass, which
 * rewrites `li` and `a` against core's container. Mapping such a selector as a
 * bare anchor would fuse `.wp-block-navigation` onto the list item.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;

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

$compat = static fn (string $css): string => ( new WordPressCompatCss() )->css($css, array(), array());
$anchorSection = static function (string $css): string {
    $offset = strpos($css, 'replay source nav anchor selectors');
    return false === $offset ? '' : substr($css, $offset);
};

$responsive = $compat(
    '.jumpnav{display:flex}.jumpnav a{padding:0.35rem 0.85rem}'
    . '@media (max-width: 640px){.jumpnav a{padding:0.35rem 0.5rem}}'
);
$responsiveAnchors = $anchorSection($responsive);
$assert(str_contains($responsiveAnchors, 'padding:0.35rem 0.85rem'), 'the unconditional anchor rule is replayed');
$assert(
    (bool) preg_match('/@media \(max-width: 640px\)\s*\{[^}]*wp-block-navigation-item__content[^}]*padding:0\.35rem 0\.5rem/', $responsiveAnchors),
    'the conditional anchor rule is replayed inside its own group'
);

// Other conditional group types carry the same replay.
foreach ( array( '@supports (display:grid)', '@layer menu', '@container (min-width:20rem)' ) as $group ) {
    $scoped = $anchorSection($compat('.sitenav a{color:#222}' . $group . '{.sitenav a{color:#0a7d55}}'));
    $assert(str_contains($scoped, $group) && str_contains($scoped, '#0a7d55'), $group . ' replays its nav anchor rule');
}

// Nested groups keep their nesting.
$nested = $anchorSection($compat('.jumpnav a{padding:1rem}@media (max-width:40rem){@supports (display:flex){.jumpnav a{padding:0.25rem}}}'));
$assert(
    (bool) preg_match('/@media[^{]*\{\s*@supports[^{]*\{[^}]*padding:0\.25rem/', $nested),
    'a rule nested two groups deep keeps both conditions'
);

// A conditional selector that names the source list stays with the structure
// pass: the anchor mapper must not fuse the navigation class onto the item.
$listScoped = $compat(
    '.desktop-nav li{float:left}'
    . '@media screen and (min-width:1025px){body.menu-ready .desktop-nav ul.site-menu>li a{font-family:Montserrat;padding-bottom:7px}}'
);
$assert(! str_contains($listScoped, 'li.wp-block-navigation'), 'a conditional list selector never fuses the navigation class onto the list item');
$assert(! str_contains($anchorSection($listScoped), 'site-menu'), 'a conditional list selector is left to the structure pass');

// A group whose rules map to nothing emits no empty group.
$unrelated = $anchorSection($compat('.card{color:#222}@media (max-width:40rem){.card{color:#333}}'));
$assert(! str_contains($unrelated, '@media'), 'a group with no nav anchor rule emits no empty group');

// A menu stated only inside a breakpoint still replays.
$breakpointOnly = $anchorSection($compat('@media (max-width: 640px){.jumpnav{gap:0.25rem 0.5rem}.jumpnav a{padding:0.35rem 0.5rem}}'));
$assert(
    (bool) preg_match('/@media \(max-width: 640px\)\s*\{[^}]*wp-block-navigation-item__content[^}]*padding:0\.35rem 0\.5rem/', $breakpointOnly),
    'a menu stated only inside a breakpoint replays its anchor rule'
);

// The observed academic CV corpus case: compact anchor padding under 640px.
$cvCss = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/websites/31-personal-cv-academic/styles.css');
$cvAnchors = $anchorSection($compat($cvCss));
$assert(
    (bool) preg_match('/@media[^{]*\{[^}]*\.jumpnav[^}]*wp-block-navigation-item__content[^}]*padding:\s*0\.35rem 0\.5rem/', $cvAnchors),
    'academic CV jump nav replays its compact mobile anchor padding'
);

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation anchor conditional replay tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Navigation anchor conditional replay tests: {$passes} passed" . PHP_EOL);

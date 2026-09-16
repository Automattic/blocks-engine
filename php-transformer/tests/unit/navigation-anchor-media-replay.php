<?php
declare(strict_types=1);

/**
 * Source nav anchor selectors are replayed against core/navigation's wrapper
 * markup. A responsive menu states its compact anchor box inside `@media`, so
 * the replay has to follow the source into its conditional groups.
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

$responsive = $compat(
    '.jumpnav{display:flex}.jumpnav a{padding:0.35rem 0.85rem}'
    . '@media (max-width:40rem){.jumpnav a{padding:0.35rem 0.5rem}}'
);
$assert(str_contains($responsive, 'padding:0.35rem 0.85rem'), 'the unconditional anchor rule is replayed');
$assert(str_contains($responsive, '@media (max-width:40rem)'), 'the conditional group is retained');
$assert(str_contains($responsive, 'padding:0.35rem 0.5rem'), 'the conditional anchor rule is replayed');
$assert(
    (bool) preg_match('/@media \(max-width:40rem\)\s*\{[^}]*wp-block-navigation-item__content[^}]*padding:0\.35rem 0\.5rem/', $responsive),
    'the replayed conditional rule targets the native anchor inside its own group'
);

// Other conditional group types carry the same replay.
foreach ( array( '@supports (display:grid)', '@layer menu', '@container (min-width:20rem)' ) as $group ) {
    $scoped = $compat('.sitenav a{color:#222}' . $group . '{.sitenav a{color:#0a7d55}}');
    $assert(str_contains($scoped, $group) && str_contains($scoped, '#0a7d55'), $group . ' replays its nav anchor rule');
}

// A rule with nothing to map stays absent rather than emitting an empty group.
$unrelated = $compat('.card{color:#222}@media (max-width:40rem){.card{color:#333}}');
$assert(! str_contains($unrelated, 'wp-block-navigation-item__content'), 'a selector with no nav anchor records no replay');

// Nested groups keep their nesting.
$nested = $compat('.jumpnav a{padding:1rem}@media (max-width:40rem){@supports (display:flex){.jumpnav a{padding:0.25rem}}}');
$assert(
    (bool) preg_match('/@media[^{]*\{\s*@supports[^{]*\{[^}]*padding:0\.25rem/', $nested),
    'a rule nested two groups deep keeps both conditions'
);

// A source that states its whole menu inside one breakpoint still replays.
$breakpointOnly = $compat('@media (max-width: 640px){.jumpnav{gap:0.25rem 0.5rem}.jumpnav a{padding:0.35rem 0.5rem}}');
$assert(
    (bool) preg_match('/@media \(max-width: 640px\)\s*\{[^}]*wp-block-navigation-item__content[^}]*padding:0\.35rem 0\.5rem/', $breakpointOnly),
    'a menu stated only inside a breakpoint replays its anchor rule'
);

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation anchor media replay tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Navigation anchor media replay tests: {$passes} passed" . PHP_EOL);

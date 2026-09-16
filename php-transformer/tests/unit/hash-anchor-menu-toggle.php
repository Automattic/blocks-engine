<?php
declare(strict_types=1);

/**
 * A labelless hash-anchor whose accessible name is "Menu" is a hamburger
 * control. Site builders emit `<a href="#" aria-label="Menu">` without
 * role="button" or aria-controls. The panel is often a CSS-hidden wrapper
 * around the nav, not a hidden <nav> itself.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();
$markup = static fn (array $r): string => (string) ($r['serialized_blocks'] ?? '');

$weebly = $transform(
    '<header><a class="hamburger" href="#" aria-label="Menu"><span></span></a>'
    . '<div class="nav-wrap" style="display:none"><nav><ul>'
    . '<li><a href="/">Home</a></li><li><a href="/about">About</a></li>'
    . '<li><a href="/research">Research</a></li>'
    . '</ul></nav></div></header>'
);
$weeblyMarkup = $markup($weebly);

$assert(
    str_contains($weeblyMarkup, '"overlayMenu":"mobile"') || str_contains($weeblyMarkup, '"overlayMenu":"always"'),
    'a hash-anchor Menu control promotes native overlay navigation',
    $weeblyMarkup
);
$assert(
    str_contains($weeblyMarkup, '"label":"Home"')
        && str_contains($weeblyMarkup, '"label":"About"')
        && str_contains($weeblyMarkup, '"label":"Research"'),
    'all source destinations survive on the promoted navigation',
    $weeblyMarkup
);
$assert(
    str_contains($weeblyMarkup, 'blocks-engine-native-responsive-navigation'),
    'the promoted navigation is the native responsive overlay host',
    $weeblyMarkup
);

$realLink = $transform(
    '<header><a href="/about" aria-label="Menu">About</a><nav><ul><li><a href="/">Home</a></li></ul></nav></header>'
);
$assert(
    ! str_contains($markup($realLink), 'blocks-engine-native-responsive-navigation'),
    'a real destination labelled Menu is not treated as a hamburger',
    $markup($realLink)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "hash-anchor menu toggle FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "hash-anchor menu toggle passed: {$passes} assertions\n";

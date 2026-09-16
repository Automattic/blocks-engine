<?php
declare(strict_types=1);

/**
 * Unit tests for responsively hidden disclosure controls.
 *
 * Plain-PHP test script — no PHPUnit. A control a responsive utility hides
 * (`md:hidden`) states `display` only inside a media condition. Resolving the
 * control's box at one reference viewport flattens that set to whichever value
 * applied there, and the generated support CSS carrying it is unlayered — so it
 * outranks the author's own layered rule and a small-screen control renders on
 * every screen, taking a slot in its parent's layout.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$supportCss = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) ) $css .= "\n" . (string) ( $asset['content'] ?? '' );
    }
    return $css;
};

$scaffold = '<style>details.dla-disclosure>summary{list-style:none;cursor:pointer;display:inline-block}'
    . '@media (width>=48rem){.md\:hidden{display:none}}</style>';

$hidden = $transform(
    $scaffold
    . '<header><nav><a href="/a">A</a></nav>'
    . '<details class="dla-disclosure"><summary class="md:hidden" aria-label="Abrir menu">Menu</summary><div><a href="/a">A</a></div></details>'
    . '</header><main><p>Body</p></main>'
);
$blocks = (string) ( $hidden['serialized_blocks'] ?? '' );
$css    = $supportCss($hidden);

$assert(
    1 === preg_match('/(blocks-engine-disclosure-summary-[0-9a-f]{12})/', $blocks, $marker),
    'the details block carries a disclosure marker',
    $blocks
);
$markerClass = $marker[1] ?? '';

$assert(
    '' !== $markerClass && str_contains($css, '@media (width>=48rem){.wp-block-details.' . $markerClass . '{display:none}}'),
    'the source condition travels with the display value',
    $css
);
$assert(
    '' !== $markerClass && ! preg_match('/\.wp-block-details\.' . preg_quote($markerClass, '/') . '>summary\{[^}]*display:inline-block/', $css),
    'the reference-viewport display is not also stated unconditionally',
    $css
);
$assert(
    '' !== $markerClass && str_contains($css, '{.wp-block-details.' . $markerClass . '{display:none}}'),
    'the condition hides the block holding the control slot, not just the inner trigger',
    $css
);

// A control the source shows at every viewport keeps its flat box.
$always = $transform(
    '<style>details.dla-disclosure>summary{list-style:none;display:inline-block;padding:8px}</style>'
    . '<header><details class="dla-disclosure"><summary aria-label="Abrir menu">Menu</summary><div><a href="/a">A</a></div></details></header>'
    . '<main><p>Body</p></main>'
);
$alwaysCss = $supportCss($always);
$assert(
    ! str_contains($alwaysCss, '@media') || ! preg_match('/@media[^{]*\{\.wp-block-details\.[0-9a-z-]+\{display:/', $alwaysCss),
    'an unconditionally shown control emits no viewport-scoped display',
    $alwaysCss
);
$assert(
    str_contains($alwaysCss, 'display:inline-block'),
    'an unconditionally shown control still carries its resolved display',
    $alwaysCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Disclosure responsive display: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Disclosure responsive display passed: {$passes} assertions\n");

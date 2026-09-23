<?php
declare(strict_types=1);

/**
 * An inline-block anchor centered only through an inherited ancestor
 * `text-align` becomes a core/buttons flex wrapper whose default main-axis
 * alignment is left. The inherited alignment must be restated as
 * layout.justifyContent or the source's centering is lost.
 *
 * Regression for Automattic/blocks-engine#2150: the wrapper consulted only the
 * element and its immediate parent, so an anchor nested more than one level
 * under a `text-align:center` container compiled left-aligned.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static fn (string $html): string =>
    (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );

$buttonsBlockFor = static function (string $markup, string $label): string {
    $start = strpos($markup, '<!-- wp:buttons');
    while ( false !== $start ) {
        $end = strpos($markup, '<!-- /wp:buttons -->', $start);
        $chunk = false === $end ? '' : substr($markup, $start, $end - $start);
        if ( str_contains($chunk, $label) ) {
            return $chunk;
        }
        $start = strpos($markup, '<!-- wp:buttons', $start + 1);
    }

    return '';
};

$style = '<style>'
    . '.text-center{text-align:center}'
    . '.text-right{text-align:right}'
    . '.text-left{text-align:left}'
    . '.cta{display:inline-block;background:#111;color:#fff;padding:14px 40px;border-radius:8px}'
    . '</style>';

$controlMarkup = '<div class="mt-8"><a class="cta" href="https://example.com/">Subscribe</a></div>';

// The issue shape: the anchor's own parent carries no text-align; the centering
// comes from the grandparent and inherits down to the inline control.
$centered = $transform(
    $style
    . '<div class="text-center"><h2>Stay in the Loop</h2>'
    . $controlMarkup
    . '</div>'
);
$centeredButtons = $buttonsBlockFor($centered, 'Subscribe');

$assert(
    '' !== $centeredButtons && str_contains($centeredButtons, '<!-- wp:buttons'),
    'the inherited-center anchor is recognized as a core/buttons block',
    $centered
);
$assert(
    1 === preg_match('/"layout":\{"type":"flex","justifyContent":"center"\}/', $centeredButtons),
    'an anchor centered through a grandparent text-align:center justifies its core/buttons wrapper center',
    $centeredButtons
);

$rightAligned = $transform(
    $style
    . '<div class="text-right"><div class="wrap">'
    . $controlMarkup
    . '</div></div>'
);
$assert(
    1 === preg_match('/"layout":\{"type":"flex","justifyContent":"right"\}/', $buttonsBlockFor($rightAligned, 'Subscribe')),
    'right inheritance maps to justifyContent right the same way',
    $rightAligned
);

// The nearest explicit declaration wins, matching CSS inheritance: a closer
// left declaration on the intermediate wrapper overrides the centered ancestor.
$nearestWins = $transform(
    $style
    . '<div class="text-center"><div class="text-left">'
    . $controlMarkup
    . '</div></div>'
);
$assert(
    ! str_contains($buttonsBlockFor($nearestWins, 'Subscribe'), '"justifyContent":"center"'),
    'a nearer text-align declaration wins over the more distant ancestor',
    $nearestWins
);

// Without any inherited alignment the wrapper keeps the default left geometry.
$leftDefault = $transform(
    $style
    . '<div><div class="mt-8">'
    . $controlMarkup
    . '</div></div>'
);
$assert(
    '' !== $buttonsBlockFor($leftDefault, 'Subscribe')
    && ! str_contains($buttonsBlockFor($leftDefault, 'Subscribe'), '"justifyContent"'),
    'a button without inherited centering keeps the default left-aligned wrapper',
    $leftDefault
);

if ( $failures ) {
    fwrite(STDERR, $failures . " inherited text-align button justification test(s) failed\n");
    exit(1);
}

echo 'Inherited text-align button justification tests: ' . $passes . " passed\n";

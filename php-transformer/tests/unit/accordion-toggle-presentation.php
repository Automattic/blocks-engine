<?php
declare(strict_types=1);

/**
 * Unit tests for accordion trigger presentation.
 *
 * Plain-PHP test script — no PHPUnit. core/accordion-heading saves its own
 * `<button class="wp-block-accordion-heading__toggle">` with no other
 * attributes, so a source trigger's classes are dropped and every author rule
 * addressing them is left with nothing to match. A trigger that stated its own
 * vertical padding would collapse onto the destination theme's defaults and
 * every row in the accordion would lose that height. The box is restated as
 * generated CSS keyed on a marker the heading carries, because adding
 * attributes to the toggle would diverge from core's save shape.
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

$item = static fn (string $label, string $answer): string =>
    '<div class="border-b"><h3 class="flex"><button type="button" class="trigger" aria-expanded="false">' . $label
    . '<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></button></h3><div><p>' . $answer . '</p></div></div>';

$padded = $transform(
    '<style>.trigger{display:flex;align-items:center;justify-content:space-between;padding-top:1rem;padding-bottom:1rem}</style>'
    . '<main><div class="w-full">' . $item('Question one', 'Answer one') . $item('Question two', 'Answer two') . '</div></main>'
);
$blocks = (string) ( $padded['serialized_blocks'] ?? '' );
$css    = $supportCss($padded);

$assert(str_contains($blocks, 'wp:accordion-heading'), 'the source disclosure list still converts to core/accordion', $blocks);
$assert(
    1 === preg_match('/"className":"(blocks-engine-accordion-toggle-[0-9a-f]{12})"/', $blocks, $marker),
    'the accordion heading carries a toggle presentation marker',
    $blocks
);
$markerClass = $marker[1] ?? '';

$assert(
    str_contains($blocks, '<button type="button" class="wp-block-accordion-heading__toggle">'),
    'the saved toggle keeps core\'s exact save shape',
    $blocks
);
$assert(
    '' !== $markerClass && ! str_contains($blocks, 'class="wp-block-accordion-heading__toggle ' . $markerClass),
    'the marker is not added to the toggle button itself'
);
$assert(
    '' !== $markerClass && str_contains($css, '.wp-block-accordion-heading.' . $markerClass . '>.wp-block-accordion-heading__toggle{'),
    'generated CSS targets the saved toggle through the heading marker',
    $css
);
$assert(
    str_contains($css, 'padding-top:1rem') && str_contains($css, 'padding-bottom:1rem'),
    'the source trigger\'s vertical padding is restated, so rows keep their height',
    $css
);

// Two triggers resolving to the same box share one marker and one rule.
$assert(
    '' !== $markerClass && 2 === substr_count($blocks, '"className":"' . $markerClass . '"'),
    'both headings reuse the marker their identical triggers resolve to'
);
$assert(
    '' !== $markerClass && 1 === substr_count($css, '.wp-block-accordion-heading.' . $markerClass . '>'),
    'an identical trigger box emits one shared rule'
);

// A trigger with nothing of its own to carry stays unmarked.
$bare = $transform(
    '<main><div class="w-full">'
    . '<div><h3><button type="button" aria-expanded="false">Plain one</button></h3><div><p>A</p></div></div>'
    . '<div><h3><button type="button" aria-expanded="false">Plain two</button></h3><div><p>B</p></div></div>'
    . '</div></main>'
);
$assert(
    ! str_contains((string) ( $bare['serialized_blocks'] ?? '' ), 'blocks-engine-accordion-toggle-'),
    'a trigger with no resolved box of its own carries no marker',
    (string) ( $bare['serialized_blocks'] ?? '' )
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Accordion toggle presentation: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Accordion toggle presentation passed: {$passes} assertions\n");

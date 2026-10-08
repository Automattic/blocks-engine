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
$provedIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="source-icon" data-dla-disclosure-closed-class="source-icon" data-dla-disclosure-open-class="source-icon source-open"><path d="m6 9 6 6 6-6"/></svg>';
$icons = $transform('<style>.source-icon{color:#345;transition-property:transform;transition-duration:150ms}main button .source-open{transform:rotate(135deg)}</style><main><div>'
    . '<div><button aria-expanded="false" aria-controls="one">One' . $provedIcon . '</button><div role="region" id="one" hidden><p>A</p></div></div>'
    . '<div><button aria-expanded="false" aria-controls="two">Two' . $provedIcon . '</button><div role="region" id="two" hidden><p>B</p></div></div>'
    . '</div></main>');
$iconCss = $supportCss($icons);
$assert(str_contains($iconCss, 'data:image/svg+xml,'), 'a source-proved vector occupies the native icon slot through CSS, not invalid extra markup', $iconCss);
preg_match('/data:image\/svg\+xml,([^"\)]+)/', $iconCss, $vectorUrl);
$vectorDocument = new DOMDocument();
$assert(@$vectorDocument->loadXML(rawurldecode($vectorUrl[1] ?? '')), 'the standalone native vector is well-formed XML with one SVG namespace');
$assert(str_contains($iconCss, 'width:18px') && str_contains($iconCss, 'height:18px'), 'the source icon box replaces the larger core default', $iconCss);
$assert(1 === preg_match('/\[aria-expanded="true"\]>\.wp-block-accordion-heading__toggle-icon\{[^}]*transform:rotate\(135deg\)/', $iconCss), 'open presentation comes from the observed class state on the actual native slot, not an unused author rule or rotation guess', $iconCss);
$assert(str_contains($iconCss, 'transition-property:transform;transition-duration:150ms'), 'observed transition presentation survives on the native slot');
$assert('pass' === ($icons['source_reports']['wp_block_validity']['status'] ?? null), '135-degree source state keeps canonical Core block validity');
$assert(!str_contains($icons['serialized_blocks'], '<svg') && str_contains($icons['serialized_blocks'], 'aria-hidden="true">+</span>'), 'core accordion save markup remains canonical', $icons['serialized_blocks']);

$responsive = $transform(
    '<style>body{font-family:Arial,sans-serif;line-height:1.6}button{font-family:inherit;line-height:inherit}.trigger{padding:20px}.label{font-size:16px;line-height:24px}'
    . '@media(min-width:768px){.trigger{padding:24px}}</style><main><div>'
    . $item('<span class="label">Responsive one</span>', 'A') . $item('<span class="label">Responsive two</span>', 'B')
    . '</div></main>'
);
$responsiveCss = $supportCss($responsive);
$assert(1 === preg_match('/wp-block-accordion-heading__toggle\{[^}]*font-family:Arial,sans-serif/', $responsiveCss), 'the toggle keeps source inherited body typography rather than destination heading typography', $responsiveCss);
$assert(1 === preg_match('/wp-block-accordion-heading__toggle\{[^}]*line-height:1.6/', $responsiveCss), 'an authored inherited line height crosses the generated heading wrapper', $responsiveCss);
$assert(1 === preg_match('/wp-block-accordion-heading__toggle-title\{[^}]*font-size:16px;line-height:24px/', $responsiveCss), 'the generated title wrapper keeps the source label line box instead of enlarging each row', $responsiveCss);
$assert(1 === preg_match('/@media\s*\(min-width:768px\)\{[^}]*wp-block-accordion-heading__toggle\{[^}]*padding:24px/', $responsiveCss), 'responsive toggle padding travels with its source breakpoint', $responsiveCss);

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

$iconItem = static fn (string $label): string => '<article><button type="button" aria-expanded="false">'
    . $label . '<svg class="resting-mark" data-dla-disclosure-open-class="expanded-mark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></button><div role="region" hidden><p>Answer</p></div></article>';
$iconResult = $transform('<style>.resting-mark{color:#345678;rotate:0deg}.expanded-mark{rotate:180deg}</style><main><section>'
    . $iconItem('Neutral one') . $iconItem('Neutral two') . '</section></main>');
$iconCss = $supportCss($iconResult);
$assert(str_contains($iconCss, 'background-image:url("data:image/svg+xml,'), 'native icon CSS retains source SVG artwork', $iconCss);
$assert(str_contains(rawurldecode($iconCss), 'm6 9 6 6 6-6'), 'retained icon uses observed shape rather than a guessed icon family');
$assert(str_contains(rawurldecode($iconCss), 'color:#345678'), 'standalone currentColor artwork retains authored source paint', rawurldecode($iconCss));
$assert(str_contains($iconCss, 'width:18px;height:18px'), 'native icon uses source dimensions');
$assert(str_contains($iconCss, '[aria-expanded="true"]>.wp-block-accordion-heading__toggle-icon{transform:none;rotate:180deg}'), 'expanded rotation comes from observed source classes', $iconCss);
$assert(str_contains((string) $iconResult['serialized_blocks'], '<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>'), 'core icon save markup remains valid and unchanged');
preg_match('/<!-- wp:accordion-heading (\{.*?\}) -->/', (string) $iconResult['serialized_blocks'], $headingComment);
$headingAttrs = json_decode($headingComment[1] ?? 'null', true);
$renderedIcon = (string) ($headingAttrs['metadata']['blocksEngineIcon'] ?? '');
$assert(str_starts_with($renderedIcon, '<svg') && str_contains($renderedIcon, 'm6 9 6 6 6-6'), 'the observed vector rides in block metadata for the theme to render into core\'s slot', (string) $iconResult['serialized_blocks']);
$assert(! str_contains($renderedIcon, 'data-dla-') && ! str_contains($renderedIcon, 'rotate'), 'rendered artwork carries neither capture annotations nor a baked state transform', $renderedIcon);
$assert(str_contains($iconCss, '[aria-expanded]>.wp-block-accordion-heading__toggle-icon:has(>svg){background-image:none;transform:none'), 'a rendered vector replaces the background artwork and the slot stops transforming', $iconCss);
$assert(str_contains($iconCss, '[aria-expanded="true"]>.wp-block-accordion-heading__toggle-icon>svg{transform:none;rotate:180deg}'), 'the rendered vector owns the observed expanded transform', $iconCss);
$assert('pass' === ($iconResult['source_reports']['wp_block_validity']['status'] ?? null), 'icon metadata keeps canonical Core block validity');
$assert(str_contains(Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::ACCORDION_ICON_RENDERER, "render_block_core/accordion-heading") && str_contains(Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::ACCORDION_ICON_RENDERER, 'wp_kses('), 'the theme renders metadata artwork only through sanitized passive SVG');

if ( $failures > 0 ) {
    fwrite(STDERR, "Accordion toggle presentation: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Accordion toggle presentation passed: {$passes} assertions\n");

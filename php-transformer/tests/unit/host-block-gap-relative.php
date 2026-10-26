<?php
declare(strict_types=1);

/**
 * Relative margins under `host_block_gap` (Automattic/blocks-engine#3535).
 *
 * A margin that is `em` of the element's own font size stays an em length on
 * that element's block when the font size is fluid (a theme.json preset that
 * resolves to `clamp(..vw..)` through `var()`), so it is right at every
 * viewport. Flex and grid items keep their own start and end margins on their
 * own block. A fixed font size keeps px values with the same spacing, and
 * collapsed block-flow margins that are not one element's own margin stay px.
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

/** @return list<array<string, mixed>> */
$named = static function (array $blocks, string $name) use (&$named): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ( $block['blockName'] ?? null ) ) {
            $found[] = $block;
        }
        array_push($found, ...$named(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }

    return $found;
};

$margin = static fn (?array $block, string $side): mixed => $block['attrs']['style']['spacing']['margin'][$side] ?? null;

/**
 * The start margin core's flow layout gives a child after the first: its own,
 * else the parent group's blockGap.
 */
$applied = static function (array $blocks, ?array $block) use (&$applied, $margin): mixed {
    foreach ( $blocks as $parent ) {
        $children = array_values(is_array($parent['innerBlocks'] ?? null) ? $parent['innerBlocks'] : array());
        $index = array_search($block, $children, true);
        if ( false !== $index ) {
            return $margin($block, 'top') ?? ( 0 === $index ? null : ( $parent['attrs']['style']['spacing']['blockGap'] ?? null ) );
        }
        $found = $applied($children, $block);
        if ( null !== $found ) {
            return $found;
        }
    }

    return null;
};

$transform = static fn (string $html, string $css, ?string $gap): array => ( new HtmlTransformer() )->transform(
    '<main>' . $html . '</main>',
    null === $gap ? array( 'static_css' => $css ) : array( 'static_css' => $css, 'host_block_gap' => $gap )
)->toArray();

$fluid = ':root{--wp--preset--font-size--x-large:clamp(1.75rem, 3vw, 2.25rem)}h3{font-size:var(--wp--preset--font-size--x-large)}';
$card = '<div class="card"><div class="media"><img src="https://example.com/a.jpg" alt=""></div><h3>Coco Rope Tug</h3><p>Braided cotton rope.</p></div>';
$column = '.card{display:flex;flex-direction:column;padding:24px;border:1px solid #ccc}.media{margin:0}';
$flow = '<div class="box"><h3>Coco Rope Tug</h3><p>Braided cotton rope.</p></div>';
$padded = '.box{padding:24px}';

// label => [html, css, expected heading start/end margin, expected paragraph start/end margin]
$cases = array(
    'flex column, fluid h3: the heading keeps 1em on both sides' => array( $card, $fluid . $column, array( '1em', '1em' ), array( '16px', '16px' ) ),
    'flex column, fixed h3: px values, each item keeps its own margins' => array( $card, 'h3{font-size:24px}' . $column, array( '24px', '24px' ), array( '16px', '16px' ) ),
    'flow, fluid h3 first in a padded box: its own start margin stays relative' => array( $flow, $fluid . $padded, array( '1em', null ), array( '18.72px', '16px' ) ),
    'flow, fixed h3: collapsed margins stay px' => array( $flow, 'h3{font-size:24px}' . $padded, array( '24px', null ), array( '24px', '16px' ) ),
);

foreach ( $cases as $label => list( $html, $css, $heading, $paragraph ) ) {
    $result = $transform($html, $css, '1.5rem');
    $blocks = $result['blocks'] ?? array();
    $h3 = $named($blocks, 'core/heading')[0] ?? null;
    $p = $named($blocks, 'core/paragraph')[0] ?? null;
    $assert(null !== $h3 && null !== $p, "{$label}: the heading and paragraph are blocks");
    $got = array( $applied($blocks, $h3), $margin($h3, 'bottom') );
    $assert($heading === $got, "{$label}: heading margins", json_encode($heading) . ' got ' . json_encode($got));
    $got = array( $applied($blocks, $p), $margin($p, 'bottom') );
    $assert($paragraph === $got, "{$label}: paragraph margins", json_encode($paragraph) . ' got ' . json_encode($got));
    if ( null !== $margin($h3, 'top') ) {
        $assert(str_contains((string) ( $h3['innerHTML'] ?? '' ), 'margin-top:' . $heading[0]), "{$label}: the saved heading markup carries its start margin", (string) ( $h3['innerHTML'] ?? '' ));
    }
    $serialized = (string) ( $result['serialized_blocks'] ?? '' );
    foreach ( array( 'Coco', 'Rope', 'Tug', 'Braided', 'cotton', 'rope.' ) as $word ) {
        $assert(str_contains($serialized, $word), "{$label}: keeps {$word}");
    }
    $assert(
        $transform($html, $css, null)['serialized_blocks'] === ( new HtmlTransformer() )->transform('<main>' . $html . '</main>', array( 'static_css' => $css, 'host_block_gap' => null ))->toArray()['serialized_blocks'],
        "{$label}: a null host_block_gap is the same as none"
    );
}

// Flow siblings whose margins collapse: a fluid heading's end margin and the
// paragraph's start margin are not one relative value, so the paragraph keeps
// the px estimate (max of 1em of the estimated 18.72px and 16px).
$collapsed = $named($transform($flow, $fluid . $padded, '1.5rem')['blocks'] ?? array(), 'core/group');
$box = $collapsed[0] ?? null;
$assert('18.72px' === ( $box['attrs']['style']['spacing']['blockGap'] ?? null ), 'flow, fluid h3: the collapsed spacing stays the px blockGap', json_encode($box['attrs'] ?? null));

if ( 0 < $failures ) {
    fwrite(STDERR, "host block gap relative FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "host block gap relative passed: {$passes} assertions\n";

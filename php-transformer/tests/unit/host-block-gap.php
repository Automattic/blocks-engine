<?php
declare(strict_types=1);

/**
 * Converting into a theme the engine does not own (Automattic/blocks-engine#3479).
 *
 * WordPress's flow layout gives every child after the first in a group
 * `margin-block-start` = the group's `style.spacing.blockGap`, else the
 * theme's `styles.spacing.blockGap`; a child's own `style.spacing.margin.top`
 * wins. Given `host_block_gap`, the blocks encode the source's spacing between
 * children so that rule reproduces it: a uniform spacing becomes the group's
 * blockGap and children that differ carry their own top margin. Rows the
 * author CSS lays out with flex still get flow margins in WordPress, so they
 * are encoded too. Without the option the output does not change.
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

$host = '1.5rem';

$px = static function (mixed $value): ?float {
    if ( ! is_string($value) ) {
        return null;
    }
    $value = trim($value);
    if ( 1 === preg_match('/^-?0(?:\.0+)?(?:px|rem|em)?$/', $value) ) {
        return 0.0;
    }
    if ( 1 === preg_match('/^(-?\d*\.?\d+)px$/', $value, $match) ) {
        return round((float) $match[1], 3);
    }
    if ( 1 === preg_match('/^(-?\d*\.?\d+)rem$/', $value, $match) ) {
        return round(16 * (float) $match[1], 3);
    }

    return null;
};

/** @return array<string, mixed>|null */
$find = static function (array $blocks, string $class) use (&$find): ?array {
    foreach ( $blocks as $block ) {
        $classes = preg_split('/\s+/', (string) ( $block['attrs']['className'] ?? '' )) ?: array();
        if ( 'core/group' === ( $block['blockName'] ?? '' ) && in_array($class, $classes, true) ) {
            return $block;
        }
        $found = $find(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $class);
        if ( null !== $found ) {
            return $found;
        }
    }

    return null;
};

/**
 * The start margin WordPress's layout gives each child after the first.
 *
 * @return list<?float>
 */
$applied = static function (array $group) use ($px, $host): array {
    $type = $group['attrs']['layout']['type'] ?? 'default';
    $gap = $group['attrs']['style']['spacing']['blockGap'] ?? null;
    $children = array_values($group['innerBlocks'] ?? array());
    if ( in_array($type, array( 'flex', 'grid' ), true) ) {
        $gap = is_array($gap) ? ( $gap['left'] ?? null ) : $gap;
        return array_fill(0, count($children) - 1, $px($gap ?? $host));
    }
    $gap = is_array($gap) ? ( $gap['top'] ?? null ) : $gap;
    $margins = array();
    foreach ( array_slice($children, 1) as $child ) {
        $margins[] = $px($child['attrs']['style']['spacing']['margin']['top'] ?? $gap ?? $host);
    }

    return $margins;
};

/** Every spacing value the blocks carry, for the style-engine validity check. */
$spacingValues = static function (array $blocks) use (&$spacingValues): array {
    $values = array();
    foreach ( $blocks as $block ) {
        $spacing = $block['attrs']['style']['spacing'] ?? array();
        foreach ( array( 'blockGap', 'margin' ) as $key ) {
            $value = $spacing[$key] ?? null;
            foreach ( is_array($value) ? $value : ( null === $value ? array() : array( $value ) ) as $side ) {
                $values[] = $side;
            }
        }
        array_push($values, ...$spacingValues(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()));
    }

    return $values;
};

$transform = static fn (string $html, string $css, ?string $gap): array => ( new HtmlTransformer() )->transform(
    '<main>' . $html . '</main>',
    null === $gap ? array( 'static_css' => $css ) : array( 'static_css' => $css, 'host_block_gap' => $gap )
)->toArray();

// label => [css, html, class => expected start margins of children after the first,
//           extra checks on the encoded tree]
$cases = array(
    'product card: flex row and plain wrappers keep zero spacing' => array(
        '.card{padding:16px;border:1px solid #ccc}.paw-row{display:flex;justify-content:space-between}.card h3{margin:0 0 8px}.card p{margin:0}',
        '<div class="card"><div class="media"><img src="https://example.com/a.jpg" alt=""><span class="tag">New</span></div><div class="paw-row"><span class="pill">For dogs</span><span class="price">$14</span></div><div class="body"><h3>Rope toy</h3><p>Braided cotton.</p></div></div>',
        array( 'card' => array( 0.0, 0.0 ), 'paw-row' => array( 0.0 ), 'body' => array( 8.0 ) ),
    ),
    'header row: author flex children get no flow margin' => array(
        '.inner{display:flex;justify-content:space-between;align-items:center;padding:16px}.brand{font-weight:700}',
        '<header class="site-header"><div class="inner"><a class="brand" href="/">Brand</a><nav><a href="/a/">Alpha</a><a href="/b/">Beta</a></nav></div></header>',
        array( 'inner' => array( 0.0 ) ),
    ),
    'inline margin: the source margin wins over the gap' => array(
        '.stack{padding:8px}',
        '<div class="stack"><div class="a"><strong>One</strong><em>first</em></div><div class="b" style="margin-top:20px"><strong>Two</strong><em>second</em></div></div>',
        array( 'stack' => array( 20.0 ) ),
    ),
    'uneven margins: user-agent heading and paragraph margins collapse, an author margin differs' => array(
        '.uneven{padding:4px}.late{margin-top:2rem}',
        '<div class="uneven"><h2>Alpha</h2><p>Bravo</p><p>Charlie</p><div class="late">Echo</div></div>',
        array( 'uneven' => array( 19.92, 16.0, 32.0 ) ),
    ),
    'collapse through: a wrapper without border passes its child margins to its siblings' => array(
        '.outer{border:1px solid red}.boxed{margin-top:30px;border:1px solid}',
        '<section class="outer"><div class="plain"><h3>One</h3><p>Two</p></div><div class="boxed"><h3>Three</h3><p>Four</p></div><div class="shaded"><p>Five</p></div></section>',
        array( 'outer' => array( 30.0, 16.0 ), 'plain' => array( 18.72 ), 'boxed' => array( 18.72 ) ),
    ),
);

foreach ( $cases as $label => list( $css, $html, $expected ) ) {
    $result = $transform($html, $css, $host);
    $blocks = $result['blocks'] ?? array();
    $serialized = (string) ( $result['serialized_blocks'] ?? '' );
    foreach ( $expected as $class => $margins ) {
        $group = $find($blocks, $class);
        $assert(null !== $group, "{$label}: .{$class} is a group");
        if ( null === $group ) {
            continue;
        }
        $assert(count($group['innerBlocks'] ?? array()) === count($margins) + 1, "{$label}: .{$class} has " . ( count($margins) + 1 ) . ' children', (string) count($group['innerBlocks'] ?? array()));
        $got = $applied($group);
        $assert($got === $margins, "{$label}: .{$class} children get the source spacing", json_encode($margins) . ' got ' . json_encode($got));
        $assert(isset($group['attrs']['style']['spacing']['blockGap']), "{$label}: .{$class} carries its own blockGap", json_encode($group['attrs']));
    }
    foreach ( $spacingValues($blocks) as $value ) {
        $assert(is_string($value) && 1 === preg_match('/^(?:0|-?\d*\.?\d+(?:px|rem|em))$/', $value), "{$label}: spacing value is a length or 0", var_export($value, true));
    }
    $words = preg_split('/\s+/', trim(strip_tags(str_replace('><', '> <', $html)))) ?: array();
    $assert(array() === array_filter($words, static fn (string $word): bool => ! str_contains($serialized, $word)), "{$label}: every source word is kept");
    $assert(
        ( $result['status'] ?? '' ) === ( $transform($html, $css, null)['status'] ?? '' ),
        "{$label}: the option does not change the conversion status"
    );

    // Without the option nothing depends on it.
    $assert(
        $transform($html, $css, null)['serialized_blocks'] === ( new HtmlTransformer() )->transform('<main>' . $html . '</main>', array( 'static_css' => $css, 'host_block_gap' => null ))->toArray()['serialized_blocks'],
        "{$label}: a null host_block_gap is the same as none"
    );
}

// Uneven spacing: the most common value is the group gap and only the
// differing children carry a margin of their own.
$uneven = $find($transform($cases['uneven margins: user-agent heading and paragraph margins collapse, an author margin differs'][1], $cases['uneven margins: user-agent heading and paragraph margins collapse, an author margin differs'][0], $host)['blocks'], 'uneven');
$ownMargins = array_map(static fn (array $child): mixed => $child['attrs']['style']['spacing']['margin']['top'] ?? null, array_values($uneven['innerBlocks'] ?? array()));
$assert(null === $ownMargins[1] && '16px' === $ownMargins[2], 'uneven: only the child that differs from the group gap carries a margin of its own', json_encode($ownMargins));

// Padding keeps the first child's source margin inside the group; core zeroes
// it unless the block carries it.
$assert($px($ownMargins[0] ?? null) === 19.92, 'uneven: the first child keeps its start margin inside a padded group', json_encode($ownMargins));

// A wrapper with a border keeps its last child's end margin, which core zeroes.
$boxed = $find($transform($cases['collapse through: a wrapper without border passes its child margins to its siblings'][1], $cases['collapse through: a wrapper without border passes its child margins to its siblings'][0], $host)['blocks'], 'boxed');
$lastBoxed = array_values($boxed['innerBlocks'] ?? array())[1] ?? array();
$assert('16px' === ( $lastBoxed['attrs']['style']['spacing']['margin']['bottom'] ?? null ), 'collapse through: the bordered wrapper\'s last child keeps its end margin', json_encode($lastBoxed['attrs'] ?? null));
$assert(str_contains((string) ( $lastBoxed['innerHTML'] ?? '' ), 'margin-bottom:16px'), 'collapse through: the saved markup carries the end margin', (string) ( $lastBoxed['innerHTML'] ?? '' ));

// A native grid group spaces its items with the source gap, not the host's.
$grid = $transform('<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px 10px"><h3>A</h3><p>B</p><div>C</div></div>', '', $host)['blocks'];
$gridGroup = $grid[0]['innerBlocks'][0] ?? $grid[0] ?? array();
if ( 'grid' !== ( $gridGroup['attrs']['layout']['type'] ?? null ) ) {
    $gridGroup = $grid[0] ?? array();
}
$assert('grid' === ( $gridGroup['attrs']['layout']['type'] ?? null ), 'native grid: the group has a grid layout', json_encode($gridGroup['attrs'] ?? null));
$assert(array( 'top' => '20px', 'left' => '10px' ) === ( $gridGroup['attrs']['style']['spacing']['blockGap'] ?? null ), 'native grid: blockGap is the source row and column gap', json_encode($gridGroup['attrs'] ?? null));

if ( 0 < $failures ) {
    fwrite(STDERR, "host block gap FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "host block gap passed: {$passes} assertions\n";

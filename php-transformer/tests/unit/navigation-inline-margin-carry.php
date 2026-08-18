<?php
declare(strict_types=1);

/**
 * Contract for carrying an authored inline-axis `auto` margin onto the promoted
 * navigation host.
 *
 * A menu authored as `.navlinks{margin:0 0 0 auto}` inside `nav{display:flex}`
 * sits at the far end of its landmark. The class survives onto the navigation
 * block and the declaration reaches the page — the rendered menu still shows the
 * authored `gap` and `padding` from the same rule set — but core's own
 * navigation stylesheet owns the inner list. `.wp-block-navigation ul{margin-left:0}`
 * is specificity 0,1,1 and outranks the authored `.navlinks` at 0,1,0, so the
 * menu snapped back to the start of the landmark.
 *
 * The block host is the flex item that actually moves, so the authored margin is
 * restated there after author CSS. The rule must stay narrow: only an `auto`
 * margin positions a flex item, and only a class that genuinely sits on a
 * navigation host may produce one — a page wrapper's `.wrap{margin:0 auto}` is
 * not a statement about a menu.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    global $failures, $passes;
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/** @return array{after: string, all: string} */
$stylesheets = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $after = '';
    $all = '';
    foreach ( (is_array($result['assets'] ?? null) ? $result['assets'] : array()) as $asset ) {
        if ( ! is_array($asset) ) {
            continue;
        }

        $content = (string) ($asset['content'] ?? '');
        $all .= $content;
        if ( 'after-author' === (string) ($asset['stylesheet_placement'] ?? '') ) {
            $after .= $content;
        }
    }

    return array( 'after' => $after, 'all' => $all );
};

$list = '<ul class="navlinks"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li></ul>';

// -- An authored end-aligned menu keeps its alignment on the block host.
$endAligned = $stylesheets(
    '<style>nav{display:flex;gap:20px}'
    . '.navlinks{list-style:none;display:flex;gap:16px;padding:0}'
    . '.navlinks{margin:0 0 0 auto}</style>'
    . '<nav aria-label="Primary"><a class="mark" href="/"><span>Harbor</span><span>Pilots</span></a>' . $list . '</nav>'
);
$assert(
    str_contains($endAligned['after'], '.wp-block-navigation.blocks-engine-list-navigation.navlinks{margin-left:auto!important'),
    'an authored auto inline margin is restated on the navigation host after author CSS',
    substr($endAligned['after'], 0, 260)
);
$assert(
    str_contains($endAligned['after'], 'margin-right:0!important'),
    'the opposite side rides along so a one-sided auto cannot read as centring',
    substr($endAligned['after'], 0, 260)
);

// -- A page wrapper that centres itself is not a statement about a menu.
$wrapper = $stylesheets(
    '<style>nav{display:flex;gap:20px}.wrap{max-width:60rem;margin:0 auto}'
    . '.navlinks{list-style:none;display:flex;gap:16px;margin:0;padding:0}</style>'
    . '<div class="wrap"><nav aria-label="Primary">' . $list . '</nav></div>'
);
$assert(
    ! str_contains($wrapper['all'], 'blocks-engine-list-navigation.wrap'),
    'a page wrapper class never produces a navigation margin rule',
    substr($wrapper['all'], -260)
);

// -- Only `auto` is carried: an authored length is left to the author rule.
$explicitLength = $stylesheets(
    '<style>nav{display:flex;gap:20px}'
    . '.navlinks{list-style:none;display:flex;gap:16px;padding:0;margin-left:24px}</style>'
    . '<nav aria-label="Primary">' . $list . '</nav>'
);
$assert(
    ! str_contains($explicitLength['all'], 'blocks-engine-list-navigation.navlinks{margin'),
    'an authored length margin is not restated, only auto is',
    substr($explicitLength['all'], -260)
);

// -- A menu with no authored inline margin emits no rule at all.
$plain = $stylesheets(
    '<style>nav{display:flex;gap:20px}'
    . '.navlinks{list-style:none;display:flex;gap:16px;margin:0;padding:0}</style>'
    . '<nav aria-label="Primary">' . $list . '</nav>'
);
$assert(
    ! str_contains($plain['all'], 'blocks-engine-list-navigation.navlinks{margin'),
    'a menu with no authored inline margin emits no margin rule',
    substr($plain['all'], -260)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation inline margin carry contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation inline margin carry contract passed: {$passes} assertions\n";

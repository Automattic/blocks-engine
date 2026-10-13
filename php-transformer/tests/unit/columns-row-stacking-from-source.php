<?php
declare(strict_types=1);

/**
 * core/columns stacks its columns below 782px unless `isStackedOnMobile` is
 * false. A source flex row decides that from its own cascade: a horizontal,
 * non-wrapping flex container that is still one at the mobile reference
 * viewport never stacks, so its columns must not stack either. A row whose
 * author CSS turns it into a column, a wrapping row or non-flex flow at that
 * viewport keeps core's stacking.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CanonicalSaveShapeValidator;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$unit = static fn (string $value, string $label): string => '<div style="min-width:60px;display:flex;align-items:center;justify-content:center"><div style="display:flex;flex-direction:column;align-items:center;gap:4px"><span style="font-size:48px;line-height:1.2em">' . $value . '</span><span style="font-size:14px;line-height:1.2em">' . $label . '</span></div></div>';
$units = $unit('14', 'days') . $unit('06', 'hours') . $unit('32', 'minutes') . $unit('09', 'seconds');
// A flex row deep in a generated page is a repeatable component candidate and
// reaches the columns recognizer; shallow author flex rows stay CSS-owned groups.
$nest = static function (string $html): string {
    for ( $level = 0; $level < 13; ++$level ) {
        $html = '<div class="level-' . $level . '">' . $html . '</div>';
    }

    return $html;
};
$row = static fn (string $style): string => $nest('<div class="counter"><div class="counter-row" style="' . $style . '">' . $units . '</div></div>');
$inlineRow = 'display:flex;flex-direction:row;justify-content:center;align-items:center;gap:24px;width:100%;height:100%';

/**
 * @return array{blocks: array<int, array<string, mixed>>, serialized: string}
 */
$transform = static function (string $html, string $css = ''): array {
    $result = ( new HtmlTransformer() )->transform($html, '' === $css ? array() : array( 'static_css' => $css ))->toArray();

    return array( 'blocks' => $result['blocks'] ?? array(), 'serialized' => (string) ($result['serialized_blocks'] ?? '') );
};

/** @return array<string, mixed>|null */
$findColumns = static function (array $blocks) use (&$findColumns): ?array {
    foreach ( $blocks as $block ) {
        if ( 'core/columns' === ($block['blockName'] ?? null) ) {
            return $block;
        }
        $nested = $findColumns($block['innerBlocks'] ?? array());
        if ( null !== $nested ) {
            return $nested;
        }
    }

    return null;
};

// A row that stays one row at narrow widths does not stack.
$singleRow = array(
    'inline flex row' => array( $row($inlineRow) ),
    'inline flex row without a direction' => array( $row('display:flex;gap:24px') ),
    'inline flex row with an explicit nowrap flow' => array( $row('display:flex;flex-flow:row nowrap;gap:24px') ),
    'inline flex row whose narrow-width rule only changes its gap' => array( $row($inlineRow), '@media (max-width: 809px){.counter-row{gap:8px}}' ),
    'inline flex row turned into a column only at wide widths' => array( $row($inlineRow), '@media (min-width: 1200px){.counter-row{flex-direction:column}}' ),
);

foreach ( $singleRow as $label => $case ) {
    $out = $transform($case[0], $case[1] ?? '');
    $columns = $findColumns($out['blocks']);
    $assert(null !== $columns, $label . ': the row is core/columns', substr($out['serialized'], 0, 300));
    $assert(false === ($columns['attrs']['isStackedOnMobile'] ?? null), $label . ': isStackedOnMobile is false', (string) json_encode($columns["attrs"] ?? array()));
    $assert(str_contains($out['serialized'], 'wp-block-columns is-not-stacked-on-mobile'), $label . ': saved markup carries is-not-stacked-on-mobile');
    $assert(array() === ( new CanonicalSaveShapeValidator() )->findings($out['blocks']), $label . ': canonical save shape holds');
}

// A row the source stacks, wraps or leaves at narrow widths keeps core stacking.
$stacks = array(
    'row that becomes a column below a breakpoint' => array( $row('display:flex;gap:24px'), '@media (max-width: 809px){.counter-row{flex-direction:column}}' ),
    'row that becomes a column through flex-flow' => array( $row('display:flex;gap:24px'), '.counter-row{flex-flow:row nowrap}@media (max-width: 809px){.counter-row{flex-flow:column}}' ),
    'row whose important narrow-width rule overrides the inline row' => array( $row($inlineRow), '@media (max-width: 809px){.counter-row{flex-direction:column !important}}' ),
    'row that becomes block flow below a breakpoint' => array( $row('display:flex;gap:24px'), '@media (max-width: 809px){.counter-row{display:block !important}}' ),
    'wrapping row' => array( $row('display:flex;flex-wrap:wrap;gap:24px') ),
    'column-named container with no flex display' => array( '<div class="columns"><div><p>One</p></div><div><p>Two</p></div></div>' ),
);

foreach ( $stacks as $label => $case ) {
    $out = $transform($case[0], $case[1] ?? '');
    $columns = $findColumns($out['blocks']);
    $assert(null !== $columns, $label . ': the row is core/columns', substr($out['serialized'], 0, 300));
    $assert(! array_key_exists('isStackedOnMobile', $columns['attrs'] ?? array()), $label . ': core stacking is kept', (string) json_encode($columns["attrs"] ?? array()));
    $assert(! str_contains($out['serialized'], 'is-not-stacked-on-mobile'), $label . ': saved markup stays stacked');
}

if ( 0 < $failures ) {
    fwrite(STDERR, "Columns row stacking from source contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Columns row stacking from source contract passed: {$passes} assertions\n");

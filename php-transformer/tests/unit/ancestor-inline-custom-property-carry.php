<?php
declare(strict_types=1);

/**
 * An inline custom property is only carried when the engine can see a rule that
 * READS it. That reader search ran over the `safeVisualDeclarations()`
 * classification allow-list, which has no `opacity`, `transform`, `filter` or
 * `transition`. A definition read only through one of those was judged unused
 * and dropped, so the reader became invalid at computed-value time and fell
 * back to the property's initial value — a `opacity:var(--overlay-opacity)`
 * hover veil painted at opacity 1 over the photograph it was meant to reveal.
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

$transform = static function (string $html): array {
    $out = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array( 'blocks' => (string) ( $out['serialized_blocks'] ?? '' ), 'css' => $css );
};

/** The generated carrier rule that declares `$property`, if any. */
$carrierRuleFor = static function (string $css, string $property): string {
    foreach ( preg_split('/(?<=})/', $css) ?: array() as $rule ) {
        if ( str_contains($rule, '.be-inline-geometry-') && str_contains($rule, $property . ':') ) {
            return trim($rule);
        }
    }

    return '';
};

$carrierClassOf = static function (string $rule): string {
    return preg_match('/\.(be-inline-geometry-[a-f0-9-]+)/', $rule, $match) ? $match[1] : '';
};

$veilStyles = '<style>'
    . '.frame{position:relative;display:block}'
    . '.frame__veil{position:absolute;inset:0;opacity:var(--veil-opacity);background-color:var(--veil-color)}'
    . '</style>';

// 1. An ancestor defines the veil tokens inline; a descendant rule reads one
//    through `opacity` and the other through `background-color`.
$descendantReader = $transform(
    $veilStyles
    . '<div class="frame" style="--veil-color:#112233;--veil-opacity:0">'
    . '<div class="frame__veil"></div><p>Caption</p>'
    . '</div>'
);
$descendantCarrier = $carrierRuleFor($descendantReader['css'], '--veil-color');

$assert('' !== $descendantCarrier, '1: the declaring ancestor keeps a carrier for its inline custom properties', $descendantReader['css']);
$assert(
    str_contains($descendantCarrier, '--veil-color:#112233'),
    '2: the colour token a descendant reads through background-color survives',
    $descendantCarrier
);
$assert(
    str_contains($descendantCarrier, '--veil-opacity:0'),
    '3: the opacity token the same descendant rule reads survives',
    $descendantCarrier
);
$assert(
    str_contains($descendantReader['blocks'], 'frame__veil'),
    '4: the veil the tokens style is still in the tree',
    $descendantReader['blocks']
);

// 2. The reader is on the declaring element itself, and `opacity` is its only
//    declaration, so the whole rule sits outside the classification allow-list.
$selfReader = $transform(
    '<style>.fade{opacity:var(--fade-opacity)}</style>'
    . '<div class="fade" style="--fade-opacity:0.25"><p>Muted</p></div>'
);
$assert(
    str_contains($carrierRuleFor($selfReader['css'], '--fade-opacity'), '--fade-opacity:0.25'),
    '5: an element that only reads its own inline token through opacity keeps it',
    $selfReader['css']
);

// 3. The declaring element is a bare wrapper the conversion is free to merge
//    away. Custom properties inherit, so the definitions have to land on an
//    element that is still an ancestor of the veil that reads them.
$mergedHost = $transform(
    $veilStyles
    . '<section><div class="outer" style="--veil-color:#445566;--veil-opacity:0">'
    . '<div class="frame"><div class="frame__veil"></div><p>Caption</p></div>'
    . '</div></section>'
);
$mergedCarrier = $carrierRuleFor($mergedHost['css'], '--veil-color');
$mergedClass = $carrierClassOf($mergedCarrier);

$assert(
    str_contains($mergedCarrier, '--veil-color:#445566') && str_contains($mergedCarrier, '--veil-opacity:0'),
    '6: a merge candidate still carries both tokens it defines',
    $mergedHost['css']
);
$carrierOffset = '' === $mergedClass ? false : strpos($mergedHost['blocks'], $mergedClass);
$veilOffset = strpos($mergedHost['blocks'], 'frame__veil');
$assert(
    false !== $carrierOffset && false !== $veilOffset && $carrierOffset < $veilOffset,
    '7: the carrier lands on an element that still encloses the reader',
    $mergedHost['blocks']
);

if ( 0 !== $failures ) {
    fwrite(STDERR, sprintf('ancestor-inline-custom-property-carry: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

echo sprintf('ancestor-inline-custom-property-carry: %d assertions passed%s', $passes, PHP_EOL);

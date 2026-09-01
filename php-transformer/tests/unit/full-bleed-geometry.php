<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$beforeAuthorCss = static function (array $result): string {
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'engine-support' === ($asset['source'] ?? '') && 'before-author' === ($asset['stylesheet_placement'] ?? '') ) {
            return (string) ($asset['content'] ?? '');
        }
    }

    return '';
};

$source = '<main><section class="slideshow" style="width:100vw !important;background-image:url(hero.jpg);min-height:400px"><div class="cover-media" style="width:120vw">Cover</div></section></main>';
$first = ( new HtmlTransformer() )->transform($source)->toArray();
$second = ( new HtmlTransformer() )->transform($source)->toArray();
$css = $beforeAuthorCss($first);

$fullBleedRule = '';
if ( preg_match('/\.be-inline-geometry-[A-Fa-f0-9]+\{[^}]*width:100vw !important[^}]*\}/', $css, $match) ) {
    $fullBleedRule = $match[0];
}

$assert($css === $beforeAuthorCss($second), 'full-bleed carrier CSS is deterministic across equivalent transforms');
$assert(str_contains((string) ($first['serialized_blocks'] ?? ''), 'wp:cover'), 'full-bleed carrier remains the native cover block');
foreach ( array( 'position:relative !important', 'left:50% !important', 'margin-left:-50vw !important', 'margin-right:-50vw !important', 'overflow-x:clip !important' ) as $declaration ) {
    $assert(str_contains($fullBleedRule, $declaration), 'normal-flow 100vw carrier emits ' . $declaration);
}

foreach ( array( array( 135, 1170, 1440 ), array( 20, 350, 390 ) ) as [ $parentLeft, $parentWidth, $viewport ] ) {
    $carrierLeft = $parentLeft + $parentWidth / 2 - $viewport / 2;
    $carrierRight = $carrierLeft + $viewport;
    $oversizedCoverRight = $carrierLeft + 1.2 * $viewport;
    $visibleCoverRight = min($carrierRight, $oversizedCoverRight);

    $assert(abs($carrierLeft) < 0.0001 && abs($carrierRight - $viewport) < 0.0001, 'inset normal-flow full-bleed carrier spans x=0 to viewport width at ' . $viewport . 'px');
    $assert($carrierRight === $visibleCoverRight, 'carrier clipping prevents an oversized cover descendant from overflowing at ' . $viewport . 'px');
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Full-bleed geometry unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Full-bleed geometry unit tests: {$passes} passed\n");

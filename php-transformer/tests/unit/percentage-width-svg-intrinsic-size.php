<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// An inline SVG sized by a percentage width (`width="100%"` or
// `style="width:100%"`) and an auto height renders full-width in flow, at its
// viewBox ratio. Its materialized standalone file is an image document: a
// percentage width there gives the image no intrinsic size, so a shrink-to-fit
// figure around `img{width:100%}` resolves against itself and both collapse to
// 0x0. The materialized file carries its viewBox size as numeric intrinsic
// dimensions instead; the page CSS percentage still controls rendered width.
// An SVG with absolute dimensions keeps them.
$path = '<path d="M0 0L400 1600" stroke="#000" stroke-width="20"/>';
$cases = array(
    'attribute percentage width' => array(
        'svg'       => '<svg width="100%" height="auto" viewBox="0 0 400 1600">' . $path . '</svg>',
        'intrinsic' => array( '400', '1600' ),
        'percent'   => true,
    ),
    'style percentage width' => array(
        'svg'       => '<svg viewBox="0 0 1440 370" style="display:block;width:100%;height:auto">' . $path . '</svg>',
        'intrinsic' => array( '1440', '370' ),
        'percent'   => true,
    ),
    'absolute width is kept' => array(
        'svg'       => '<svg width="120" height="90" viewBox="0 0 400 300">' . $path . '</svg>',
        'intrinsic' => array( '120', '90' ),
        'percent'   => false,
    ),
);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

foreach ( $cases as $name => $case ) {
    $html = '<!doctype html><html><body><main style="width:390px"><div>' . $case['svg'] . '</div><p>After</p></main></body></html>';
    $compiled = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray();
    $plan = $compiled['source_reports']['wordpress_site_plan'] ?? array();
    $page = (string) (current($plan['pages'] ?? array())['canonical_block_markup'] ?? '');

    $svgAsset = '';
    $css = '';
    foreach ( $compiled['assets'] ?? array() as $asset ) {
        if ( 'inline-svg' === ($asset['source'] ?? '') ) {
            $svgAsset = (string) ($asset['content'] ?? '');
        } elseif ( 'engine-support' === ($asset['source'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    $assert('' !== $svgAsset, "$name: the inline SVG is materialized as a standalone asset.");
    $assert(str_contains($page, 'blocks-engine-synthetic-image-figure'), "$name: the SVG becomes a synthetic image figure.\n$page");

    $assert(1 === preg_match('/<svg\b[^>]*>/i', $svgAsset, $root), "$name: the asset has an svg root.");
    $rootTag = $root[0];
    $width = preg_match('/\swidth\s*=\s*"([^"]*)"/i', $rootTag, $m) ? $m[1] : '';
    $height = preg_match('/\sheight\s*=\s*"([^"]*)"/i', $rootTag, $m) ? $m[1] : '';
    $assert(
        array( $width, $height ) === $case['intrinsic'],
        "$name: the materialized SVG carries numeric intrinsic width/height " . implode('x', $case['intrinsic']) . ": $rootTag"
    );
    $rootStyle = preg_match('/\sstyle\s*=\s*"([^"]*)"/i', $rootTag, $m) ? $m[1] : '';
    $assert(
        1 !== preg_match('/(?:^|;)\s*(?:width|height)\s*:\s*(?:auto|[\d.]+%)/i', $rootStyle),
        "$name: no relative root style overrides the intrinsic size in the standalone document: $rootTag"
    );

    if ( $case['percent'] ) {
        // The rendered width stays the source percentage on the page.
        $class = preg_match('/<figure class="[^"]*\b(be-inline-geometry-[a-f0-9]{16})\b/', $page, $m) ? $m[1] : '';
        $carried = '' !== $class && 1 === preg_match('/\.wp-block-image\.' . $class . '>img[^{]*\{[^}]*(?<![\w-])width:100%/', $css);
        $assert(
            str_contains($page, 'style="width:100%"') || $carried,
            "$name: the image keeps the source percentage width on the page.\n$page\n$css"
        );
    }
}

echo "Percentage-width SVG intrinsic size unit test passed ({$assertions} assertions).\n";

<?php
declare(strict_types=1);

/**
 * A bare <img> is inline content, so its parent's text-align decides where it
 * sits. Wrapping it in a synthesized <figure> replaces that inline box with a
 * block box that fills the line, and the alignment has nothing left to move.
 *
 * Only an alignment that acts on inline content matters here: a block image is
 * positioned by its own box, and shrink-wrapping it would strip the width its
 * auto margins resolve against.
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

$transform = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }

    return array( (string) ($result['serialized_blocks'] ?? ''), $css );
};

$inlineClass = 'blocks-engine-synthetic-image-figure-inline';

// Right-aligned inline image, with the anchor wrapper the source usually has.
list( $right, $rightCss ) = $transform('<main><div style="text-align:right"><a><img src="/a.jpg" alt="P"></a></div></main>');
$assert(
    str_contains($right, $inlineClass),
    'a right-aligned inline image keeps an inline-level figure so the alignment still moves it',
    $right
);
$assert(
    str_contains($rightCss, '.' . $inlineClass . '{display:inline-block}'),
    'the inline-level synthetic figure ships its display rule',
    $rightCss
);

// Centered inline image.
list( $center, ) = $transform('<main><div style="text-align:center"><img src="/a.jpg" alt="P"></div></main>');
$assert(
    str_contains($center, $inlineClass),
    'a centered inline image keeps an inline-level figure',
    $center
);

// Left alignment is what a block figure already does, so nothing changes.
list( $left, ) = $transform('<main><div style="text-align:left"><a><img src="/a.jpg" alt="P"></a></div></main>');
$assert(
    ! str_contains($left, $inlineClass),
    'a left-aligned image is left as an ordinary block figure',
    $left
);

// A block image is positioned by its own box; shrink-wrapping it would break
// the width its auto margins resolve against.
list( $blockImage, ) = $transform('<style>img{display:block;margin:0 auto}</style><main><div style="text-align:center"><img src="/a.jpg" alt="P"></div></main>');
$assert(
    ! str_contains($blockImage, $inlineClass),
    'a block image centered by auto margins keeps its full-width figure',
    $blockImage
);

// An authored <figure> is not synthesized at all.
list( $authored, ) = $transform('<main><figure style="text-align:right"><img src="/a.jpg" alt="P"></figure></main>');
$assert(
    ! str_contains($authored, 'blocks-engine-synthetic-image-figure'),
    'an authored figure is never marked synthetic',
    $authored
);

if ( 0 < $failures ) {
    fwrite(STDERR, "synthetic image figure inline flow FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "synthetic image figure inline flow passed: {$passes} assertions\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? "\n" . $detail : '' ) . PHP_EOL);
};

$cssOf = static function (array $out): string {
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }
    return $css;
};

$out = ( new HtmlTransformer() )->transform(
    '<style>.wrap{width:302px}.sqs-block-button-element{display:flex;height:78px;padding:0 20.8px;box-sizing:border-box;align-items:center;justify-content:center;background:#fff;color:#111}</style>'
    . '<main><div class="wrap sqs-stretched"><a class="sqs-block-button-element sqs-button-element--primary" href="/order">Order Now</a></div></main>'
)->toArray();
$css = $cssOf($out);
$markup = (string) ( $out['serialized_blocks'] ?? '' );

$assert(str_contains($markup, 'wp:button') && str_contains($markup, 'Order Now'), 'stretched CTA becomes core/button', $markup);
$assert(
    ! preg_match('/wp-block-button__link\{[^}]*width:max-content/', $css)
        && ! preg_match('/wp-block-buttons\{[^}]*width:max-content/', $css),
    'definite ancestor width must not shrink the button with max-content',
    $css
);
$assert(
    str_contains($css, 'width:302px') && str_contains($css, 'padding:0 20.8px'),
    'stretched CTA keeps the ancestor box and source padding',
    $css
);

// A sized ancestor only fills controls that span its line. Inline-level
// buttons in inline-block list items keep their content width.
$out = ( new HtmlTransformer() )->transform(
    '<style>.circle{display:flex;flex-direction:column;width:320px}.amounts{padding:0;list-style:none}.amounts li{display:inline-block}.btn{display:inline-block;min-width:80px;padding:8px 24px;border:2px solid #484848;border-radius:40px;background:transparent}</style>'
    . '<main><div class="circle"><ul class="amounts"><li><button class="btn" type="button">50</button></li><li><button class="btn" type="button">100</button></li></ul></div></main>'
)->toArray();
$css = $cssOf($out);
$assert(! preg_match('/wp-block-buttons\{width:320px/', $css), 'inline-level buttons do not take a sized ancestor width', $css);

// A flex card ends the block-flow walk even when its class is a generated
// CSS-in-JS name, so a product card button keeps its card instead of taking
// the carousel track width beyond it.
$out = ( new HtmlTransformer() )->transform(
    '<style>.css-1k0woj{display:flex;flex-direction:column;align-items:center}.cta{margin-top:16px}.cta a{display:block;padding:10px 20px;background:#444;color:#fff}</style>'
    . '<main><div style="width:1059px;display:flex"><div style="flex:1 1 0%;width:33.3333%"><div class="css-1k0woj"><div class="cta"><a href="/cart?add=1">Add to Cart</a></div></div></div></div></main>'
)->toArray();
$css = $cssOf($out);
$assert(! preg_match('/wp-block-buttons\{width:1059px/', $css), 'a flex card button does not take the track width beyond its card', $css);

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "button definite ancestor width tests: passed\n");

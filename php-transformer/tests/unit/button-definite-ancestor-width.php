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

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "button definite ancestor width tests: passed\n");

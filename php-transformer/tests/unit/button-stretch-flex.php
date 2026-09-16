<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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
    '<style>.sqs-stretched .sqs-block-button-element{align-items:center;box-sizing:border-box;display:flex;flex:1;height:100%;justify-content:center;padding:0 20.8px;background:#fff;color:#111}</style>'
    . '<main><div class="sqs-stretched"><a class="sqs-block-button-element sqs-block-button-element--medium sqs-button-element--primary" href="/order">Order Now</a></div></main>'
)->toArray();
$css = $cssOf($out);
$markup = (string) ( $out['serialized_blocks'] ?? '' );
if ( ! str_contains($markup, 'wp:button') || ! str_contains($markup, 'Order Now') ) {
    fwrite(STDERR, "FAIL: stretched CTA must remain a button\n" . $markup . "\n");
    exit(1);
}
if ( preg_match('/wp-block-button__link\{[^}]*width:max-content/', $css) ) {
    fwrite(STDERR, "FAIL: stretching flex CTA must not shrink with max-content\n" . $css . "\n");
    exit(1);
}

fwrite(STDOUT, "button stretch flex tests: passed\n");

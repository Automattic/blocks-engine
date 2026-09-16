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

$idTheme = ( new HtmlTransformer() )->transform(
    '<style>'
    . '#siteWrapper.site-wrapper .sqs-button-element--primary,'
    . '.sqs-modal-lightbox .sqs-button-element--primary{padding-top:1rem;padding-bottom:1rem;padding-left:1.3rem;padding-right:1.3rem;background:#4c2929;color:#fff}'
    . '.fluid-engine .sqs-block-button.sqs-stretched .sqs-block-button-element{padding-top:0!important;padding-bottom:0!important;height:100%;display:flex;flex:1;align-items:center;justify-content:center}'
    . '</style>'
    . '<div id="siteWrapper" class="site-wrapper"><div class="fluid-engine"><div class="sqs-block-button sqs-stretched">'
    . '<a class="sqs-block-button-element sqs-button-element--primary" href="/order">Order Now</a>'
    . '</div></div></div>'
)->toArray();
$idThemeCss = $cssOf($idTheme);
if ( ! preg_match('/padding-top:0!important/', $idThemeCss) ) {
    fwrite(STDERR, "FAIL: stretched zero padding must survive an ID-themed button rule\n" . $idThemeCss . "\n");
    exit(1);
}
if ( preg_match('/#siteWrapper[^\{]*\{[^}]*padding-top:1rem!important/', $idThemeCss) ) {
    fwrite(STDERR, "FAIL: ID-themed button padding must keep source importance\n" . $idThemeCss . "\n");
    exit(1);
}
if ( str_contains($idThemeCss, '!important!important') ) {
    fwrite(STDERR, "FAIL: native button padding must not double !important\n" . $idThemeCss . "\n");
    exit(1);
}

$compete = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.cta{height:auto;display:inline-block;padding:12px 24px;background:#4c2929;color:#fff}'
    . '.fluid-engine .sqs-stretched .cta{height:100%;display:flex;flex:1;padding-top:0;padding-bottom:0;align-items:center;justify-content:center}'
    . '</style>'
    . '<div class="fluid-engine"><div class="sqs-stretched" style="height:78px"><a class="cta" href="/order">Order Now</a></div></div>'
)->toArray();
$competeCss = $cssOf($compete);
if ( ! preg_match('/height:100%!important/', $competeCss) ) {
    fwrite(STDERR, "FAIL: stretching height:100% must still fill inner carriers\n" . $competeCss . "\n");
    exit(1);
}
if ( preg_match('/wp-block-button__link\)\{height:auto!important/', $competeCss) ) {
    fwrite(STDERR, "FAIL: unconditioned height:auto must not force inner carriers to auto\n" . $competeCss . "\n");
    exit(1);
}

fwrite(STDOUT, "button stretch flex tests: passed\n");

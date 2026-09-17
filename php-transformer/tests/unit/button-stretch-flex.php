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

// Flex sizing is item participation: it describes how the box behaves inside
// the author's flex container. The core/buttons wrapper is the box standing in
// the source element's place there, so the declaration has to land on the
// wrapper. Left on the inner link it is inert, and the control sizes against
// the author's intent — a `shrink-0` pill grew to fill its row and squeezed the
// heading beside it onto an extra line.
//
// Assert the selector, not the declaration: the declaration is emitted either
// way, and only the target box distinguishes the bug from the fix.
$participation = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.row{display:flex;flex-direction:row;gap:24px;justify-content:space-between;align-items:flex-end}'
    . '.pill{flex-shrink:0;border-radius:9999px;background:#1b2a3a;color:#fff;padding:8px 16px;display:inline-block}'
    . '</style>'
    . '<div class="row"><div><h2>Experience, skills &amp; education.</h2></div>'
    . '<a href="/cv" class="pill">Download full CV</a></div>'
)->toArray();
$participationCss = $cssOf($participation);

$shrinkOnWrapper = false;
$shrinkOnLink = false;
foreach ( preg_split('/(?<=\})/', $participationCss) ?: array() as $rule ) {
    if ( ! str_contains($rule, 'flex-shrink') ) {
        continue;
    }
    $selector = substr($rule, 0, (int) strpos($rule, '{'));
    if ( str_contains($selector, 'wp-block-button__link') ) {
        $shrinkOnLink = true;
    } elseif ( str_contains($selector, 'wp-block-buttons') ) {
        $shrinkOnWrapper = true;
    }
}
if ( ! $shrinkOnWrapper ) {
    fwrite(STDERR, "FAIL: authored flex sizing must land on the core/buttons wrapper that participates in the author's row\n" . $participationCss . "\n");
    exit(1);
}
if ( $shrinkOnLink ) {
    fwrite(STDERR, "FAIL: authored flex sizing must not be stranded on the inner link, where it is inert\n" . $participationCss . "\n");
    exit(1);
}

// Which axis the parent lays out on has to be read at the reference viewport.
// A `flex-col md:flex-row` container is a column only below the breakpoint; at
// the width being rendered it is a row, and its children are not cross-axis
// stretched. Reading the resting value sees the mobile column and pins a
// content-sized control to the full row.
$responsiveRow = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.row{display:flex;flex-direction:column;gap:24px}'
    . '@media (min-width:768px){.row{flex-direction:row;align-items:flex-end;justify-content:space-between}}'
    . '.pill{border-radius:9999px;background:#1b2a3a;color:#fff;padding:8px 16px;display:inline-block}'
    . '</style>'
    . '<div class="row"><div><h2>Experience</h2></div><a href="/cv" class="pill">Download full CV</a></div>'
)->toArray();
if ( str_contains($cssOf($responsiveRow), 'width:100%!important') ) {
    fwrite(STDERR, "FAIL: a control in a row-at-desktop container must not be pinned to the column stretch width\n" . $cssOf($responsiveRow) . "\n");
    exit(1);
}

// A genuine column still stretches its children, which is why the branch exists.
$trueColumn = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.col{display:flex;flex-direction:column;gap:24px}'
    . '.pill{border-radius:9999px;background:#1b2a3a;color:#fff;padding:8px 16px;display:inline-block}'
    . '</style>'
    . '<div class="col"><div><h2>Experience</h2></div><a href="/cv" class="pill">Download full CV</a></div>'
)->toArray();
if ( ! str_contains($cssOf($trueColumn), 'width:100%!important') ) {
    fwrite(STDERR, "FAIL: a control in a real flex column must still fill the cross axis\n" . $cssOf($trueColumn) . "\n");
    exit(1);
}

// A control with no authored flex participation must gain none.
$plain = ( new HtmlTransformer() )->transform(
    '<style>.plain{border-radius:9999px;background:#1b2a3a;color:#fff;padding:8px 16px;display:inline-block}</style>'
    . '<div><a href="/cv" class="plain">Download full CV</a></div>'
)->toArray();
if ( str_contains($cssOf($plain), 'flex-shrink') ) {
    fwrite(STDERR, "FAIL: a control without authored flex sizing must not gain any\n" . $cssOf($plain) . "\n");
    exit(1);
}

fwrite(STDOUT, "button stretch flex tests: passed\n");

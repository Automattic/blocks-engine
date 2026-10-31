<?php
declare(strict_types=1);

/**
 * A custom property defined only under one media condition does not resolve
 * under another, non-overlapping condition (#2553): the reader falls back to
 * its own var() fallback, as a browser does.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<div class="p"><svg viewBox="0 0 10 10" width="10" height="10"><rect width="10" height="10" fill="red"/></svg></div>';
foreach ( array(
    'mobile token, desktop reader' => array('@media (min-width:768px){.p svg{width:var(--w,100%)}}@media (max-width:767px){.p{--w:40px}}', '(min-width:768px)'),
    'desktop token, mobile reader' => array('@media (max-width:767px){.p svg{width:var(--w,100%)}}@media (min-width:768px){.p{--w:40px}}', '(max-width:767px)'),
) as $case => [$css, $readerCondition] ) {
    $result = (new HtmlTransformer())->transform('<style>' . $css . '</style>' . $html)->toArray();
    $rules = stripslashes(json_encode($result, JSON_UNESCAPED_SLASHES));
    if ( ! preg_match('/@media ' . preg_quote($readerCondition, '/') . '\{\.be-inline-geometry-[0-9a-f]+[^{]*\{([^}]*)\}/', $rules, $carrier) ) {
        fwrite(STDERR, "FAIL: {$case}: no carried reader rule under {$readerCondition}\n");
        exit(1);
    }
    if ( str_contains($carrier[1], '--w:') ) {
        fwrite(STDERR, "FAIL: {$case}: another condition's token leaked into {$readerCondition}: {$carrier[1]}\n");
        exit(1);
    }
}
echo "conditioned custom property scope passed\n";

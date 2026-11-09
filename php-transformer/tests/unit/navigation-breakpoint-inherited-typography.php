<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;

// Issue #5254: links inherit a size the nav changes at a breakpoint. The
// per-instance link rule must not pin the desktop size at every width.
$html = <<<'HTML'
<!doctype html><html><head><style>
header{display:flex;justify-content:space-between;align-items:center;padding:20px}
nav{display:flex;gap:30px;font-size:12px}
@media(max-width:600px){nav{gap:13px;font-size:10px}}
</style></head><body><header><a href="#">Brand</a><nav><a href="#one">One</a><a href="#two">Two</a><a href="#three">Three</a></nav></header><main><p>Body</p></main></body></html>
HTML;

$result = (new HtmlTransformer())->transform($html, array())->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$sizes = array( 1440 => '', 390 => '' );
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body, array $conditions) use (&$sizes): void {
    if ( ! str_contains($prelude, 'blocks-engine-navigation-instance-') || ! str_contains($prelude, '.wp-block-navigation-item__content') || ! preg_match('/font-size:([^;}]+)/', $body, $match) ) {
        return;
    }
    foreach ( array_keys($sizes) as $width ) {
        foreach ( $conditions as $condition ) {
            if ( ! preg_match('/^@media\s*(.+)$/is', $condition, $media) || ! CssCascade::mediaConditionApplies($media[1], (float) $width) ) {
                continue 2;
            }
        }
        $sizes[ $width ] = trim($match[1]);
    }
});

if ( '12px' !== $sizes[1440] || '10px' !== $sizes[390] ) {
    fwrite(STDERR, 'FAIL: navigation links resolve to ' . json_encode($sizes) . ", expected 12px at 1440 and 10px at 390\n");
    exit(1);
}
echo "navigation breakpoint inherited typography passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced fixture from #4306: the intermediate fill must not collapse when
// only a media rule gives its ancestor a ratio-derived block size.
$failures = 0;
$cases = array(
    'media ratio and definite width' => '@media (min-width:1px){.box{width:204px;aspect-ratio:1 / 0.283;overflow:hidden}}',
    'no-media control' => '.box{width:204px;aspect-ratio:1 / 0.283;overflow:hidden}',
    'media ratio and percentage width' => '.box{width:100%}@media (min-width:1px){.box{aspect-ratio:1 / 0.283}}',
    'media height' => '@media (min-width:1px){.box{height:58px}}',
    'auto-sized control' => '.box{width:204px;overflow:hidden}',
);
foreach ( $cases as $name => $rules ) {
    $html = '<!doctype html><html><head><style>.fill{height:100%}' . $rules . '</style></head><body>'
        . '<header><div class="box"><div class="fill"><img src="logo.png" alt="" style="width:100%;height:100%"></div></div></header>'
        . '</body></html>';
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? '') ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    preg_match_all('/(?<![\w-])\.fill\{([^}]*)\}/', $css, $matches);
    $expected = 'auto-sized control' === $name ? 'auto' : '100%';
    $actual = implode(';', $matches[1]);
    if ( 1 !== count($matches[1]) || ! str_contains($actual, 'height:' . $expected) || ('100%' === $expected && str_contains($actual, 'height:auto')) ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$name}: expected .fill height:{$expected}, got {$actual}\n");
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "Conditional aspect-ratio parent percentage height passed\n";

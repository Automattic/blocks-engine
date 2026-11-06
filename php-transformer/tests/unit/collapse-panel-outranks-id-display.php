<?php
declare(strict_types=1);

/**
 * A collapse panel the source hides with an ID-scoped `!important` rule (a
 * phone/desktop document switch) still yields Core's open button: the
 * generated wrapper override must outrank that rule, or the panel's
 * `display:none` hides the button with the menu and phones get no hamburger.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$css = '.toggle{display:block}@media (min-width:1280px){.toggle{display:none}}'
    . '#panel{position:fixed;left:0;top:0;transform:translateX(-100%)}@media (min-width:1280px){#panel{transform:none}}'
    . '@media(max-width:767px){#panel{display:none!important}}';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header>'
    . '<div role="button" tabindex="0" class="toggle" aria-controls="panel" aria-expanded="false" title="Show sidebar"><svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"></path></svg></div>'
    . '<nav id="panel"><a class="title" href="/">Site Title</a><ul><li><a href="/a">Alpha</a></li><li><a href="/b">Beta</a></li><li><a href="/c">Gamma</a></li></ul></nav>'
    . '</header><main><p>Body</p></main></body></html>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$assetCss = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));

$failures = array();
if ( 1 !== preg_match('/([^{}]*:has\(> [^{}]*)\{display:contents!important\}/', $assetCss, $match) ) {
    $failures[] = 'no wrapper override for the hidden collapse panel';
} else {
    $ids = 0;
    // An `:is()` arm counts with its most specific argument.
    if ( preg_match('/:is\(([^)]*)\)/', $match[1], $is) ) {
        foreach ( explode(',', $is[1]) as $argument ) {
            $ids = max($ids, substr_count($argument, '#'));
        }
    }
    if ( $ids < 2 ) {
        $failures[] = 'wrapper override does not outrank #panel{display:none!important}: ' . trim($match[1]);
    }
}

foreach ( $failures as $failure ) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
echo 'Collapse panel outranks ID display: ' . ( 1 - count($failures) ) . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
exit(array() === $failures ? 0 : 1);

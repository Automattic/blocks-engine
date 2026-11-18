<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Source frameworks restate a property inside one block, and the later value
// is the one that applies. Final theme stylesheets ship only declarations that
// can decide a value: one a later declaration always overrides (a custom
// property, a var() value, or the same value) is dropped, while a fallback a
// browser may need (`display:-webkit-box;display:flex`) and an earlier
// !important declaration stay.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><h1 class="title">Title</h1><p class="lede">Lede</p></main></body></html>',
    'site.css' => ':root{--accent:#123456}'
        . '.title{font-size:61px;letter-spacing:7px;--tone:#aa0000;font-size:var(--accent-size,37px);letter-spacing:7px;--tone:#00aa00}'
        . '.lede{display:-webkit-box;display:flex;text-indent:13px!important;text-indent:var(--indent,4px);color:var(--accent)}',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
if (!str_contains($css, 'var(--accent-size,37px)')) throw new RuntimeException("Expected the authored title rule in theme CSS:\n" . $css);
foreach (array('61px', '#aa0000') as $shadowed) {
    if (str_contains($css, $shadowed)) throw new RuntimeException("Theme CSS ships a declaration a later one always overrides ({$shadowed}):\n" . $css);
}
if (1 !== substr_count($css, 'letter-spacing:7px')) throw new RuntimeException("A repeated identical declaration ships once:\n" . $css);
if (!str_contains($css, '#00aa00')) throw new RuntimeException("The winning custom property must survive:\n" . $css);
if (!str_contains($css, '-webkit-box')) throw new RuntimeException("A parse-time fallback must survive:\n" . $css);
if (!preg_match('/text-indent:13px\s*!important/', $css)) throw new RuntimeException("An earlier !important declaration wins and must survive:\n" . $css);
echo "Site plan stylesheets drop declarations a later one always overrides\n";

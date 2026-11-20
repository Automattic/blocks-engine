<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSpecificityShimCompactor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Projection pays an arm's specificity one shim at a time, so each projected
// arm restated `:where(.carrier):not(.be-c-0):not(#be-i-0)>:where(.link):not(be-t-0):not(.be-c-0)`.
// Theme sheets ship that weight at its smallest spelling.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><section id="hero" class="hero"><h1>Hi</h1><p class="lead">Lead</p><a class="btn btn-primary" href="#c">Go</a></section><ul class="list"><li>One</li><li>Two</li></ul></main></body></html>',
    'site.css' => 'a{color:red}.btn{padding:4px}.btn-primary{background:navy}#hero .btn{margin:2px}.list li{margin:0}p{margin:0 0 1rem}',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
$assert('' !== $css, 'Expected generated theme CSS');
$shim = ':not\([.#]?be-[tci]-\d+\)';
$padded = array();
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector) use (&$padded, $shim): void {
    // Two shim negations in one compound restate weight one negation carries.
    if (preg_match('/' . $shim . '[^\s>+~,]*' . $shim . '/', $selector)) $padded[] = $selector;
});
$assert(array() === $padded, "Theme CSS ships padded specificity shims:\n" . implode("\n", array_slice($padded, 0, 5)));
$assert(str_contains($css, '{margin:2px}') && str_contains($css, '{margin:0 0 1rem}'), 'Every projected rule survives: ' . $css);

// Each arm matches the same elements at the same specificity.
foreach (array(
    ':where(.c):not(.be-c-0):not(#be-i-0)>:where(.l):not(be-t-0):not(.be-c-0)' => '.c:not(#be-i-0)>.l:not(be-t-0)',
    ':where(.m):not(.be-c-0)' => '.m',
    ':where(.r.s.m):not(.be-c-0)' => '.m:where(.r.s)',
    ':where(.m,.n):not(.be-c-0)' => ':where(.m,.n):not(.be-c-0)',
    ':where(.m .n):not(.be-c-0)' => ':where(.m .n):not(.be-c-0)',
    ':where(.m).m:not(.be-c-0)' => ':where(.m).m:not(.be-c-0)',
    ':where(figure):not(be-t-0).a::before' => 'figure.a::before',
    '.a:not(.be-c-0):not(.be-c-0):hover,.b' => '.a:hover:not(.be-c-0.be-c-0),.b',
    ':is(.a:not(.be-c-0):not(be-t-0),.b)' => ':is(.a:not(be-t-0.be-c-0),.b)',
    ':where(.a:not(.be-c-0)) .b' => ':where(.a) .b',
    '.a:not(.be-c-0)::after:not(.be-c-0)' => '.a:not(.be-c-0)::after:not(.be-c-0)',
    '.a[title="x:not(.be-c-0):not(.be-c-0)"]' => '.a[title="x:not(.be-c-0):not(.be-c-0)"]',
) as $before => $expected) {
    $after = CssSpecificityShimCompactor::selectorList($before);
    $assert($expected === $after, $before . ' compacts to ' . $after);
    foreach (array_map(null, CssStylesheetTransformer::splitSelectorList($before) ?? array(), CssStylesheetTransformer::splitSelectorList($after) ?? array()) as [$from, $to]) {
        $assert(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse((string) $from)) === CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse((string) $to)), $from . ' keeps its weight as ' . $to);
    }
}

echo "specificity-shim-compaction: ok\n";

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = 'body{margin:0;font-family:Georgia}.site-header{display:flex;gap:12px;padding:20px;background:#123;color:#fff}.site-header a{color:#fff}.hero{padding:40px}.card{border:1px solid #ccc;padding:12px}.site-footer{padding:20px;background:#eee}';
$inlineCss = '.page-only{color:#c00}';
$doc = static fn(string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="/site.css"><style>' . $inlineCss . '</style></head><body><header class="site-header"><a href="/">Logo</a><nav><a href="/a/">A</a><a href="/b/">B</a></nav></header><main><section class="hero"><h1 class="page-only">' . $title . '</h1><div class="card"><p>Card</p></div></section></main><footer class="site-footer"><p>© F</p></footer></body></html>';
$files = array('site.css' => $css, 'index.html' => $doc('Home'), 'a/index.html' => $doc('A'), 'b/index.html' => $doc('B'));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$findMetrics = static function (array $node) use (&$findMetrics): ?array {
    if (array_key_exists('unique_source_css_bytes', $node)) return $node;
    foreach ($node as $value) {
        if (is_array($value) && null !== ($metrics = $findMetrics($value))) return $metrics;
    }
    return null;
};
$metrics = $findMetrics($result);
$expected = strlen($css) + strlen($inlineCss);
if ($expected !== ($metrics['unique_source_css_bytes'] ?? null)) {
    fwrite(STDERR, 'Expected captured CSS bytes ' . $expected . ', got ' . json_encode($metrics) . "\n");
    exit(1);
}
if (round($metrics['theme_css_bytes'] / $expected, 4) !== $metrics['generated_to_source_css_ratio']) {
    fwrite(STDERR, "CSS ratio must use captured input bytes\n");
    exit(1);
}
echo "PASS: design-system source CSS bytes\n";

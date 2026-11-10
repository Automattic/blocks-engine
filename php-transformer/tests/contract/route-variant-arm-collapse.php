<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// One shared stylesheet; only the home page converts a link into a button,
// so the reset and `.btn` rules project differently per route.
$css = 'html,body,div,span,p,a,ul,li,h1,img{margin:0;padding:0;border:0}body{line-height:1}.btn{display:inline-block;padding:8px;background:#e55}.copy{color:#333}';
$page = static fn (string $title, string $extra): string => '<!doctype html><html lang="en"><head><title>' . $title . '</title><style>' . $css . '</style></head><body><main><h1>' . $title . '</h1><p class="copy">Text</p>' . $extra . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', '<a class="btn" href="/b/">Go</a>'),
    'b/index.html' => $page('B', '<ul><li><a href="/">Back</a></li></ul>'),
    'c/index.html' => $page('C', ''),
)))->toArray()['source_reports']['wordpress_site_plan'];
$sheet = '';
foreach ($plan['writes'] as $write) {
    if (str_contains($write['target_path'], '/inline/') && str_contains($write['payload']['data'], '.copy')) $sheet = $write['payload']['data'];
}
if ('' === $sheet) throw new RuntimeException('The shared author stylesheet is materialized.');

$arms = array();
(new CssStylesheetTransformer())->visitStyleRules($sheet, static function (string $selector, string $body) use (&$arms): void {
    foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) $arms[] = array(trim($arm), $body);
});
$count = static fn (callable $match): int => count(array_filter($arms, $match));
foreach (array('html', 'body') as $tag) {
    // Every route keeps the same margin reset arm, so it ships once, ungated.
    $margin = $count(static fn (array $arm): bool => 'margin:0' === $arm[1] && preg_match('/(^|[\s(,])' . $tag . '(:not\(\.be-c-\d+\))*($|[):,])/', $arm[0]) && !str_contains($arm[0], 'be-r'));
    $gated = $count(static fn (array $arm): bool => 'margin:0' === $arm[1] && preg_match('/(^|[\s(,])' . $tag . '(:not\(\.be-c-\d+\))*($|[):,])/', $arm[0]) && str_contains($arm[0], 'be-r'));
    if (1 !== $margin || 0 !== $gated) throw new RuntimeException("The {$tag} margin reset ships once, ungated ({$margin} ungated, {$gated} gated):\n{$sheet}");
}
if (0 !== $count(static fn (array $arm): bool => str_contains($arm[0], '.btn:not(:where(') && str_contains($arm[0], 'be-r'))) {
    throw new RuntimeException("A gated .btn arm subsumed by the ungated .btn arm is dropped:\n{$sheet}");
}
echo 'Route variant arm collapse: ' . strlen($css) . ' source bytes, ' . strlen($sheet) . " projected bytes.\n";

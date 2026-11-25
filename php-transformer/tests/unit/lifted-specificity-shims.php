<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// A projected subject arm addresses its carrier through a weightless
// `:where(.role.subject.class)` and pays the source class's weight with a
// shim beside it. The `:where()` already requires that class, so naming it
// outside pays the weight with no shim: every projected framework rule
// otherwise ships `:not(.be-c-0)` once per carrier arm.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><section class="hero"><h1>Hi</h1><a class="btn btn-primary" href="#c">Go</a></section></main></body></html>',
    'site.css' => '.btn{padding:4px}.btn-primary{background:navy}',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
$assert(str_contains($css, '{padding:4px}') && str_contains($css, '{background:var(--wp--preset--color--primary,navy)}'), 'Every projected rule survives: ' . $css);

$payable = array();
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector) use (&$payable): void {
    // A one-compound class `:where()` beside a class shim could pay it.
    if (preg_match('/:where\((?:\.[\w-]+)+\)(?:[^\s>+~,(]|\([^()]*\))*:not\([^)]*\.be-c-\d+/', $selector)) $payable[] = $selector;
});
$assert(array() === $payable, "Theme CSS pays weight with a shim its carrier class could pay:\n" . implode("\n", $payable));
$assert(1 === preg_match('/\.blocks-engine-subject-class-[0-9a-f]{12}:where\(\.blocks-engine-role-control\.blocks-engine-subject\)>/', $css), 'The carrier arm names its subject class outside the role scope: ' . $css);

echo "lifted-specificity-shims: ok\n";

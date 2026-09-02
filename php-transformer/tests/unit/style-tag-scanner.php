<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$html = '<stylesheet>ignored</stylesheet><STYLE media="screen > print">first{color:red}</sTyLe ><style data-id="two">second{color:blue}</style><style>incomplete';
$styles = StyleTagScanner::scan($html);
$assert(2 === count($styles), 'scanner returns only complete style elements with valid tag boundaries');
$assert(' media="screen > print"' === ($styles[0]['attributes'] ?? null), 'scanner preserves attributes containing a greater-than sign');
$assert(array('first{color:red}', 'second{color:blue}') === array_column($styles, 'content'), 'scanner preserves payloads and source order');
$assert(($styles[0]['offset'] ?? -1) < ($styles[1]['offset'] ?? -1), 'scanner exposes stable source offsets');

$largeCss = '/*' . str_repeat('large-style-payload-', 70000) . '*/.large{color:#000}';
$largeHtml = '<html><head><style media="all">' . $largeCss . '</style></head><body><main class="large">Large</main></body></html>';
$assert(strlen($largeCss) > (int) ini_get('pcre.backtrack_limit'), 'regression payload exceeds the default PCRE backtracking budget');
$assert(false === preg_match_all('@<style\b([^>]*)>(.*?)</style>@is', $largeHtml, $unused), 'regression payload reproduces the former PCRE backtracking failure');
$started = microtime(true);
$largeStyles = StyleTagScanner::scan($largeHtml);
$assert(1 === count($largeStyles) && $largeCss === ($largeStyles[0]['content'] ?? ''), 'linear scanner preserves the complete multi-megabyte payload');
$assert(microtime(true) - $started < 2.0, 'multi-megabyte scan completes in bounded time');

$compiled = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $largeHtml),
    ),
))->toArray();
$authorAssets = array_values(array_filter(
    $compiled['assets'] ?? array(),
    static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && !in_array($asset['source'] ?? '', array('engine-support', 'editor-static-state'), true)
));
$assert(1 === count($authorAssets), 'canonical compiler emits the large inline author stylesheet as an asset');
$assert(str_contains((string) ($authorAssets[0]['content'] ?? ''), '.large{color:#000}'), 'emitted author asset retains the large source CSS');
$planAssets = $compiled['source_reports']['wordpress_site_plan']['assets'] ?? array();
$assert((bool) array_filter($planAssets, static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'inline-style' === ($asset['source'] ?? '')), 'WordPress site plan retains inline author stylesheet coverage');

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "style-tag-scanner: passed\n");

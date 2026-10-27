<?php
declare(strict_types=1);

/**
 * A header whose captured dialog panel id is an ordinal is still shared chrome.
 *
 * The capture numbers dialog panels per route (`data-blocks-engine-capture-dialog-N`),
 * so identical headers carry different ordinals in the panel id, its panel
 * handle, and the trigger's aria-controls. Shell identity must ignore that
 * ordinal, while headers whose dialogs differ in content stay unshared.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($ok) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . substr($detail, 0, 600) : '') . PHP_EOL);
};

$links = '<ul><li><a href="/">Home</a></li><li><a href="/about.html">About</a></li><li><a href="/contact.html">Contact</a></li></ul>';
$header = static fn(int $n, string $dialogLinks): string => '<header><a href="/">Acme</a><nav aria-label="Site">' . $links . '</nav>' . "\n"
    . '<button type="button" class="menu-btn" aria-expanded="false" aria-haspopup="dialog" aria-label="Menu" aria-controls="data-blocks-engine-capture-dialog-' . $n . '">☰</button>' . "\n"
    . '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="data-blocks-engine-capture-dialog-' . $n . '" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-' . $n . '" aria-label="Site navigation">' . $dialogLinks . '</div></header>';

$compile = static function (callable $dialogFor) use ($header): array {
    $files = array();
    foreach (array('index.html' => 'Home', 'about.html' => 'About', 'contact.html' => 'Contact') as $path => $title) {
        $n = count($files);
        $files[] = array('path' => $path, 'content' => '<!doctype html><html><head><title>' . $title . '</title></head><body>' . $header($n, $dialogFor($n)) . '<main><h1>' . $title . '</h1><p>' . $title . ' copy.</p></main><footer><p>© Acme</p></footer></body></html>');
    }
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'] ?? array();
};
$parts = static fn(array $plan, string $area): array => array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => $area === ($part['area'] ?? null)));
$template = static function (array $plan, string $slug): string {
    foreach ($plan['templates'] ?? array() as $template) {
        if ($slug === ($template['slug'] ?? null)) return (string) ($template['content'] ?? $template['canonical_block_markup'] ?? '');
    }
    return '';
};

// Identical headers that differ only by the capture's dialog ordinal.
$plan = $compile(static fn(int $n): string => $links);
$diagnostic = (string) json_encode(array_map(static fn(array $part): array => array('area' => $part['area'] ?? null, 'slug' => $part['slug'] ?? null), $plan['template_parts'] ?? array()));
$assert(3 === count($plan['pages'] ?? array()), 'all three routes compile to pages', (string) json_encode(array_column($plan['pages'] ?? array(), 'source_path')));
$assert(1 === count($parts($plan, 'header')), 'the header differing only by dialog ordinal becomes one template part', $diagnostic);
$assert(1 === count($parts($plan, 'footer')), 'the footer becomes one template part', $diagnostic);
foreach (array('page', 'front-page') as $slug) {
    $markup = $template($plan, $slug);
    if ('' === $markup) continue;
    $assert(str_contains($markup, '"area":"header"') || str_contains($markup, '"slug":"header'), $slug . ' includes the header part', $markup);
    $assert(str_contains($markup, '"area":"footer"') || str_contains($markup, '"slug":"footer'), $slug . ' includes the footer part', $markup);
}

// Negative control: dialogs that differ in content keep the header unshared.
$control = $compile(static fn(int $n): string => '<ul><li><a href="/">Home</a></li><li><a href="/page-' . $n . '.html">Page ' . $n . '</a></li></ul>');
$assert(0 === count($parts($control, 'header')), 'headers whose dialogs differ in content stay unshared', (string) json_encode(array_column($control['template_parts'] ?? array(), 'area')));
$assert(1 === count($parts($control, 'footer')), 'the control footer is still shared', (string) json_encode(array_column($control['template_parts'] ?? array(), 'area')));

fwrite(STDOUT, "Captured dialog ordinal shared header: {$passes} passed, {$failures} failed\n");
exit($failures ? 1 : 0);

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Projection restates a utility class rule on every image figure carrier a
// source image could become. When the emitted markup places that class only
// on elements that are not image figures (an icon inside a link), no figure
// can carry it: its figure arms ship weight for nothing (#6145). The class's
// own rule stays, and so do the figure arms of a class a figure carries.
$css = '.me-1{margin-right:.25rem!important}.rounded{border-radius:8px}';
$page = '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="site.css"></head><body><main><h1>Home</h1>'
    . '<p><a href="#close"><img class="me-1" src="a.png" width="16" height="16" alt="">Close</a> the dialog.</p>'
    . '<img class="rounded" src="a.png" width="120" height="120" alt="Avatar"></main></body></html>';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'a.png' => $png, 'index.html' => $page),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = '';
foreach (array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array()) as $unit) {
    $markup .= (string) ($unit['canonical_block_markup'] ?? '');
}
$assert(1 === preg_match('/<img [^>]*class="[^"]*\bme-1\b/', $markup) && 1 !== preg_match('/<figure [^>]*class="[^"]*\bme-1\b/', $markup), 'The icon class is on an inline image, not an image figure.');
$assert(1 === preg_match('/<figure [^>]*class="[^"]*\brounded\b/', $markup), 'The avatar is an image figure.');

$sheets = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) && 'editor' !== ($asset['stylesheet_target'] ?? 'both') ? (string) ($asset['content'] ?? '') : '', $plan['assets'] ?? array()));
$assert(1 === preg_match('/\.me-1[^,{]*\{[^}]*margin-right/', $sheets), 'The icon class keeps its own rule.');
$assert(1 !== preg_match('/\.me-1\.wp-block-image/', $sheets), "No figure arm requires a class the markup places only off figures:\n" . $sheets);
$assert(1 === preg_match('/\.rounded\.wp-block-image\s*>\s*img/', $sheets), 'A class an image figure carries keeps its figure leaf.');

echo "absent-image-figure-off-figure-classes: ok\n";

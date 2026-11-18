<?php
declare(strict_types=1);

// Gutenberg paints imported blocks in its canvas iframe before the theme's
// styles arrive, then restyles them. A captured colour transition (a hover
// `transition-colors` link kept in the instance CSS) animated that restyle,
// so the canvas painted interpolated colours the front end never shows. The
// theme's editor styles render the canvas at its resting style; the front
// end keeps the captured transition.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$html = '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="/site.css"></head><body>'
    . '<main><h1>Home</h1><footer class="foot"><p>Follow us</p>'
    . '<a class="social" href="https://example.test/fb" aria-label="Facebook">FB</a></footer></main></body></html>';
$css = '.foot{background:#15281f;color:#faf8f5}'
    . '.social{display:flex;width:36px;height:36px;color:rgba(250,248,245,.8);transition-property:color,background-color;transition-duration:.15s}'
    . '.social:hover{color:#15281f;background:#faf8f5}';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'site.css' => $css,
)))->toArray()['source_reports']['wordpress_site_plan'];

$functions = '';
foreach ($plan['writes'] ?? array() as $write) {
    if ('functions.php' === ($write['target_path'] ?? null)) $functions .= (string) ($write['payload']['data'] ?? '');
}
$frontendCss = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && 'editor' !== ($asset['stylesheet_target'] ?? 'both')) $frontendCss .= (string) ($asset['content'] ?? '');
}
$assert(1 === preg_match('/transition(?:-duration)?:[^;}]*\.15s|transition-duration:0?\.15s/', $frontendCss), 'The fixture keeps the captured colour transition on the front end: ' . substr($frontendCss, 0, 400));

$assert('' !== $functions, 'The plan writes a theme bootstrap.');
// The editor-settings filter carrying the theme's canvas compatibility rules.
$settings = '';
foreach (explode("\n", $functions) as $line) if (str_contains($line, "add_filter( 'block_editor_settings_all'") && str_contains($line, '.editor-styles-wrapper a[href]{pointer-events:none!important}')) $settings = $line;
$assert('' !== $settings, 'The theme bootstrap registers editor canvas styles.');
$assert(str_contains($settings, ':root .editor-styles-wrapper *{transition:none!important}'), 'Editor canvas styles render imported blocks at their resting style instead of animating the canvas restyle: ' . $settings);

$frontend = str_replace($settings, '', $functions);
$assert(!str_contains($frontend, 'transition:none') && !str_contains($frontendCss, 'transition:none'), 'Front-end output keeps captured transitions.');

echo "editor-canvas-resting-transitions: ok\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// A sheet that orders its rules by named cascade layers (Tailwind's
// `@layer theme, base, utilities`) still ships its editor mirrors
// (`:root .editor-styles-wrapper …`) to every visitor unless they move to the
// editor-only extract. The extract restates each moved arm inside its layer,
// which the sheet before it already declared, so the layer order holds.
$css = '@layer theme,base,utilities;'
    . '@layer theme{:root{--kit-ink:#123456;--kit-paper:#fafafa;--kit-gap:12px;--kit-radius:6px}}'
    . '@layer base{body{color:var(--kit-ink);background:var(--kit-paper);font-family:Georgia,serif;font-size:18px;line-height:1.6;letter-spacing:.01em}}'
    . '@layer utilities{.title{white-space:nowrap}.summary{white-space:nowrap}.card{padding:var(--kit-gap);border-radius:var(--kit-radius);border:1px solid #ccc}'
    . '@media (max-width:600px){body{color:#abcdef;font-size:16px}}}';
$html = '<!doctype html><html><head><title>Page</title><link rel="stylesheet" href="site.css"></head><body><main>'
    . '<h1 class="title">Collection title</h1><p class="summary">Summary</p><div class="card"><p>Card</p></div></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html, 'site.css' => $css),
))->toWordPressSitePlanView()['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$assets = array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), null, 'target_path');
$frontend = $assets['assets/site.css'] ?? null;
$extract = $assets['assets/site.editor-canvas.css'] ?? null;
$assert(null !== $frontend && str_contains($frontend['content'], '@layer'), 'The author sheet keeps its layers: ' . json_encode(array_keys($assets)));
$assert(! str_contains($frontend['content'], 'editor-styles-wrapper'), "The layered sheet visitors download carries no editor-canvas arm:\n" . $frontend['content']);
$assert(str_contains($frontend['content'], 'white-space:nowrap'), 'Frontend rules are kept.');
$assert(str_starts_with(ltrim($frontend['content']), '@layer theme,base,utilities;'), "The sheet still declares its layer order first:\n" . $frontend['content']);

$assert(null !== $extract && 'editor' === ($extract['stylesheet_target'] ?? null), 'Editor-canvas arms of a layered sheet move to an editor-only extract.');
$rules = array();
(new CssStylesheetTransformer())->visitStyleRules($extract['content'], static function (string $selector, string $body, array $parents) use (&$rules): void {
    $rules[] = array($parents, $selector);
});
$assert(array() !== $rules && array() === array_filter($rules, static fn(array $rule): bool => ! str_contains($rule[1], '.editor-styles-wrapper')), 'The extract holds only editor-canvas rules: ' . $extract['content']);
$assert(array() === array_filter($rules, static fn(array $rule): bool => ! str_starts_with((string) ($rule[0][0] ?? ''), '@layer ')), "Every moved arm keeps its layer:\n" . $extract['content']);
$assert(in_array(array(array('@layer utilities', '@media (max-width:600px)'), ':root body.editor-styles-wrapper'), $rules, true), "Moved rules keep their layer and media context:\n" . $extract['content']);

// An anonymous layer cannot be reopened by an extract: its sheet stays whole.
$anonymous = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html, 'site.css' => '@layer{' . substr($css, (int) strpos($css, '@layer utilities{') + strlen('@layer utilities{'))),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$anonymousTargets = array_column(array_filter($anonymous['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'target_path');
$assert(! in_array('assets/site.editor-canvas.css', $anonymousTargets, true), 'A sheet with an anonymous layer is not split.');

echo "layered-stylesheet-editor-canvas-extract: ok\n";

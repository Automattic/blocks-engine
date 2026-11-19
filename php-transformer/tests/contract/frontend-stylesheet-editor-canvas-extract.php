<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/BootstrapStyles.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\BootstrapStyles;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Editor mirrors (`:root .editor-styles-wrapper …`) match nothing a visitor
// renders, yet were shipped in the sheet every front-end page downloads.
// They move to an editor-only extract replayed right after that sheet.
$css = 'body{color:#123456;font-family:Georgia,serif;font-size:18px;line-height:1.6;letter-spacing:.01em}'
    . '.title{white-space:nowrap}.summary{white-space:nowrap}.card{padding:12px;border:1px solid #ccc}'
    . '@media (max-width:600px){body{color:#abcdef;font-size:16px}}';
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
$assert(null !== $frontend && 'both' === ($frontend['stylesheet_target'] ?? 'both'), 'The author sheet still serves both contexts.');
$assert(! str_contains($frontend['content'], 'editor-styles-wrapper'), 'The sheet visitors download carries no editor-canvas arm.');
$assert(str_contains($frontend['content'], 'white-space:nowrap') && str_contains($frontend['content'], '@media (max-width:600px){body{color:#abcdef;font-size:16px}}'), 'Frontend rules are kept.');

$assert(null !== $extract && 'editor' === ($extract['stylesheet_target'] ?? null), 'Editor-canvas arms move to an editor-only extract.');
$rules = array();
(new CssStylesheetTransformer())->visitStyleRules($extract['content'], static function (string $selector, string $body, array $parents) use (&$rules): void {
    $rules[] = array($parents, $selector, $body);
});
$assert(array() !== $rules && array() === array_filter($rules, static fn(array $rule): bool => ! str_contains($rule[1], '.editor-styles-wrapper')), 'The extract holds only editor-canvas rules, never a copy of the sheet.');
$assert(in_array(array(array(), ':root .editor-styles-wrapper .title', 'text-wrap-mode:nowrap!important'), $rules, true), 'Editor wrapping overrides survive in the extract.');
$assert(in_array(array(array('@media (max-width:600px)'), ':root body.editor-styles-wrapper', 'color:#abcdef;font-size:16px'), $rules, true), 'Moved rules keep their media context.');

// Each context replays one resource per position: the extract directly
// follows its sheet, only in the editor.
$order = static fn(array $asset): ?int => $asset['stylesheet_instances'][0]['order'] ?? null;
$assert(null !== $order($frontend) && $order($frontend) + 1 === $order($extract), 'The extract replays directly after its sheet.');
$bootstrap = BootstrapStyles::expand((string) array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data']);
$visitor = (string) strstr($bootstrap, "add_action( 'wp_enqueue_scripts'");
$assert(str_contains($visitor, 'assets/site.css') && ! str_contains(substr($visitor, 0, (int) strpos($visitor, '}, 1 );')), 'site.editor-canvas.css'), 'Visitors never enqueue the extract.');
$editorRows = array_column(BootstrapStyles::registry($bootstrap, 'blocks_engine_presentation_styles'), 'target_path');
$sheet = array_search('assets/site.css', $editorRows, true);
$assert(false !== $sheet && 'assets/site.editor-canvas.css' === ($editorRows[$sheet + 1] ?? null), 'The editor loads the extract right after its sheet.');
$writes = array_column($plan['writes'], null, 'target_path');
$assert(isset($writes['assets/site.editor-canvas.css']), 'The extract is materialized for the editor.');

echo "frontend-stylesheet-editor-canvas-extract: ok\n";

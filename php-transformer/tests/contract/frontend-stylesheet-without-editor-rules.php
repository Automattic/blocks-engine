<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Projection writes editor-canvas variants of author rules
// (`:where(:root .editor-styles-wrapper) …`). Only the block editor has that
// wrapper, so visitors should not download them: the frontend gets the
// stylesheet without them, and the editor keeps every rule in a sibling copy.
$css = 'body{margin:0;font-family:serif}.card{display:flex;gap:12px;padding:8px}.card img{width:120px;height:80px;object-fit:cover}'
    . '.card p:empty{display:none}.note{color:#555}table td{padding:4px}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<header><a href="index.html">Acme</a></header><main><h1>' . $title . '</h1>'
    . '<div class="card"><img src="a.png" alt="A"><p class="note">Hello ' . $title . '</p><p></p></div>'
    . '<table><tr><td>1</td><td>2</td></tr></table></main><footer><p>Acme Ltd</p></footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'a.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        'index.html' => $page('Home'),
        'b.html' => $page('Beta'),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$byTarget = array();
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? null)) $byTarget[$asset['target_path']] = $asset;
$frontend = $byTarget['assets/site.css'] ?? null;
$editor = $byTarget['assets/site.editor.css'] ?? null;
$assert(is_array($frontend) && 'frontend' === ($frontend['stylesheet_target'] ?? null), 'The author stylesheet serves the frontend.');
$assert(is_array($editor) && 'editor' === ($editor['stylesheet_target'] ?? null), 'A sibling copy serves the editor.');
$assert(! str_contains((string) $frontend['content'], 'editor-styles-wrapper'), 'The frontend stylesheet carries no editor-canvas rule.');
$assert(str_contains((string) $editor['content'], 'editor-styles-wrapper'), 'The editor copy keeps the editor-canvas rules.');
$assert(strlen((string) $frontend['content']) < strlen((string) $editor['content']), 'Visitors download less CSS.');
$assert($frontend['stylesheet_instances'] === $editor['stylesheet_instances'] && $frontend['scopes'] === $editor['scopes'], 'Both copies hold the same document positions and scopes.');

// Removing editor-only selectors leaves every frontend rule in source order.
$frontendRules = preg_replace('/\s+/', '', (string) $frontend['content']);
$editorWithoutCanvas = '';
(new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer())->transformStyleRules((string) $editor['content'], static function (string $prelude, string $body) use (&$editorWithoutCanvas): string {
    if (! str_contains($prelude, 'editor-styles-wrapper')) $editorWithoutCanvas .= $prelude . '{' . $body . '}';
    return '';
});
$assert(str_contains($frontendRules, preg_replace('/\s+/', '', $editorWithoutCanvas)), 'Frontend rules keep their text and order.');

// Each context enqueues one of the two copies.
$functions = '';
foreach ($plan['writes'] as $write) if (str_ends_with((string) ($write['target_path'] ?? ''), 'functions.php')) $functions = (string) ($write['payload']['data'] ?? '');
$assert(str_contains($functions, "get_theme_file_uri( 'assets/site.css' )"), 'The frontend enqueues the frontend copy.');
$assert(! str_contains($functions, "get_theme_file_uri( 'assets/site.editor.css' )"), 'The frontend never enqueues the editor copy.');
$assert(1 === preg_match("/'target_path' => 'assets\\/site\\.editor\\.css',.*?'editor_only' => false/s", $functions), 'The editor loads its copy wherever it loaded the shared stylesheet.');
$assert(! preg_match("/'target_path' => 'assets\\/site\\.css',/", $functions), 'The editor does not load the frontend copy.');
foreach (WordPressSitePlan::documentStylesheets($plan, 'index.html') as $row) {
    if ('assets/site.css' === $row['target_path']) $assert('frontend' === $row['stylesheet_target'], 'Hosts replaying the sequence see the frontend copy as frontend-only.');
    if ('assets/site.editor.css' === $row['target_path']) $assert('editor' === $row['stylesheet_target'], 'Hosts replaying the sequence see the editor copy as editor-only.');
}

// Only selectors that require the wrapper are removed.
$requires = (new ReflectionMethod(WordPressSitePlan::class, 'selectorRequiresEditorCanvas'))->getClosure(null);
$cases = array(
    ':where(:root .editor-styles-wrapper) .card' => true,
    ':root .editor-styles-wrapper .card' => true,
    ':root *.editor-styles-wrapper' => true,
    ':root body.editor-styles-wrapper' => true,
    ':is(.editor-styles-wrapper,.editor-styles-wrapper .x) .card' => true,
    '.card:has(.editor-styles-wrapper)' => true,
    ':is(.editor-styles-wrapper,body) .card' => false,
    '.card:not(.editor-styles-wrapper .x)' => false,
    '.editor-styles-wrapper-like .card' => false,
    '[class~="editor-styles-wrapper"] .card' => false,
    '.card' => false,
);
foreach ($cases as $selector => $expected) $assert($expected === $requires($selector), $selector . ' should ' . ($expected ? '' : 'not ') . 'require the editor canvas.');

echo "frontend-stylesheet-without-editor-rules: ok\n";

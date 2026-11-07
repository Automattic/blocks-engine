<?php
declare(strict_types=1);

// The frontend applies some resting state only at render time: a navigation
// item's source inline style through a render_block filter, and the captured
// document root classes through body_class. The editor canvas renders neither
// path, so the plan must carry the same resting state to it explicitly.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};

// A priority-style menu: the stylesheet hides every item until a script marks
// the ones that fit; the snapshot holds the script's inline resting result.
$document = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>'
    . '<body class="root-scope shared-font page-' . strtolower($title) . '"><header class="site"><nav class="menu"><ul class="list">'
    . '<li class="nav-item" style="visibility: visible"><a class="link" href="/">Home</a></li>'
    . '<li class="nav-item" style="visibility: visible"><a class="link" href="/about.html">About</a></li>'
    . '<li class="nav-item" style="visibility: hidden;display: none"><a class="link" href="/contact.html">Contact</a></li>'
    . '</ul></nav></header><main><h1>' . $title . '</h1><p>Body copy.</p></main></body></html>';
$css = '.root-scope .nav-item{visibility:hidden;display:inline-block;padding:0 8px}.root-scope .link{color:rgb(200,10,10);text-decoration:none}.menu .list{display:flex;list-style:none}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $document('Home'),
    'about.html' => $document('About'),
    'site.css' => $css,
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];

// Every item style the frontend filter will apply, keyed by item marker.
$markup = implode("\n", array_merge(
    array_column($plan['pages'], 'canonical_block_markup'),
    array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'),
    array_map(static fn(array $write): string => is_string($write['payload']['data'] ?? null) ? $write['payload']['data'] : '', $plan['writes'] ?? array()),
    array_map(static fn(array $menu): string => (string) ($menu['block_markup'] ?? ''), $plan['menus'] ?? array())
));
preg_match_all('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*\/?-->/s', $markup, $matches);
$itemStyles = array();
foreach ($matches[1] as $json) {
    $attrs = json_decode($json, true);
    $style = $attrs['metadata']['blocksEngineNavigationAnchor']['itemStyle'] ?? null;
    if (!is_string($style) || !preg_match('/\bblocks-engine-navigation-item-[a-f0-9]{12}-\d+\b/', (string) ($attrs['className'] ?? ''), $marker)) continue;
    $itemStyles[$marker[0]] = $style;
}
$assert(count($itemStyles) >= 2, 'The fixture emits render-time item styles: ' . json_encode($itemStyles));

$editorCss = $frontendCss = '';
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    $target = $asset['stylesheet_target'] ?? 'both';
    if ('frontend' !== $target) $editorCss .= (string) ($asset['content'] ?? '') . "\n";
    if ('editor' !== $target) $frontendCss .= (string) ($asset['content'] ?? '') . "\n";
}
foreach ($itemStyles as $marker => $style) {
    $declarations = array();
    foreach (array_filter(array_map('trim', explode(';', $style))) as $declaration) {
        [$property, $value] = array_map('trim', explode(':', $declaration, 2));
        $declarations[] = strtolower($property) . ':' . $value . '!important';
    }
    $rule = '.wp-block-navigation-item.' . $marker . '{' . implode(';', $declarations) . '}';
    $assert(str_contains($editorCss, $rule), 'The editor restates the item\'s render-time inline style at inline precedence: ' . $rule);
    $assert(!str_contains($frontendCss, $rule), 'The frontend keeps its render filter as the only owner of the item style: ' . $marker);
}

// RichText writes white-space:pre-wrap inline on the editable label. A nowrap
// menu must not become breakable there, or its flex parent squeezes it and the
// trailing items overflow the editor canvas.
$assert((bool) preg_match('/\.wp-block-navigation-item:is\(([^)]*)\) \.wp-block-navigation-item__label\[contenteditable\]\{text-wrap-mode:inherit!important\}/', $editorCss, $labelRule), 'The editor label inherits the item\'s line wrapping.');
foreach (array_keys($itemStyles) as $marker) $assert(str_contains($labelRule[1], '.' . $marker), 'The label wrapping rule covers ' . $marker);
$assert(!str_contains($frontendCss, '__label[contenteditable]'), 'The frontend has no editable label to correct.');

// Template parts, templates, and the Site Editor have no captured route. Their
// canvas must still carry the root state every captured document shares.
$bootstrap = DocumentRootContext::bootstrap($plan['pages']);
$assert('' !== $bootstrap, 'Captured document roots emit a runtime registry.');
$filters = array();
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void { $GLOBALS['captured_filters'][$hook][] = $callback; }
function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void {}
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['captured_identities'][$id] ?? ''; }
$GLOBALS['captured_identities'] = array(41 => $plan['pages'][0]['reconciliation_identity']);
(static function (string $code): void { eval($code); })($bootstrap);
$settings = $GLOBALS['captured_filters']['block_editor_settings_all'][0] ?? null;
$assert(is_callable($settings), 'The registry hooks editor settings.');
$classes = static fn(array $result): array => preg_split('/\s+/', trim((string) ($result['blocksEngineDocumentContext']['body']['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: array();
foreach (array('site editor' => (object) array('name' => 'core/edit-site', 'post' => null), 'template part' => (object) array('name' => 'core/edit-site', 'post' => (object) array('ID' => 77)), 'uncaptured post' => (object) array('name' => 'core/edit-post', 'post' => (object) array('ID' => 78))) as $label => $context) {
    $shared = $classes($settings(array(), $context));
    $assert(in_array('root-scope', $shared, true) && in_array('shared-font', $shared, true), $label . ' canvas carries the shared document root classes: ' . json_encode($shared));
    $assert(!preg_grep('/^page-/', $shared), $label . ' canvas excludes route-owned root classes.');
}
$own = $classes($settings(array(), (object) array('name' => 'core/edit-post', 'post' => (object) array('ID' => 41))));
$assert(in_array('page-home', $own, true) && in_array('root-scope', $own, true), 'A captured route keeps its full document root context: ' . json_encode($own));

fwrite(STDOUT, "Editor render-time resting state: {$assertions} assertions passed\n");

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Captured pages share page.html and own their layout (title included) in
// content. A page authored after the import renders the entry's prose column,
// the border-box container's content box, with its native title.
$style = '<style>*{box-sizing:border-box}.container{max-width:1280px;margin-left:auto;margin-right:auto;padding-left:20px;padding-right:20px}</style>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Atelier</title>' . $style . '</head><body><main><section><div class="container"><h1>Engineered to your standard</h1><p>Home story in the site column.</p></div></section></main></body></html>',
    'about.html' => '<html><head><title>About</title>' . $style . '</head><body><main><section><div class="container"><h1>About the atelier</h1><p>Our story.</p></div></section></main></body></html>',
)))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); };
if (str_contains($templates['page'] ?? '', 'wp:post-title') || str_contains($templates['page'] ?? '', 'contentSize')) $fail('captured pages keep their content-owned page.html');
$authored = $templates['authored-page'] ?? $fail('new pages need their own template');
foreach (array('<!-- wp:post-title {"level":1} /-->', '"contentSize":"1240px"', '"padding":{"left":"20px","right":"20px"}', 'style="padding-right:20px;padding-left:20px"') as $needle) {
    if (!str_contains($authored, $needle)) $fail("new-page template lacks {$needle}");
}

// The bootstrap selects that template only for pages without a captured
// identity, captured route or assigned template, below page-<slug> and page-<id>.
$filters = array();
function add_filter($hook, $callback, ...$args): void { $GLOBALS['filters'][$hook][] = $callback; }
function add_action(...$args): void {}
function remove_action(...$args): void {}
function remove_filter(...$args): void {}
function get_queried_object_id(): int { return $GLOBALS['queried']; }
function get_page_template_slug($id): string { return 21 === $id ? 'page-landing' : ''; }
function get_post_meta($id, $key, $single): string { return 20 === $id ? 'captured-identity' : ''; }
function get_page_uri($id): string { return 23 === $id ? 'about' : 'new'; }
$source = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
eval(preg_replace('/^<\?php\s*/', '', $source));
$hierarchy = array('page-new.php', 'page-22.php', 'page.php');
foreach (array(20 => $hierarchy, 21 => $hierarchy, 23 => $hierarchy, 22 => array('page-new.php', 'page-22.php', 'authored-page.php', 'page.php')) as $id => $expected) {
    $GLOBALS['queried'] = $id;
    $actual = $hierarchy;
    foreach ($filters['page_template_hierarchy'] ?? array() as $callback) $actual = $callback($actual);
    if ($actual !== $expected) $fail("page {$id} resolves " . implode(',', $actual));
}
echo "authored-page-template: passed\n";

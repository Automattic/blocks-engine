<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$request = array();
function apply_filters(string $hook, mixed $value): mixed { return $value; }
function is_singular(?string $type = null): bool { return null === $type ? true : $type === $GLOBALS['request']['type']; }
function get_queried_object_id(): int { return 7; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['request']['identity']; }
function get_post_type(int $id): string { return $GLOBALS['request']['type']; }
function get_page_uri(int $id): string { return $GLOBALS['request']['uri']; }
function get_page_template_slug(int $id): string { return $GLOBALS['request']['template']; }
function is_front_page(): bool { return false; }
function get_theme_file_uri(string $path): string { return $path; }
function wp_enqueue_style(string $handle): void { $GLOBALS['enqueued'][] = $handle; }

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// A support sheet that several post designs and pages need was selected by a
// condition restating every page's identity and path and one template test
// per design, again for every such sheet. Pages and designs are numbered once
// per request; each sheet's condition lists numbers.
$pages = $scopes = array();
foreach (array('alpha' => null, 'beta' => 'single-post-article-beta', 'gamma' => 'single-post-article-gamma') as $slug => $template) {
    $page = array('source_path' => "$slug.html", 'post_type' => 'post', 'route' => array('path' => "/$slug"), 'reconciliation_identity' => hash('sha256', $slug), 'canonical_block_markup' => '', 'metadata' => array(), 'document_metadata' => array());
    if (null !== $template) $page['route_template'] = $template;
    $pages[] = $page;
    $scopes[] = array('kind' => 'post', 'source_path' => $page['source_path'], 'route_path' => $slug, 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => false);
}
foreach (array('about', 'contact') as $slug) {
    $pages[] = array('source_path' => "$slug.html", 'post_type' => 'page', 'route' => array('path' => "/$slug"), 'reconciliation_identity' => hash('sha256', $slug), 'canonical_block_markup' => '', 'metadata' => array(), 'document_metadata' => array());
    $scopes[] = array('kind' => 'page', 'source_path' => "$slug.html", 'route_path' => $slug, 'reconciliation_identity' => hash('sha256', $slug), 'front_page' => false);
}
$assets = array(array('kind' => 'css', 'source' => 'engine-support', 'source_path' => 'assets/css/engine-support-before-author-x.css', 'target_path' => 'assets/assets/css/engine-support-before-author-x.css', 'content_hash' => hash('sha256', 'x'), 'stylesheet_placement' => 'before-author', 'scopes' => $scopes));
$bootstrap = ThemeBootstrap::functionsPhp($assets, array(), array(), array(), array(), $pages);
$lines = explode("\n", $bootstrap);
$sheet = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, "'assets/assets/css/engine-support-before-author-x.css'") && str_contains($line, 'wp_enqueue_style')));
$assert(1 === count($sheet), 'The shared sheet has one enqueue.');
$assert(!str_contains($sheet[0], hash('sha256', 'about')) && !str_contains($sheet[0], 'get_page_template_slug') && !str_contains($sheet[0], "'about'"), 'Its condition names no identity, path or template test.');

// Run the request lookups and the sheet's line against stubbed requests.
$request = array_values(array_filter($lines, static fn (string $line): bool => 1 === preg_match('/^    \$blocks_engine_(?:page_id|page_identity|page_uri|page_scope|design_scope) = /', $line)));
$assert(5 === count($request), 'The request reads its page and design numbers once.');
$run = static function (array $current) use ($request, $sheet): bool {
    $GLOBALS['request'] = $current + array('identity' => '', 'uri' => '', 'template' => '');
    $GLOBALS['enqueued'] = array();
    eval(implode("\n", $request) . "\n" . $sheet[0]);
    return array() !== $GLOBALS['enqueued'];
};
$assert($run(array('type' => 'post', 'identity' => 'new-post', 'template' => '')), 'A post of the default design loads the sheet.');
$assert($run(array('type' => 'post', 'identity' => 'new-post', 'template' => 'single-post-article-gamma')), 'A post of another listed design loads the sheet.');
$assert(!$run(array('type' => 'post', 'identity' => hash('sha256', 'beta'), 'template' => 'single-post-article-other')), 'A post of an unlisted design does not.');
$assert($run(array('type' => 'page', 'identity' => hash('sha256', 'contact'))), 'A listed page loads the sheet by identity.');
$assert($run(array('type' => 'page', 'uri' => 'about')), 'A listed page without identity loads the sheet by URI.');
$assert(!$run(array('type' => 'page', 'identity' => hash('sha256', 'elsewhere'), 'uri' => 'about')), 'Another imported page does not.');
fwrite(STDOUT, "shared-sheet-scope-lookup: ok\n");

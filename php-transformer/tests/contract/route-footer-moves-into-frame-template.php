<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Builder chrome: one header shared by every route inside the site wrappers,
// but each route's footer differs, so no footer part forms. The shared header
// hoists the wrappers into each route's template; the route's own footer,
// after the content, is chrome of that frame and must not stay page content.
$routes = array('index' => 'Home', 'about' => 'About', 'contact' => 'Contact');
$files = array();
foreach ($routes as $route => $title) {
    $nav = '';
    foreach ($routes as $other => $label) $nav .= '<a href="' . ('index' === $other ? '/' : '/' . $other . '/') . '">' . $label . '</a>';
    $files['index' === $route ? 'index.html' : $route . '/index.html'] = '<!doctype html><html><head><title>' . $title . '</title><style>.site{max-width:960px}.site-footer{padding:24px}</style></head><body>'
        . '<div id="site" class="site"><div class="site-inner">'
        . '<header class="site-header"><nav>' . $nav . '</nav></header>'
        . '<main><h1>' . $title . '</h1><p>' . $title . ' route content.</p></main>'
        . '<footer class="site-footer"><p>' . $title . ' footer note, unique to this route.</p></footer>'
        . '</div></div></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers) && 'inline_shared_shell' === ($headers[0]['placement']['kind'] ?? null), 'The nested header is one inline shared part.');
$templates = array_column($plan['templates'], null, 'slug');
foreach ($plan['pages'] as $page) {
    $title = $routes['index.html' === $page['source_path'] ? 'index' : dirname($page['source_path'])];
    $assert(!preg_match('/<footer[ >]/', $page['canonical_block_markup']), 'The route footer must not remain in page content: ' . $page['source_path']);
    $assert(str_contains($page['canonical_block_markup'], $title . ' route content.'), 'Page content stays owned by the page: ' . $page['source_path']);
    $template = $templates[$page['route_template'] ?? (!empty($page['entrypoint']) ? 'front-page' : 'page')] ?? null;
    $assert(is_array($template), 'Each route renders through a template: ' . $page['source_path']);
    $markup = $template['canonical_block_markup'];
    $content = strpos($markup, '<!-- wp:post-content');
    $footer = strpos($markup, $title . ' footer note, unique to this route.');
    $assert(false !== $content && false !== $footer && $footer > $content, 'The route footer follows the content slot in its own template: ' . $page['source_path']);
    $assert(1 === substr_count($markup, '<footer'), 'The route template carries exactly its own footer: ' . $page['source_path']);
}

echo "route footer moves into frame template passed\n";

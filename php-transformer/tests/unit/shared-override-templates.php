<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$files = array();
foreach (glob(dirname(__DIR__) . '/fixtures/shared-override-templates/*.html') as $path) $files[basename($path)] = file_get_contents($path);
$view = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$templates = array_column($plan['templates'], null, 'slug');
$pages = array_column($plan['pages'], null, 'slug');
$theme = json_decode(array_column($plan['writes'], null, 'target_path')['theme.json']['payload']['data'], true);
$custom = $theme['customTemplates'] ?? array();
$shared = $pages['c']['metadata']['post_meta']['_wp_page_template'] ?? null;
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$assert(is_string($shared) && isset($templates[$shared]), 'C selects an emitted custom template');
$assert(null !== $shared && $shared === ($pages['d']['metadata']['post_meta']['_wp_page_template'] ?? null), 'C and D select the same template');
$assert(!isset($templates['page-c']) && !isset($templates['page-d']), 'no per-route duplicates remain');
$assert(8 === count($templates), 'exactly one override is emitted alongside the seven lifecycle templates');
$registrations = array_values(array_filter($custom, static fn(array $row): bool => $row['name'] === $shared));
$assert(1 === count($registrations) && '' !== trim($registrations[0]['title']) && array('page') === $registrations[0]['postTypes'], 'shared template is registered once with a readable title for pages');
$entry = current(array_filter($plan['pages'], static fn(array $page): bool => !empty($page['entrypoint'])));
$assert(isset($templates['front-page']) && !isset($entry['metadata']['post_meta']['_wp_page_template']), 'front-page handling is unchanged');
if (is_string($shared) && isset($templates[$shared])) {
    $footer = current(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['area'] && str_contains($part['canonical_block_markup'], 'Products')));
    $assert(is_array($footer) && str_contains($templates[$shared]['canonical_block_markup'], '"slug":"' . $footer['slug'] . '"'), 'shared override references the Products footer');
}

// With only one excluded route, its native hierarchy override stays unique.
unset($files['d.html']);
$uniqueView = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView();
$uniquePlan = $uniqueView['wordpress_site_plan'];
$uniqueTemplates = array_column($uniquePlan['templates'], null, 'slug');
$uniquePages = array_column($uniquePlan['pages'], null, 'slug');
$assert(isset($uniqueTemplates['page-c']) && !isset($uniquePages['c']['metadata']['post_meta']['_wp_page_template']), 'a unique override keeps its native slug and assignment');
exit($failures ? 1 : 0);

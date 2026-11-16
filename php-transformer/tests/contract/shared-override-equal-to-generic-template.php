<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$document = static fn(string $title, string $shell): string => '<html><body><div class="' . $shell . '"><header><p>Brand</p></header><main><h1>' . $title . '</h1></main><footer><p>Footer</p></footer></div></body></html>';
// Colliding terminal slugs initially receive qualified route overrides even
// though both inner pages use the generic page layout.
$files = array('index.html' => $document('Home', 'site'), 'north/about.html' => $document('About north', 'site'), 'south/about.html' => $document('About south', 'site'));
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$plan = $compile($files);
$assert(!array_filter($plan['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'page-shared-')), 'Generic layouts must not emit shared custom templates.');
foreach ($plan['pages'] as $page) $assert(!isset($page['route_template']) && !isset($page['metadata']['post_meta']['_wp_page_template']), 'Generic pages must not select custom templates.');

$singletonFiles = $files;
unset($singletonFiles['south/about.html']);
$singleton = $compile($singletonFiles);
$assert(!array_filter($singleton['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'page-')), 'A single inner page with the generic layout needs no override.');

$files['special.html'] = $document('Special', 'distinct');
$distinct = $compile($files);
$assert(in_array('page-special', array_column($distinct['templates'], 'slug'), true), 'A fourth page with a different shell keeps its own hierarchy override.');

// Exercise singleton removal and both post hierarchy candidates, including
// placement keys that must no longer refer to the removed template.
foreach (array('page' => 'page', 'post' => 'single', 'book' => 'single-book') as $type => $generic) {
    $old = 'page' === $type ? 'page-about' : 'single-' . $type . '-about';
    $pages = array(array('slug' => 'about', 'post_type' => $type, 'route_template' => $old, 'metadata' => array('post_meta' => array('_wp_page_template' => $old))));
    $parts = array(array('placement' => array('template_slugs' => array($generic, $old), 'excluded_template_slugs' => array($old), 'template_wrappers' => array($old => array('opening' => '<div>', 'closing' => '</div>')))));
    $templates = array(array('slug' => $generic, 'canonical_block_markup' => 'same'), array('slug' => $old, 'canonical_block_markup' => 'same'));
    $titles = ThemeTemplates::shareOverrides($pages, $parts, $templates);
    $assert(array() === $titles && array($generic) === array_column($templates, 'slug'), 'Singleton equality removes the override without emitting a shared template.');
    $assert(!isset($pages[0]['route_template']) && !isset($pages[0]['metadata']['post_meta']['_wp_page_template']), 'Singleton equality clears both assignments.');
    $assert(array($generic) === $parts[0]['placement']['template_slugs'] && array($generic) === $parts[0]['placement']['excluded_template_slugs'] && array($generic) === array_keys($parts[0]['placement']['template_wrappers']), 'All placement keys follow the generic template.');
    $assert($generic === TemplateSlugs::genericForPage($pages[0], array_column($templates, 'slug')), 'Post types prefer their typed generic template when available.');
}

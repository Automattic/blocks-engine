<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Public template reconciliation: imported routes use distinct layouts and
// already own their headings. page.html is the new-page authoring fallback.
$pages = array(); $templates = array(); $parts = array();
foreach (array('about', 'menu') as $slug) {
    $pages[] = array('post_type' => 'page', 'slug' => $slug, 'title' => ucfirst($slug), 'route_template' => 'page-' . $slug, 'canonical_block_markup' => '<h1>' . $slug . '</h1>');
    $templates[] = array('slug' => 'page-' . $slug, 'canonical_block_markup' => '<!-- wp:group --><div class="' . $slug . '"><!-- wp:post-content /--></div><!-- /wp:group -->');
}
$templates[] = array('slug' => 'page', 'canonical_block_markup' => '<!-- wp:post-content /-->');
$captured = $templates;
ThemeTemplates::shareOverrides($pages, $parts, $templates);
$markup = array_column($templates, 'canonical_block_markup', 'slug');
$assert(1 === substr_count($markup['page'], '<!-- wp:post-title {"level":1} /-->'), 'New pages get one native title.');
$assert(strpos($markup['page'], 'wp:post-title') < strpos($markup['page'], 'wp:post-content'), 'The title precedes page content.');
foreach ($captured as $template) if ('page' !== $template['slug']) {
    $assert($markup[$template['slug']] === $template['canonical_block_markup'], 'Imported route rendering stays unchanged.');
}
// Reconciliation is idempotent.
ThemeTemplates::shareOverrides($pages, $parts, $templates);
$assert($markup === array_column($templates, 'canonical_block_markup', 'slug'), 'Repeated reconciliation does not duplicate titles.');
$assert(str_contains($markup['page'], '"layout":{"type":"constrained"}'), 'New body paragraphs use the theme content column.');

// A captured entry route can have lost its byte-identical front-page override
// during build(). It must not inherit the new authoring title or column.
$pages[] = array('post_type' => 'page', 'slug' => 'home', 'entrypoint' => true);
$templates = $captured;
$originalPage = array_column($templates, 'canonical_block_markup', 'slug')['page'];
ThemeTemplates::shareOverrides($pages, $parts, $templates);
$rendered = ThemeTemplates::rendered(array('templates' => $templates));
$assert($originalPage === $rendered['front-page'], 'Collapsed front-page fallback keeps its captured markup.');
$assert(str_contains($rendered['page'], 'wp:post-title'), 'New pages still receive a title.');
$assert(str_contains($rendered['page'], '"layout":{"type":"constrained"}'), 'New pages still receive the content column.');
$before = $templates;
ThemeTemplates::shareOverrides($pages, $parts, $templates);
$assert($before === $templates, 'Preserving the front-page fallback is idempotent.');

// A captured inner page still using page.html owns its title and geometry.
$pages[] = array('post_type' => 'page', 'slug' => 'plain', 'route_template' => 'page');
$templates = $captured;
ThemeTemplates::shareOverrides($pages, $parts, $templates);
$assert($originalPage === array_column($templates, 'canonical_block_markup', 'slug')['page'], 'A live captured page fallback is not restyled.');
echo "new page native title: ok\n";

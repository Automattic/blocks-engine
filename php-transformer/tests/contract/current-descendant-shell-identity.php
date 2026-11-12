<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$routes = array('index' => 'Home', 'about' => 'About', 'contact' => 'Contact');
$files = array();
foreach ($routes as $current => $title) {
    $items = '';
    foreach ($routes as $slug => $label) {
        $active = $slug === $current;
        $items .= '<li class="menu__wrap" aria-current="' . ($active ? 'true' : 'false') . '" data-is-current="' . ($active ? 'true' : 'false') . '"><a class="item' . ($active ? ' item--isCurrentPage' : '') . '" href="/' . ('index' === $slug ? '' : $slug . '/') . '">' . $label . '</a></li>';
    }
    $files[$current . '.html'] = '<!doctype html><html lang="en"><head><title>' . $title . '</title><style>.item{color:#fff}.item--isCurrentPage{border-bottom:2px solid #fff}</style></head><body><header><nav aria-label="Main"><ul>' . $items . '</ul></nav></header><main><h1>' . $title . '</h1><p>' . $title . ' page body text.</p></main><footer><p>Example footer</p></footer></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'The current-page class on the current item descendant does not split the shared header.');
$assert(!str_contains($headers[0]['canonical_block_markup'], 'item--isCurrentPage'), 'The shared header does not freeze the current-page descendant class.');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $templateSlug = $page['entrypoint'] ? 'front-page' : 'page';
    $template = ThemeTemplates::rendered($plan)[$templateSlug] ?? '';
    $assert(str_contains($template . $markup, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), $page['source_path'] . ' binds the shared header part.');
    $assert(!str_contains($markup, '<header') && !str_contains($markup, '<!-- wp:navigation '), $page['source_path'] . ' has no inline header navigation.');
}
echo "current-descendant-shell-identity contract passed\n";

<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$compile = static function (bool $descendant = false): array {
    $files = array();
    $add = static function (string $path, string $route, string $body, string $footer = 'Shared footer', array $metadata = array()) use (&$files): void {
        $files[] = array('path' => $path, 'content' => '<html><body><main>' . $body . '</main><footer><p>' . $footer . '</p></footer></body></html>', 'metadata' => $metadata + array('route_path' => $route));
    };
    $add('index.html', '/', '<h1>Home</h1>');
    foreach (array('about', 'contact', 'help') as $slug) $add($slug . '.html', '/' . $slug, '<h1>' . ucfirst($slug) . '</h1>');
    $add('special.html', '/special', '<h1>Special</h1>', 'Special footer');
    foreach (array('programming' => 'Programming', 'programming/wordpress' => 'WordPress') as $slug => $name) {
        $add('category/' . $slug . '.html', '/category/' . $slug, '<h1>' . $name . '</h1><article><h2><a href="/first">First</a></h2><p>Summary.</p></article>', 'Archive footer ' . $name);
    }
    $add('author/adam.html', '/author/adam', '<h1>Author: <span>Adam</span></h1><article><h2><a href="/first">First</a></h2><p>Summary.</p></article>', 'Adam footer');
    $add('first.html', '/first', '<h1>First</h1><p>Full story.</p><a href="/category/programming">Programming</a><a href="/category/programming/wordpress">WordPress</a><a href="/author/adam">Adam</a>', 'Shared footer', array('post_type' => 'post'));
    if ($descendant) $add('category/programming/wordpress/about.html', '/category/programming/wordpress/about', '<h1>Collection details</h1>');
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
};
$plan = $compile();
$assert(2 === count($plan['taxonomy_entities']) && 1 === count($plan['author_entities']), 'Fixture proves nested taxonomy and author archives.');
$slugs = array_column($plan['templates'], 'slug');
foreach (array('programming', 'wordpress', 'adam') as $slug) $assert(!in_array('page-' . $slug, $slugs, true), 'No archive page override: ' . $slug);
$assert(in_array('page-special', $slugs, true), 'Regular-page footer override is preserved.');
$templates = array_column($plan['templates'], null, 'slug');
$assert(str_contains($templates['page']['canonical_block_markup'], '"slug":"footer"') && !str_contains($templates['page-special']['canonical_block_markup'], '"slug":"footer"'), 'Shared footer and regular-page exclusion are preserved.');
foreach ($plan['pages'] as $page) $assert(empty($page['synthetic']), 'Archive-only hierarchy parents are not emitted.');
foreach (array('routes', 'operations') as $kind) foreach ($plan[$kind] as $row) $assert(!str_starts_with($row['source_path'] ?? '', 'wordpress-site-plan/routes/'), 'No orphan hierarchy artifact in ' . $kind);
WordPressSitePlan::assertValid($plan);
$withChild = $compile(true);
$parents = array_column(array_filter($withChild['pages'], static fn(array $p): bool => !empty($p['synthetic'])), null, 'source_path');
$assert(isset($parents['wordpress-site-plan/routes/category.html']), 'A surviving descendant retains its required synthetic ancestor.');
WordPressSitePlan::assertValid($withChild);

$reject = static function (array $invalid, string $message) use ($assert): void {
    try { WordPressSitePlan::assertValid($invalid); } catch (InvalidArgumentException $e) { $assert($message === $e->getMessage(), 'Expected invariant, got: ' . $e->getMessage()); return; }
    throw new RuntimeException('Invalid plan accepted: ' . $message);
};
$invalid = $plan;
$template = $templates['page-special'];
$template['slug'] = 'page-absent';
$template['target_path'] = 'templates/page-absent.html';
$template['reconciliation_identity'] = WordPressSitePlan::identity('template', 'wordpress-site-plan/templates/page-absent.html', $template['target_path']);
$invalid['templates'][] = $template;
$reject($invalid, 'WordPress site plan page override must resolve to a materialized page.');
$invalid = $plan;
$invalid['pages'][] = $parents['wordpress-site-plan/routes/category.html'];
$reject($invalid, 'WordPress site plan synthetic parent must have a materialized child.');
echo "Archive documents leave no page artifacts assertions passed.\n";

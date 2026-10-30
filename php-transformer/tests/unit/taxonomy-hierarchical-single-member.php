<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TaxonomyProjection;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$documents = $routes = array();
$add = static function (string $route, string $name, bool $reciprocal = true) use (&$documents, &$routes): void {
    $source = trim($route, '/') . '.html';
    $post = '/stories/' . str_replace('/', '-', trim($route, '/'));
    $documents[] = array('source_path' => $source, 'html' => '<h1>' . $name . '</h1><article><h2><a href="' . $post . '">Story</a></h2><p>Summary.</p></article>');
    $documents[] = array('source_path' => trim($post, '/') . '.html', 'metadata' => array('post_type' => 'post'), 'html' => '<h1>Story</h1>' . ($reciprocal ? '<a href="' . $route . '">' . $name . '</a>' : ''));
    $routes[] = array('source_path' => $source, 'target_path' => $route);
    $routes[] = array('source_path' => trim($post, '/') . '.html', 'target_path' => $post);
};
// Deliberately reverse ancestry order to rule out document-order dependencies.
$add('/category/performing/acting/meisner', 'Meisner');
$add('/category/performing/acting', 'Acting');
$add('/category/performing', 'Performing');
$add('/category/writing', 'Writing');
$add('/category/missing/child', 'Child');
$add('/category/missing', 'Missing', false);
$add('/category/programming', 'Programming');
$add('/category/programming/shared', 'Shared');
$add('/category/performing/shared', 'Shared');
$documents[] = array('source_path' => 'acting-page-two.html', 'html' => '<h1>Acting</h1><a href="/extra">Extra story</a>');
$documents[] = array('source_path' => 'extra.html', 'metadata' => array('post_type' => 'post'), 'html' => '<a href="/category/performing/acting">Acting</a>');
$routes[] = array('source_path' => 'acting-page-two.html', 'target_path' => '/category/performing/acting/page/2');
$routes[] = array('source_path' => 'extra.html', 'target_path' => '/extra');
$result = TaxonomyProjection::project($documents, $routes);
$terms = array_column($result['entities'], null, 'slug');
$assert('performing' === $terms['acting']['parent'] && 'acting' === $terms['meisner']['parent'], 'Child and grandchild carry immediate proven parent slugs.');
$assert(!isset($terms['writing']['parent']) && 1 === count($terms['writing']['membership_source_paths']), 'One reciprocal post is sufficient.');
$assert(!isset($terms['child']) && !isset($terms['shared']), 'Missing parents and duplicate taxonomy-wide leaf slugs cannot prove terms.');
$assert(2 === count(array_filter($result['diagnostics'], static fn(array $d): bool => str_ends_with($d['code'], 'ambiguous_term'))), 'Both duplicate leaf claims remain ambiguous.');
$assert(str_contains(implode(' ', array_column($result['diagnostics'], 'message')), '/category/missing'), 'Missing-parent diagnostic names the parent route.');
$assert(in_array('extra.html', $terms['acting']['membership_source_paths'], true) && array('acting-page-two.html') === $result['pagination_source_paths_by_archive']['category/performing/acting.html'], 'Child pagination contributes membership and source ownership.');

// History investigation: 0535c3e5 introduced the two-member threshold without a
// concrete single-post false-positive fixture. Retain its actual safeguards:
// canonical post metadata, matching label, local origin, and reciprocal links.
$badDocuments = $documents;
foreach ($badDocuments as &$document) if ('stories/category-writing.html' === $document['source_path']) $document['html'] = '<a href="https://foreign.example/category/writing">Writing</a>';
unset($document);
$assert(!in_array('writing', array_column(TaxonomyProjection::project($badDocuments, $routes)['entities'], 'slug'), true), 'A foreign backlink cannot prove a single-member category.');

// Exercise emission and public plan validation, not just the projection helper.
$artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => '<h1>Home</h1>'));
foreach ($documents as $document) {
    $route = array_column($routes, 'target_path', 'source_path')[$document['source_path']];
    $artifact['files'][] = array('path' => $document['source_path'], 'content' => $document['html'], 'metadata' => array_merge($document['metadata'] ?? array(), array('route_path' => $route)));
}
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
$emitted = array_column($plan['taxonomy_entities'], null, 'slug');
$assert('performing' === ($emitted['acting']['parent'] ?? null) && 'acting' === ($emitted['meisner']['parent'] ?? null), 'Compiled plans retain hierarchy.');
WordPressSitePlan::assertValid($plan);
$validateEntities = new ReflectionMethod(WordPressSitePlan::class, 'assertTaxonomyEntities');
$validateEntities->invoke(null, array_reverse($plan['taxonomy_entities']), array_column($plan['pages'], null, 'source_path'));
$diagnostics = array();
$retained = TaxonomyProjection::retainProvenParents(array_values(array_filter($result['entities'], static fn(array $entity): bool => 'performing' !== $entity['slug'])), $diagnostics);
$assert(!in_array('acting', array_column($retained, 'slug'), true) && !in_array('meisner', array_column($retained, 'slug'), true), 'Missing proven parent entities prune all descendants, independently of presentation.');
foreach (array(null, 'absent', 'meisner', 42) as $invalidParent) {
    $invalid = $plan;
    foreach ($invalid['taxonomy_entities'] as &$entity) if ('acting' === $entity['slug']) $entity['parent'] = $invalidParent;
    unset($entity);
    try { $validateEntities->invoke(null, $invalid['taxonomy_entities'], array_column($invalid['pages'], null, 'source_path')); } catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Invalid parent was accepted.');
}
echo "Hierarchical and single-member taxonomy assertions passed.\n";

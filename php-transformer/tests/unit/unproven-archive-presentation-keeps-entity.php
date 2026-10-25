<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TaxonomyProjection;

$fixture = require dirname(__DIR__) . '/fixtures/taxonomy/corroborated-category.php';
// Source links and cards prove taxonomy membership independently of whether
// the compiled archive presentation can safely become a native query.
$documents = array();
$routes = array();
foreach ($fixture['files'] as $key => $file) {
    if (!is_array($file)) continue;
    $documents[] = array('source_path' => $file['path'], 'html' => $file['content'], 'metadata' => $file['metadata'], 'block_markup' => '<!-- wp:paragraph --><p>Unproven captured presentation</p><!-- /wp:paragraph -->');
    $routes[] = array('source_path' => $file['path'], 'target_path' => $file['metadata']['route_path'] ?? '/' . preg_replace('/\.html$/', '', $file['path']));
}
$projection = TaxonomyProjection::project($documents, $routes);
$entities = array_values(array_filter($projection['entities'], static fn(array $entity): bool => 'field-notes' === ($entity['slug'] ?? null)));
if (1 !== count($entities)) {
    fwrite(STDERR, "FAIL: Unproven archive presentation must not erase the taxonomy entity.\n");
    exit(1);
}
if (str_contains($entities[0]['archive']['presentation_markup'] ?? '', '<!-- wp:query ')) {
    fwrite(STDERR, "FAIL: Unproven archive cards must not manufacture a query loop.\n");
    exit(1);
}
echo "unproven-archive-presentation-keeps-entity: passed\n";

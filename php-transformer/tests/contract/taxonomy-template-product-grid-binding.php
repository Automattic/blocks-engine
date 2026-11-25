<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\BindingAnchors;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * A taxonomy archive that becomes a theme template keeps its commerce
 * collection binding through the final anchoring pass (blocks-engine#7405).
 * That pass sees the materialized template units, so each must carry its
 * source's projected markup: the binding's source anchor hashes a block of
 * that markup, not of the template's canonical markup, whose image and link
 * references are already projected.
 */
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = 'tag/field-notes/index.html';
$files = array('index.html' => '<main><a href="/cart/">Cart</a><h1>Home</h1></main>');
$cards = '';
foreach (array('one', 'two') as $slug) {
    $cards .= '<article><h2><a href="/posts/' . $slug . '/">Report ' . $slug . '</a></h2><p>Summary ' . $slug . '.</p></article>';
    $files['posts/' . $slug . '/index.html'] = '<main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">Report ' . $slug . '</h1><p>Full report.</p><a href="/tag/field-notes/">Field Notes</a></article></main>';
}
$products = '';
foreach (array('Alpha' => '10.00', 'Beta' => '11.00', 'Gamma' => '12.00', 'Delta' => '13.00') as $name => $price) {
    $products .= '<div class="card"><a href="/tag/field-notes/"><img src="/media/' . strtolower($name) . '.png" alt="' . $name . ' Kit"></a><h3>' . $name . ' Kit</h3><span class="price">$' . $price . '</span></div>';
    $files['media/' . strtolower($name) . '.png'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}
$files[$source] = '<main><a href="/cart/">Cart</a><h1>Field Notes</h1><section>' . $cards . '</section><div class="grid">' . $products . '</div></main>';
$surface = array('schema' => 'blocks-engine/template-surface/v1', 'role' => 'tag', 'slug' => 'tag-field-notes', 'logical_surface_id' => 'tag:tag-field-notes', 'responsive_variant_id' => 'default');

// A declared taxonomy surface, as captured collection archives arrive, and an
// archive found only through reciprocal membership.
foreach (array('declared surface' => true, 'reciprocal membership' => false) as $case => $declared) {
    $entries = array();
    foreach ($files as $path => $content) $entries[] = array('path' => $path, 'content' => $content) + ($declared && $source === $path ? array('metadata' => array('template_surface' => $surface)) : array());
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $entries))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $assert(array() !== $plan, $case . ': a site plan is emitted: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
    $assert(!in_array($source, array_column($plan['pages'], 'source_path'), true), $case . ': the archive source is not emitted as a page.');
    $template = array_column($plan['templates'], null, 'slug')['tag-field-notes'] ?? null;
    $assert(is_array($template) && $source === $template['source_path'], $case . ': the archive source is emitted as a taxonomy template.');
    foreach ($plan['templates'] as $emitted) foreach (array_keys($emitted) as $key) $assert(!str_starts_with((string) $key, '_'), $case . ': emitted templates carry no working key: ' . $key);
    $bindings = array();
    foreach (RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array()) as $declaration) {
        foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
            if ($source === ($binding['source_path'] ?? null) && in_array($binding['role'] ?? null, array('commerce_collection', 'commerce_product_grid'), true)) $bindings[] = $binding;
        }
    }
    $assert(array() !== $bindings, $case . ': the taxonomy template retains its commerce collection binding.');
    $markup = $template['canonical_block_markup'];
    $write = array_column($plan['writes'], null, 'target_path')[$template['target_path']];
    foreach ($bindings as $binding) {
        $assert(1 === substr_count($markup, $binding['search_block_markup']) && str_contains($binding['search_block_markup'], 'Alpha Kit'), $case . ': the binding identifies the exact grid in the template.');
        $assert(BindingAnchors::positionMatches($binding['position'], new BlockMarkupIndex($markup), $binding['search_block_markup']), $case . ': the binding position anchors to the template markup.');
        $assert(BindingAnchors::positionMatches($binding['position'], new BlockMarkupIndex($write['payload']['data']), $binding['search_block_markup']), $case . ': the anchor matches the emitted template write.');
    }
    WordPressSitePlan::assertValid($plan);
}
echo "Taxonomy template product grid binding contract passed.\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\BindingAnchors;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = 'tag/field-notes/index.html';
$files = array('index.html' => '<main><h1>Home</h1></main>');
$cards = '';
foreach (array('one', 'two') as $slug) {
    $cards .= '<article><h2><a href="/posts/' . $slug . '/">Report ' . $slug . '</a></h2><p>Summary ' . $slug . '.</p></article>';
    $files['posts/' . $slug . '/index.html'] = '<main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">Report ' . $slug . '</h1><p>Full report.</p><a href="/tag/field-notes/">Field Notes</a></article></main>';
}
$files[$source] = '<main><h1>Field Notes</h1><section>' . $cards . '</section><form method="post" action="/subscribe/"><label for="email">Email</label><input id="email" type="email" name="email"><button>Subscribe</button></form></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'A site plan is emitted: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$assert(!in_array($source, array_column($plan['pages'], 'source_path'), true), 'The archive source is not emitted as a page.');
$template = array_column($plan['templates'], null, 'slug')['tag-field-notes'];
$assert($source === $template['source_path'], 'Reciprocal membership emits the source as a taxonomy template.');
$bindings = array();
foreach (RuntimeDeclarations::materialize($plan['runtime_declarations'], $plan['runtime_records'] ?? array()) as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as $entity) foreach ($entity['bindings'] as $binding) $bindings[] = $binding;
}
$assert(1 === count($bindings), 'The taxonomy template retains its form binding.');
$binding = $bindings[0];
$assert($source === $binding['source_path'], 'The binding keeps the archive source path.');
$markup = $template['canonical_block_markup'];
$assert(1 === substr_count($markup, $binding['search_block_markup']) && 1 === $binding['occurrence'], 'The binding identifies the exact form occurrence.');
$assert(BindingAnchors::positionMatches($binding['position'], new BlockMarkupIndex($markup), $binding['search_block_markup']), 'The binding position anchors to the template markup.');
$write = array_column($plan['writes'], null, 'target_path')[$template['target_path']];
$assert(BindingAnchors::positionMatches($binding['position'], new BlockMarkupIndex($write['payload']['data']), $binding['search_block_markup']), 'The anchor also matches the emitted template write.');
$findings = array_values(array_filter($plan['diagnostics'], static fn(array $row): bool => 'runtime_entity_binding_unanchored' === ($row['code'] ?? null)));
$assert(array() === $findings, 'The template binding is not dropped: ' . json_encode($findings));
WordPressSitePlan::assertValid($plan);
echo "Taxonomy template form binding contract passed.\n";

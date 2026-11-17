<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$html = '<!doctype html><html><body><main><header><p>Studio</p></header><section><h1>Experiences</h1><blockquote><p>A wonderful visit.</p><footer><p>Client One</p></footer></blockquote><blockquote><p>Highly recommended.</p><footer><p>Client Two</p></footer></blockquote></section><footer><p>Studio copyright</p><form><label>Email<input type="email" name="email"></label><button type="submit">Subscribe</button></form></footer></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = (new WordPressSitePlan())->fromResult($result);
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['area']));
if (1 !== count($footers) || !str_contains($footers[0]['canonical_block_markup'], 'Studio copyright')) throw new RuntimeException('The document footer must become one template part despite testimonial citations.');
foreach ($plan['templates'] as $template) {
    if (!str_contains($template['canonical_block_markup'], '"slug":"' . $footers[0]['slug'] . '"')) throw new RuntimeException('Every template must place the document footer.');
}
foreach ($plan['pages'] as $page) {
    if (!str_contains($page['canonical_block_markup'], 'Client One') || str_contains($page['canonical_block_markup'], 'Studio copyright')) throw new RuntimeException('Citations must stay in content, and document chrome must leave it.');
}
$bindings = 0;
foreach ($plan['runtime_declarations'] as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) {
    if ('form' !== ($binding['role'] ?? null)) continue;
    ++$bindings;
    if ($footers[0]['source_path'] !== $binding['source_path'] || $footers[0]['source_path'] !== $entity['source_path']) throw new RuntimeException('The form must bind to the actual entry-shell source path.');
    $position = $binding['position'];
    if (substr($footers[0]['canonical_block_markup'], $position['offset'], $position['length']) !== $binding['search_block_markup']) throw new RuntimeException('The form binding must address the emitted footer markup.');
}
if (1 !== $bindings) throw new RuntimeException('The footer form binding must survive extraction exactly once.');
fwrite(STDOUT, "Page-edge footer with citations tests passed\n");

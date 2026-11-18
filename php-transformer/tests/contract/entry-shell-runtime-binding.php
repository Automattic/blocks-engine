<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = '<!doctype html><html><head><title>Atelier</title></head><body><div class="site"><header><a href="#intro">Atelier</a></header><main><h1 id="intro">Welcome</h1><p>Route content.</p><footer><p>Stay in touch</p><form><label>Email<input type="email" name="email"></label><button type="submit">Subscribe</button></form></footer></main></div></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['area']));
$assert(1 === count($footers), 'A single-entry footer with a movable form becomes a template part.');
$part = $footers[0];
$assert(!str_contains($plan['pages'][0]['canonical_block_markup'], '<footer') && str_contains($plan['pages'][0]['canonical_block_markup'], 'Route content.'), 'Page content excludes the footer but retains its body.');
$bindings = array();
foreach ($plan['runtime_declarations'] as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) $bindings[] = $binding;
$assert(1 === count($bindings), 'The form keeps exactly one runtime binding.');
$binding = $bindings[0];
$assert($part['source_path'] === $binding['source_path'], 'The form binding targets the actual entry part source, not a shared-part path.');
$position = $binding['position'];
$assert($binding['search_block_markup'] === substr($part['canonical_block_markup'], $position['offset'], $position['length']), 'The binding position resolves to its form fallback inside the part.');
echo "Entry shell runtime binding contract passed\n";

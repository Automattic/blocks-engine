<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$files = array();
foreach (array('index', 'about', 'work', 'shop') as $name) {
    $layout = 'shop' === $name ? 'column' : 'row';
    $files[$name . '.html'] = '<html><head><title>' . $name . '</title><style>header.chrome{background:#eee;padding:10px}.bar{display:flex;flex-direction:' . $layout . ';gap:12px}main{min-height:200px}</style></head><body><header class="chrome"><div class="bar"><a href="/">Shared brand</a><a href="/shop.html">Shop</a><form><label>Email<input type="email" name="email"></label><button type="submit">Subscribe</button></form></div></header><main><h1>' . $name . '</h1><p>Route content.</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null));
if (2 !== count($headers)) throw new RuntimeException('The unanchored route layout must have its own editable header part.');
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<header')) throw new RuntimeException($page['source_path'] . ' retains page-owned chrome.');
}
$parts = array_column($plan['template_parts'], null, 'source_path');
foreach ($plan['runtime_declarations'] as $declaration) {
    foreach ($declaration['payload']['entities'] ?? array() as $entity) {
        foreach ($entity['bindings'] ?? array() as $binding) {
            $part = $parts[$binding['source_path']] ?? null;
            if (null === $part) throw new RuntimeException('Header bindings must move with their route-local part.');
            $position = $binding['position'];
            if ($binding['search_block_markup'] !== substr($part['canonical_block_markup'], $position['offset'], $position['length'])) throw new RuntimeException('Hoisted binding must resolve inside the part.');
        }
    }
}
echo "unanchored-header-layout-variant: passed\n";

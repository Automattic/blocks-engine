<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A menu marks its served item with an additive utility (`text-primary`) that
// the page body also authors on ordinary content. The cross-route current-state
// proof belongs to the navigation item only: body content keeps its class.
$files = array();
foreach (array('index.html' => 'Home', 'shop/index.html' => 'Shop', 'about/index.html' => 'About') as $path => $current) {
    $link = static fn(string $label, string $url): string => '<a class="hover:text-foreground' . ($current === $label ? ' text-primary" aria-current="page' : '') . '" href="' . $url . '">' . $label . '</a>';
    $files[$path] = '<!doctype html><html><head><title>' . $current . '</title><style>.text-primary{color:#b8860b}.menu{display:flex;gap:16px}.price{font-size:30px}</style></head><body><header><nav class="menu">'
        . $link('Home', '/') . $link('Shop', '/shop/') . $link('About', '/about/')
        . '</nav></header><main><h1>' . $current . '</h1><p class="price text-primary">R 38 999,00</p></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['pages'] as $page) {
    if (!preg_match('/<p\b[^>]*class="([^"]*)"[^>]*>R 38 999,00<\/p>/', $page['canonical_block_markup'], $match)) throw new RuntimeException($page['source_path'] . ': price paragraph stays native.');
    if (!in_array('text-primary', explode(' ', $match[1]), true)) throw new RuntimeException($page['source_path'] . ': body content keeps its authored text-primary class: ' . $match[1]);
}
$header = implode('', array_column(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)), 'canonical_block_markup'));
$header .= implode('', array_column($plan['menus'], 'block_markup'));
if (str_contains($header, 'text-primary')) throw new RuntimeException('The shared menu must not freeze the captured current class.');
echo "additive-current-class-body-content: ok\n";

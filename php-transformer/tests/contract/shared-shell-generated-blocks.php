<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * A shared header whose menu becomes a generated component differs per route
 * (each marks its own current item), so the shared template part is compiled
 * from its own canonical variant. Every generated block that part references
 * ships in the same companion payload; otherwise the part renders nothing.
 */

$page = static function (string $route): string {
    $link = static fn (string $slug, string $href, string $label): string => '<a href="' . $href . '"' . ($slug === $route ? ' aria-current="page" class="cur"' : '') . '>' . $label . '</a>';
    return '<html><head><title>' . $route . '</title></head><body><div class="site"><header><p>Brand</p>'
        . '<x-menu class="menu-host" style="display:flex">' . $link('index', '/', 'Home') . $link('about', '/about/', 'About') . $link('contact', '/contact/', 'Contact') . '</x-menu>'
        . '</header><main><h1>' . $route . '</h1><p>' . str_repeat($route . ' text. ', 20) . '</p></main><footer><p>Foot</p></footer></div></body></html>';
};

$files = array();
foreach (array('index', 'about', 'contact') as $route) $files[$route . '.html'] = array('content' => $page($route));
$view = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView();

$shipped = array_map(static fn (array $block): string => (string) ($block['name'] ?? ''), $view['companion_plugin_payload']['blocks'] ?? array());
$referenced = array();
foreach ($view['wordpress_site_plan']['template_parts'] as $part) {
    preg_match_all('#<!-- wp:custom/([a-z0-9-]+)#', (string) $part['canonical_block_markup'], $matches);
    foreach ($matches[1] as $name) $referenced[$name] = $part['slug'];
}

if (array() === $referenced) throw new RuntimeException('The shared header part references its generated menu component.');
foreach ($referenced as $name => $slug) {
    if (!in_array($name, $shipped, true)) throw new RuntimeException("Template part $slug references generated block $name that the companion payload does not ship.");
}
echo "shared-shell-generated-blocks: ok\n";

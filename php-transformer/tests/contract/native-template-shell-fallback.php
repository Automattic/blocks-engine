<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// The home page links its own sections in place and leaves out its own nav
// item; the inner page links back to them. Each route keeps its chrome, but
// templates with no source route (404, archives, a store's cart and product
// templates that include the theme's header and footer) still get the site's
// header and footer, from the inner route whose links leave the page.
$chrome = static fn(string $home, array $nav): string => '<header class="site-header"><a class="brand" href="' . $home . '">Kuru</a><nav>' . implode('', array_map(static fn(string $href, string $label): string => '<a href="' . $href . '">' . $label . '</a>', array_keys($nav), $nav)) . '</nav></header>';
$footer = static fn(string $home): string => '<footer class="site-footer"><p>Natural cosmetics.</p><a href="' . $home . '">Inicio</a><a href="https://example.org/ig">Instagram</a></footer>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $chrome('#top', array('catalogo.html' => 'Catálogo', '#quienes' => 'Quiénes somos')) . '<main><h1>Home</h1><section id="quienes"><p>About us.</p></section></main>' . $footer('#inicio'),
    'catalogo.html' => $chrome('index.html', array('index.html' => 'Inicio', 'catalogo.html' => 'Catálogo', 'index.html#quienes' => 'Quiénes somos')) . '<main><h1>Catalog</h1></main>' . $footer('index.html'),
    'aviso.html' => '<main><h1>Privacy</h1><p>Legal text.</p></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = ThemeTemplates::rendered($plan);
foreach (array('header', 'footer') as $area) {
    $parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => $area === $part['area']));
    $assert(1 === count($parts) && $area === $parts[0]['slug'] && 'catalogo.html' === $parts[0]['placement']['source_path'], "The inner route's {$area} becomes the theme's {$area} part.");
    $assert(array() === array_diff($parts[0]['placement']['template_slugs'], WordPressSitePlan::NATIVE_QUERY_TEMPLATE_SLUGS), "The {$area} part binds only to templates without a source route.");
    $assert(str_contains($templates['404'], '"slug":"' . $area . '"') && !str_contains($templates['page'] ?? '', '"slug":"' . $area . '"') && !str_contains($templates['front-page'] ?? '', '"slug":"' . $area . '"'), "404 renders the {$area}; routes render only their own.");
}
foreach ($plan['pages'] as $page) $assert(('aviso.html' === $page['source_path']) !== str_contains($page['canonical_block_markup'], '"tagName":"header"'), "{$page['source_path']} keeps exactly its own source chrome.");

// Chrome that differs in anything but internal link targets is route content.
$routeContent = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $chrome('#top', array('catalogo.html' => 'Catálogo')) . '<main><h1>Home</h1></main><footer class="site-footer"><p>© Kuru Home</p></footer>',
    'catalogo.html' => $chrome('index.html', array('index.html' => 'Inicio')) . '<main><h1>Catalog</h1></main><footer class="site-footer"><p>© Kuru Catalog</p></footer>',
    'aviso.html' => '<main><h1>Privacy</h1></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$assert(array() === array_filter($routeContent['template_parts'], static fn(array $part): bool => 'footer' === $part['area']), 'A footer whose text differs per route is not given to templates without a route.');

echo "native-template-shell-fallback: passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SourceShells;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Issue #6683: a layout wrapper above the menu list sits beside an unrelated
// placeholder. Sibling class intersection belongs to the menu-item level only,
// so the wrapper keeps its classes whether or not a route has a current item.
$header = static fn(string $about, string $focus = ''): string => '<header><nav><div id="main-nav" class="w-100"></div><div class="container-x navbar w-100 pt-2"><ul class="navbar-nav">'
    . '<li class="nav-item"><a class="nav-link dropdown-toggle' . $about . '" data-bs-toggle="collapse">About</a></li>'
    . '<li class="nav-item"><a class="nav-link dropdown-toggle' . $focus . '" data-bs-toggle="collapse">Focus</a></li>'
    . '<li class="nav-item"><a class="nav-link" href="/contact/">Contact</a></li></ul></div></nav></header>';
$resting = SourceShells::identity($header(''));
$assert($resting === SourceShells::identity($header(' active')), 'A current item does not trim classes from layout wrappers above the menu.');
$assert($resting === SourceShells::identity($header('', ' active')), 'Each current section yields the resting identity.');
$assert(str_contains($resting, 'container-x navbar w-100 pt-2'), 'The layout wrapper keeps its classes.');

$files = array();
foreach (array('index' => 'Home', 'about' => 'About', 'focus' => 'Focus', 'contact' => 'Contact') as $route => $title) {
    $files[('index' === $route ? 'index' : $route . '/index') . '.html'] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . ' | Acme</title><style>.navbar{display:flex;gap:2rem}.navbar-nav{display:flex;gap:1rem;list-style:none}.nav-link.active{text-decoration:underline}</style></head><body>'
        . $header('about' === $route ? ' active' : '', 'focus' === $route ? ' active' : '')
        . '<main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main><footer><p>© Acme</p></footer></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Resting and section routes share one header part.');
$assert(4 === count($plan['pages']), 'Every route compiles to a page.');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $template = ThemeTemplates::rendered($plan)[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page')] ?? '';
    $assert(str_contains($template . $markup, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), $page['source_path'] . ' references the shared header.');
    $assert(!str_contains($markup, '<header'), $page['source_path'] . ' keeps no header in post content.');
}
echo "navigation-current-layout-ancestor-shared-header contract passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The header is styled by authored class and id rules that each page carries in
// its own inline <style>, so every copy is page-scoped. Once the header becomes
// one shared template part, those rules must reach every page that renders it.
// The route owns `.route-grid`, which must stay route-scoped.
$css = '#site-chrome{position:sticky;top:0}.site-header{display:flex;gap:24px;background:#dddcff}'
    . '.site-header .brand{font-size:28px;color:#275f49}'
    . '@media (max-width:700px){.site-header .brand{font-size:20px}}'
    . '.route-grid{display:grid;grid-template-columns:1fr 1fr}';
$header = static fn (string $home, string $about): string => '<header id="site-chrome" class="site-header"><p class="brand">Acme</p>'
    . '<nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav></header>';
$document = static fn (string $headerHtml, string $main): string => '<!doctype html><html><head><style>' . $css . '</style></head><body>'
    . $headerHtml . '<div class="route-grid">' . $main . '</div></body></html>';

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document($header('index.html', 'about.html'), '<main><h1>Home</h1></main>'),
        'about.html' => $document($header('index.html', 'about.html'), '<main><h1>About</h1></main>'),
        'team.html' => $document($header('index.html', 'about.html'), '<main><h1>Team</h1></main>'),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$shared = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)));
$assert(array() !== $shared, 'The identical header is extracted as a shared template part.');
$partMarkup = implode('', array_column($shared, 'canonical_block_markup'));
$assert(str_contains($partMarkup, 'site-header') && str_contains($partMarkup, 'brand'), 'The shared part keeps the authored header classes.');

$global = '';
$route = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    $isGlobal = array(array('kind' => 'global')) === ($asset['scopes'] ?? null);
    if ($isGlobal) $global .= (string) ($asset['content'] ?? ''); else $route .= (string) ($asset['content'] ?? '');
}
$assert(str_contains($global, '.site-header{display:flex'), 'The authored header rule reaches a globally scoped stylesheet.');
$assert(1 === preg_match('/\.site-header \.brand\{font-size:28px/', $global), 'Descendant rules for header content reach the global stylesheet.');
$assert(1 === preg_match('/@media \(max-width:700px\)\s*\{\s*\.site-header \.brand\{font-size:20px\}\s*\}/', $global), 'Responsive header rules keep their media condition in the global stylesheet.');
$assert(str_contains($global, '#site-chrome{position:sticky'), 'An authored id rule for the header reaches the global stylesheet.');
$assert(! str_contains($global, '.route-grid'), 'Route-owned layout rules stay out of the global shared stylesheet.');
$assert(str_contains($route, '.route-grid'), 'Route-owned rules stay on their route stylesheet.');

echo "Shared chrome authored rules contract passed.\n";

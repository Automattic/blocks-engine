<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

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
    . '#site-root .site-header .brand{letter-spacing:2px}'
    . '.container{max-width:960px}'
    . '.route-grid{display:grid;grid-template-columns:1fr 1fr}';
$header = static fn (string $home, string $about): string => '<header id="site-chrome" class="site-header"><p class="brand">Acme</p>'
    . '<div class="container"><nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav></div></header>';
$document = static fn (string $headerHtml, string $main): string => '<!doctype html><html><head><style>' . $css . '</style></head><body>'
    . '<div id="site-root">' . $headerHtml . '<div class="route-grid"><div class="container">' . $main . '</div></div></div></body></html>';

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
$assert(! str_contains($global, '.container{'), 'A class the header shares with route content stays out of the global shared stylesheet.');
$assert(str_contains($route, '.container{max-width:960px}'), 'A class the header shares with route content keeps its rule on the route stylesheet.');
// In-place shared content retains the ancestor the author selector names.
$assert(str_contains($global, '#site-root .site-header .brand{letter-spacing:2px}') && !str_contains($global, ':has(> #site-chrome)'), 'In-place header ancestry needs no detached-context selector compensation: ' . substr($global, 0, 600));
$assert(str_contains($route, '.route-grid'), 'Route-owned rules stay on their route stylesheet.');

// Pages carry the same header rule in stylesheets with different media
// conditions. Identical projected text under different enqueue contracts is two
// assets, so their content-addressed targets must not collide.
$mediaDocument = static fn (string $media, string $main): string => '<!doctype html><html><head><style media="' . $media . '">.site-header{background:#eee}.route-grid{display:grid}</style></head><body>'
    . '<header id="site-chrome" class="site-header"><p class="brand">Acme</p><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>'
    . '<div class="route-grid">' . $main . '</div></body></html>';
$mediaResult = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $mediaDocument('(min-width:768px)', '<main><h1>Home</h1></main>'),
        'about.html' => $mediaDocument('(max-width:767px)', '<main><h1>About</h1></main>'),
        'team.html' => $mediaDocument('(max-width:767px)', '<main><h1>Team</h1></main>'),
    ),
));
$mediaPlan = $mediaResult->toWordPressSitePlanView()['wordpress_site_plan'] ?? null;
$assert(is_array($mediaPlan) && array() !== ($mediaPlan['assets'] ?? array()), 'Identical header rules under different media compile without colliding targets: ' . json_encode(array_values(array_filter(array_column($mediaResult->toArray()['diagnostics'] ?? array(), 'message'), static fn ($m): bool => str_contains((string) $m, 'colliding')))));
$mediaShared = array_values(array_filter($mediaPlan['assets'], static fn (array $asset): bool => str_contains((string) ($asset['target_path'] ?? ''), 'shared-chrome-') && str_contains((string) ($asset['content'] ?? ''), '.site-header{background:#eee}')));
$assert(2 === count($mediaShared) && 2 === count(array_unique(array_column($mediaShared, 'target_path'))) && array('(max-width:767px)', '(min-width:768px)') === (static function (array $m): array { sort($m); return $m; })(array_map(static fn (array $a): string => (string) ($a['media'] ?? ''), $mediaShared)), 'Each media condition keeps its own shared stylesheet: ' . json_encode(array_map(static fn (array $a): array => array($a['target_path'] ?? null, $a['media'] ?? null), $mediaShared)));

// The engine rewrites an ancestor id into an editor-parity compound; its
// positive hooks still identify the detached ancestor.
$reanchor = new ReflectionMethod(WordPressSitePlan::class, 'reanchoredDetachedContextSelector');
$context = array('class' => array(), 'id' => array('page-root' => true));
$assert(':is(:where(.blocks-engine-editor-anchor-page-root):not(#blocks-engine-specificity-id-site-0),:where(:has(> #site-chrome))) :where(.menu) .item' === $reanchor->invoke(null, ':where(.blocks-engine-editor-anchor-page-root):not(#blocks-engine-specificity-id-site-0) :where(.menu) .item', $context, array('site-chrome')), 'An engine-rewritten ancestor id is re-anchored on the part wrapper.');
$assert('.page-only .item' === $reanchor->invoke(null, '.page-only .item', $context, array('site-chrome')), 'An ancestor the chrome never sat under is left alone.');
$assert('#site-chrome .item' === $reanchor->invoke(null, '#site-chrome .item', $context, array('site-chrome')), 'A selector that starts at the chrome itself needs no re-anchoring.');
$assert(':is(#page-root,:where(:has(> #site-chrome))) .item' === $reanchor->invoke(null, '#page-root .item', $context, array('site-chrome')), 'The source id and its editor-anchor class name the same detached ancestor.');
$assert('.wp-site-blocks .item' === $reanchor->invoke(null, '.wp-site-blocks .item', $context, array('site-chrome')), 'A utility-class ancestor names no detached ancestor.');

// A navigation link keeps its classes only in its block comment. Its authored
// class still identifies header rules; an editor-anchor class stands for its id.
$hooks = (new ReflectionMethod(WordPressSitePlan::class, 'authoredChromeHooks'))->invoke(null, '<!-- wp:group {"anchor":"top","className":"bar blocks-engine-editor-anchor-top wp-block-x"} --><div id="top" class="wp-block-group bar"><!-- wp:navigation-link {"className":"item has-x","label":"A","url":"/"} /--></div><!-- /wp:group -->');
$assert(array('bar' => true, 'item' => true) == $hooks['class'] && array('top' => true) === $hooks['id'], 'Chrome hooks come from HTML and block attributes, without utility classes: ' . json_encode($hooks));

echo "Shared chrome authored rules contract passed.\n";

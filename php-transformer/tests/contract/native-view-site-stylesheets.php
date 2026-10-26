<?php
declare(strict_types=1);

/**
 * #3160: a stylesheet linked by every canonical source document is site-wide.
 *
 * WordPress-native views that no source document owns (404, search, archives)
 * render the imported shared chrome. Chrome rules are lifted into global
 * shared-chrome stylesheets, but the rules for a hook that page bodies also
 * use stay on the route stylesheets — so the header depends on the stylesheet
 * every page links. That stylesheet took one page scope per referencing
 * document, so the route-flag fallback never carried it and a native view
 * loaded the chrome without the author CSS that dresses it.
 *
 * The plan now scopes a stylesheet every canonical document links as global:
 * route documents keep their per-occurrence instance enqueues, and native
 * views load it through the existing `! $blocks_engine_route_styles` fallback
 * in source order, ahead of the shared-chrome fallbacks lifted from it. A
 * stylesheet only some documents link stays route-only. The editor canvas
 * matcher gains the same global fallback entry, and the theme.json unanimity
 * gate reads the site-wide scope without changing what it promotes.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// `header-inner` is a header element's hook that page bodies also use, so
// shared-chrome extraction keeps its rules on the route stylesheet: the header
// depends on the stylesheet every page links, not only on the lifted chrome
// projection. The body and root rules give the theme.json unanimity gate and
// the editor-canvas matcher something to read from the same resource.
$css = '.site-header{display:flex;justify-content:space-between;padding:16px}.header-inner{max-width:960px;margin:0 auto}.site-nav{display:flex;gap:12px}body{line-height:1.6}:root{--page-gutter:12px}';
$header = static fn(): string => '<header class="site-header"><div class="header-inner"><nav class="site-nav"><a href="index.html">Home</a><a href="about.html">About</a></nav></div></header>';
// No main landmark follows the header shell, so no generic page frame hoists:
// nothing lends the frame author's stylesheets to native views. Whatever the
// native view loads below, it loads as site-wide author CSS.
$document = static fn(string $head, string $body): string => '<!doctype html><html><head>' . $head . '</head><body><div id="site-root"><div id="masterPage">' . $header() . '<div id="PAGES_CONTAINER">' . $body . '</div></div></div></body></html>';

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('<link rel="stylesheet" href="site.css"><link rel="stylesheet" href="extra.css">', '<div class="header-inner"><h1>Home</h1></div><div class="promo">Featured</div>'),
        'about.html' => $document('<link rel="stylesheet" href="site.css">', '<div class="header-inner"><h1>About</h1></div>'),
        'site.css' => array('path' => 'site.css', 'kind' => 'css', 'content' => $css),
        'extra.css' => array('path' => 'extra.css', 'kind' => 'css', 'content' => '.promo{color:#345678}'),
    ),
))->toArray()['source_reports']['wordpress_site_plan'];

$siteAsset = null;
$extraAsset = null;
$chromeAssets = array();
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    $target = (string) ($asset['target_path'] ?? '');
    if (str_ends_with($target, '/site.css')) $siteAsset = $asset;
    elseif (str_ends_with($target, '/extra.css')) $extraAsset = $asset;
    elseif (preg_match('~/shared-chrome-[a-f0-9]{16}\.css$~', $target)) $chromeAssets[] = $asset;
}
$assert(null !== $siteAsset && null !== $extraAsset && array() !== $chromeAssets, 'The fixture compiles with both linked stylesheets and a shared-chrome projection.');
$sharedParts = array_values(array_filter(
    $plan['template_parts'],
    static fn (array $part): bool => in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)
));
$assert(array() !== $sharedParts, 'The identical header across both documents is extracted as a shared template part.');

$siteTarget = (string) $siteAsset['target_path'];
$extraTarget = (string) $extraAsset['target_path'];
$chromeTargets = array_map(static fn (array $asset): string => (string) $asset['target_path'], $chromeAssets);
$chromeCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $chromeAssets));
$assert(array(array('kind' => 'global')) === ($siteAsset['scopes'] ?? null), 'The stylesheet every canonical document links is scoped site-wide: ' . json_encode($siteAsset['scopes'] ?? null));
$assert(str_contains((string) $siteAsset['content'], '.header-inner{'), 'The header hook the page bodies also use stays on the site-wide stylesheet.');
$assert(str_contains($chromeCss, '.site-nav{') && ! str_contains($chromeCss, '.header-inner{'), 'Only the header-only hooks were lifted into the shared-chrome projection.');
$extraScopes = $extraAsset['scopes'] ?? array();
$assert(array() !== $extraScopes && array() === array_filter($extraScopes, static fn (array $scope): bool => 'global' === ($scope['kind'] ?? null)) && 1 === count($extraScopes) && 'index.html' === ($extraScopes[0]['source_path'] ?? null), 'A stylesheet only one document links keeps its single page scope: ' . json_encode($extraScopes));

// Scopes alone do not prove publication: read the generated bootstrap. Route
// blocks list each document's instance enqueues; the guarded lines load on
// WordPress-native views that no source document owns.
$bootstrap = (string) (array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'] ?? '');
$frontend = (string) strstr($bootstrap, "add_action( 'wp_enqueue_scripts'");
$frontend = substr($frontend, 0, (int) strpos($frontend, '}, 1 );'));
$assert(str_contains($frontend, '$blocks_engine_route_styles = false;'), 'The route flag guards the fallback run.');

preg_match_all('/^    if \( (.+?) \) \{\n((?:        [^\n]+\n)+)    \}$/m', $frontend, $blocks, PREG_SET_ORDER);
$routes = array();
foreach ($blocks as $block) {
    preg_match_all("/wp_enqueue_style\\( '[^']+', get_theme_file_uri\\( '([^']+)' \\)/", $block[2], $calls);
    $routes[$block[1]] = $calls[1];
}
preg_match_all("/^    if \( ! \\\$blocks_engine_route_styles \) wp_enqueue_style\( '([^']+)', get_theme_file_uri\( '([^']+)' \)/m", $frontend, $guarded, PREG_SET_ORDER);
$nativeOrder = array_map(static fn (array $line): string => $line[2], $guarded);

$frontCondition = (string) (array_values(array_filter(array_keys($routes), static fn (string $condition): bool => str_contains($condition, 'is_front_page()')))[0] ?? '');
$aboutCondition = (string) (array_values(array_filter(array_keys($routes), static fn (string $condition): bool => str_contains($condition, "'about' === trim( get_page_uri")))[0] ?? '');
$assert('' !== $frontCondition && '' !== $aboutCondition && 2 === count($routes), 'Each canonical document keeps its own route block.');
foreach (array($frontCondition, $aboutCondition) as $condition) {
    $files = $routes[$condition];
    $assert(1 === count(array_keys($files, $siteTarget, true)), 'A route document keeps its per-occurrence site stylesheet instance: ' . $condition);
    $assert(array_search($siteTarget, $files, true) < array_search($chromeTargets[0], $files, true), 'The shared chrome still follows its contributing stylesheet on ' . $condition);
}
$assert(in_array($extraTarget, $routes[$frontCondition], true) && ! in_array($extraTarget, $routes[$aboutCondition], true), 'The one-document stylesheet still enqueues only on its own route.');

$siteFallbacks = array_keys(array_filter($nativeOrder, static fn (string $target): bool => $target === $siteTarget));
$chromeFallbacks = array_keys(array_filter($nativeOrder, static fn (string $target): bool => in_array($target, $chromeTargets, true)));
$assert(array() !== $chromeFallbacks, 'The shared-chrome fallbacks load on WordPress-native views.');
$assert(array() !== $siteFallbacks, 'The site-wide stylesheet loads on WordPress-native views through the route-flag fallback: ' . json_encode($nativeOrder));
$assert($siteFallbacks[0] < $chromeFallbacks[0], 'The site-wide stylesheet loads in source order, ahead of the shared-chrome fallbacks lifted from it: ' . json_encode($nativeOrder));
$assert(! in_array($extraTarget, $nativeOrder, true), 'The one-document stylesheet is not fallen back to native views: ' . json_encode($nativeOrder));

preg_match('/\$blocks_engine_presentation_styles = (array \(.*?\n\));\n/s', $bootstrap, $editor);
$editorStyles = isset($editor[1]) ? eval('return ' . $editor[1] . ';') : array();
$siteEditorScopes = array();
$extraEditorScopes = array();
foreach ($editorStyles as $style) {
    $target = (string) ($style['target_path'] ?? '');
    $scope = $style['scopes'][0]['kind'] . (isset($style['scopes'][0]['source_path']) ? ':' . $style['scopes'][0]['source_path'] : '') . (! empty($style['fallback']) ? ':fallback' : '');
    if (str_ends_with($target, '/site.css')) $siteEditorScopes[] = $scope;
    elseif ($target === $extraTarget) $extraEditorScopes[] = $scope;
}
$assert(in_array('page:index.html', $siteEditorScopes, true) && in_array('page:about.html', $siteEditorScopes, true), 'Route canvases still match the site-wide stylesheet by their own document: ' . json_encode($siteEditorScopes));
$assert(in_array('global:fallback', $siteEditorScopes, true), 'A canvas for a document that owns no styles falls back to the site-wide stylesheet: ' . json_encode($siteEditorScopes));
$assert(array('page:index.html') === $extraEditorScopes, 'The one-document stylesheet keeps its page-scoped editor entry without a fallback: ' . json_encode($extraEditorScopes));

$theme = json_decode((string) (array_column($plan['writes'], null, 'target_path')['theme.json']['payload']['data'] ?? ''), true);
$assert(is_array($theme) && '1.6' === ($theme['styles']['typography']['lineHeight'] ?? null), 'The theme.json unanimity gate still promotes the site-wide body rule: ' . json_encode($theme['styles']['typography'] ?? null));

fwrite(STDOUT, "native-view-site-stylesheets contract passed\n");

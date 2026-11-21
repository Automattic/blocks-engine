<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\StylesheetScopes;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array('site.css' => '.all-copy{width:601px;padding:13px}.subset-copy{width:602px;padding:14px}.unique-copy{width:603px;padding:15px}.other-subset{width:604px;padding:16px}');
$owners = array();
for ($i = 0; $i < 6; ++$i) {
    $path = $i ? 'page-' . $i . '.html' : 'index.html';
    $owners[] = $path;
    $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><div class="all-copy"><p>All pages</p></div>' . ($i < 3 ? '<div class="subset-copy"><p>Subset</p></div>' : '') . ($i >= 2 && $i <= 4 ? '<div class="other-subset"><p>Other subset</p></div>' : '') . (1 === $i ? '<div class="unique-copy"><p>Unique</p></div>' : '') . '</main></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$support = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'engine-support' === ($asset['source'] ?? '')));
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
echo 'Reduction: ' . count($support) . ' support files, ' . array_sum(array_map(static fn(array $asset): int => strlen($asset['content']), $support)) . ' support bytes, ' . strlen($bootstrap) . " functions.php bytes\n";
$byTarget = array_column($plan['assets'], null, 'target_path');
$markup = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
$supportByTarget = array_column($support, null, 'target_path');
$routeSupport = static function (string $owner) use ($plan, $supportByTarget): array {
    $loaded = array();
    foreach (ThemeBootstrap::documentStylesheets($plan, $owner) as $stylesheet) if (isset($supportByTarget[$stylesheet['target_path']])) $loaded[] = $supportByTarget[$stylesheet['target_path']];
    return $loaded;
};
foreach (array('all-copy' => $owners, 'subset-copy' => array_slice($owners, 0, 3), 'other-subset' => array_slice($owners, 2, 3), 'unique-copy' => array($owners[1])) as $class => $consumers) {
    $pattern = '/(?:^|[},])[^{}]*\.' . $class . '(?=[,{])[^{}]*\{box-sizing:content-box\}/';
    foreach ($owners as $owner) {
        $loads = 1 === preg_match($pattern, implode("\n", array_column($routeSupport($owner), 'content')));
        // Support never reaches a route outside its consumers (#6458).
        $assert(in_array($owner, $consumers, true) === $loads, 'Support loads on exactly its consumers: ' . $class . ' ' . $owner);
    }
}
$allCopy = array_values(array_filter($support, static fn(array $asset): bool => str_contains($asset['content'], '.all-copy{box-sizing:content-box}')));
$assert(1 === count($allCopy) && array(array('kind' => 'global')) === $allCopy[0]['scopes'], 'Support every route loads is written once, globally.');
// Each route loads the global sheet and one route bundle: the five distinct
// bundles (two routes share a byte-identical one) plus the global sheet.
$assert(5 === count($support), 'Support folds into one bundle per route: ' . count($support));
$assert(count($support) === count(array_unique(array_column($support, 'content'))), 'No two support resources are byte-identical.');
$assert(str_contains($bootstrap, "if ( true )"), 'Global support also loads on WordPress-native routes.');
foreach ($owners as $owner) {
    $loaded = $routeSupport($owner);
    $assert(count(array_filter($loaded, static fn(array $asset): bool => 'global' !== $asset['scopes'][0]['kind'])) <= 1, 'Each route loads at most one route-owned support sheet: ' . $owner);
    $seen = array();
    foreach ($loaded as $asset) {
        $assert('' !== trim($asset['content']), 'Support resources are never empty.');
        $tree = new Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree($asset['content']);
        foreach ($tree->rules() as $row) {
            $rule = trim(substr($asset['content'], $row['start'], $row['end'] - $row['start'] + 1));
            $assert(!isset($seen[$rule]), 'No route loads a support rule twice.');
            $seen[$rule] = true;
        }
    }
}
foreach ($plan['assets'] as $asset) if (in_array($asset['source'] ?? '', array('engine-support', 'editor-static-state'), true)) {
    $assert('' !== trim($asset['content']), 'Generated CSS resources are never empty.');
    $writes = array_column($plan['writes'], null, 'target_path');
    $assert('' !== trim($writes[$asset['target_path']]['payload']['data'] ?? ''), 'Generated CSS writes are never empty.');
}
foreach ($owners as $owner) {
    $phase = 0;
    foreach (ThemeBootstrap::documentStylesheets($plan, $owner) as $sheet) {
        $asset = $byTarget[$sheet['target_path']];
        $next = 'engine-support' !== ($asset['source'] ?? '') ? 1 : ('before-author' === ($asset['stylesheet_placement'] ?? '') ? 0 : 2);
        $assert($next >= $phase, 'Document cascade remains before support, author, after support.');
        $phase = $next;
    }
}
preg_match("/add_action\( 'wp_enqueue_scripts', static function \(\): void \{(.*?)\n\}, 1 \);/s", $bootstrap, $callback);
$assert(1 === substr_count($callback[1] ?? '', ThemeBootstrap::DOCUMENT_ID_EXPRESSION), 'Styles resolve the current document exactly once.');
// Home-only geometry precedes a shared button reset, then a home-only modifier.
$cascadeFiles = array('site.css' => 'body{font-family:Georgia,serif}.intro p{margin:0 0 30px}.btn-pill{display:inline-flex;align-items:center;gap:8px;padding:16px 28px;border:1px solid #0e59f2;border-radius:50px;color:#051634;text-decoration:none}.btn-plain{display:inline;padding:6px 12px;border:1px solid #0e59f2;color:#0e59f2;text-decoration:none}.thumb{width:106px}.roomy img{width:100%;height:auto}');
for ($i = 0; $i < 4; ++$i) {
    $path = $i ? 'page-' . $i . '.html' : 'index.html';
    $cascadeFiles[$path] = '<!doctype html><html><head><title>Page ' . $i . '</title><link rel="stylesheet" href="site.css"></head><body><main>'
        . ($i ? '' : '<div class="thumb"><img src="a.png" width="106" height="68" alt="A"></div>')
        . '<div class="intro"><h2>Heading ' . $i . '</h2><p>Copy.</p><a class="' . ($i ? 'btn-plain' : 'btn-pill') . '" href="/x/">More</a></div></main></body></html>';
}
$cascadePlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $cascadeFiles))->toArray()['source_reports']['wordpress_site_plan'];
$cascadeAssets = array_column($cascadePlan['assets'], null, 'target_path');
$cascadeCss = '';
foreach (ThemeBootstrap::documentStylesheets($cascadePlan, 'index.html') as $stylesheet) {
    $asset = $cascadeAssets[$stylesheet['target_path']];
    if ('engine-support' === ($asset['source'] ?? '')) $cascadeCss .= $asset['content'];
}
$reset = strpos($cascadeCss, ':where(.blocks-engine-layout-child-button)>.wp-block-button__link{display:inline!important');
$modifier = strpos($cascadeCss, '-display-inline-flex)>.wp-block-button__link{display:inline-flex!important}');
$assert(false !== $reset && false !== $modifier && $modifier > $reset, 'The delivered inline-flex modifier follows the generic inline reset.');
echo "Support cascade order: PASS\n";
$realisticFiles = require __DIR__ . '/support-owner-set-realistic-fixture.php';
$realistic = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $realisticFiles))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($realistic);
$writes = array_column($realistic['writes'], null, 'target_path');
$repairs = 0;
foreach ($realistic['assets'] as $asset) {
    if (!in_array($asset['source'] ?? '', array('engine-support', 'editor-static-state'), true)) continue;
    if ('editor-static-state' === $asset['source']) ++$repairs;
    $assert('' !== trim($asset['content']), 'Realistic support and repair resources are never empty.');
    $assert('' !== trim($writes[$asset['target_path']]['payload']['data'] ?? ''), 'Realistic support and repair writes are never empty.');
}
$assert($repairs > 0, 'The realistic fixture exercises editor repair sharing.');
// A route's support folds only with its own owner set, never into a resource
// other routes load, even where its selectors match there too.
$fold = new ReflectionMethod(StylesheetScopes::class, 'foldSupport');
$documents = array();
foreach (array('index.html', 'page-1.html') as $source) {
    $documents[$source] = array('source_path' => $source, 'canonical_block_markup' => '<div class="shared-x"></div><div class="y"></div>');
}
$sheet = static fn(string $content, array $scopes): array => array('source' => 'engine-support', 'kind' => 'css', 'content' => $content, 'scopes' => $scopes, 'stylesheet_placement' => 'after-author', 'source_path' => 'assets/css/engine-support-a.css', 'target_path' => 'assets/assets/css/engine-support-a.css');
$home = array(array('kind' => 'page', 'source_path' => 'index.html'));
$global = array(array('kind' => 'global'));
$folded = $fold->invoke(null, array($sheet('.shared-x{color:red}', $home), $sheet('.y{color:blue}', $global)), $documents, array_keys($documents));
$assert(2 === count($folded) && '.shared-x{color:red}' === $folded[0]['content'] && $home === $folded[0]['scopes'] && $global === $folded[1]['scopes'], 'A rule live outside its owners stays on its owners: ' . json_encode($folded));
// A later owner rule joins its owner set's fold ahead of an intervening
// global sheet only when no element both style shares a property family.
$folded = $fold->invoke(null, array($sheet('.shared-x{color:red}', $home), $sheet('.y{color:blue}', $global), $sheet('.shared-x{margin:0}', $home)), $documents, array_keys($documents));
$assert(2 === count($folded) && '.shared-x{color:red}.shared-x{margin:0}' === $folded[0]['content'] && $home === $folded[0]['scopes'] && '.y{color:blue}' === $folded[1]['content'], 'Commuting owner rules fold into one owner resource: ' . json_encode($folded));
$folded = $fold->invoke(null, array($sheet('.shared-x{color:red}', $home), $sheet('.shared-x{color:blue}', $global), $sheet('.shared-x{color:green}', $home)), $documents, array_keys($documents));
$assert(3 === count($folded) && array('.shared-x{color:red}', '.shared-x{color:blue}', '.shared-x{color:green}') === array_column($folded, 'content'), 'An owner rule competing with an intervening global rule keeps its place.');
// Template-part markup renders on every route, so it is evidence too.
foreach ($documents as &$document) $document['canonical_block_markup'] = '<p>Body</p>';
unset($document);
$folded = $fold->invoke(null, array($sheet('.shared-x{color:red}', $home), $sheet('.shared-x{color:blue}', $global), $sheet('.shared-x{color:green}', $home)), $documents, array_keys($documents), '<header class="shared-x"></header>');
$assert(3 === count($folded), 'A rule live in template parts competes on every route.');
echo "Support owner-set allocation passed\n";

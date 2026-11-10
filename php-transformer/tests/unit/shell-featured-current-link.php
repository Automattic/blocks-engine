<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../fixtures/captured-components.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};

// Every route includes the exact same header. The featured card is not a
// navigation list item; captured drawer evidence also contains that card.
$menu = '<nav class="mega"><a href="/catalog/">Catalog</a><div class="item"><button type="button" aria-haspopup="menu" aria-controls="mega-panel">Rings</button><div id="mega-panel"><div class="links"><a href="/rings/a/">Ring A</a><a href="/rings/c/">Ring C</a></div><div class="feature"><a class="mega__feat" href="/rings/b/"><img src="/ring.svg" alt=""><h3>Featured ring</h3><p>Explore B</p></a></div></div></div></nav>';
$trigger = '<button type="button" aria-label="Open drawer" aria-expanded="false">Menu</button>';
$header = '<header class="site-header"><p>Studio</p>' . $menu . $trigger . '</header>';
$files = array(array('path' => 'website/parts/header.html', 'content' => $header));
$receipt = array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array());
$interactions = array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array());
foreach (array('a', 'b', 'c') as $route) {
    $url = 'https://example.test/rings/' . $route . '/';
    $path = 'a' === $route ? 'website/index.html' : 'website/rings/' . $route . '/index.html';
    $files[] = array('path' => $path, 'content' => '<html><head><title>Ring ' . $route . '</title><style>.mega__feat{display:block}</style></head><body><!--#include virtual="/parts/header.html" --><main><h1>Ring ' . $route . '</h1><p>Details for ' . $route . '.</p></main></body></html>');
    $receipt['routes'][] = array('url' => $url, 'path' => $path);
    $captured = 'b' === $route ? str_replace('class="mega__feat"', 'class="mega__feat" aria-current="page"', $menu) : $menu;
    $interactions['pages'][] = array('sourceUrl' => $url, 'components' => captured_components(array(array(
        'status' => 'captured',
        'trigger' => array('selector' => 'body > header > button', 'tag' => 'button', 'label' => 'Open drawer', 'dataBindings' => array()),
        'dialog' => array('html' => $captured, 'htmlBytes' => strlen($captured), 'htmlTruncated' => false),
    ))));
}
$files[] = array('path' => 'capture-receipt.json', 'content' => json_encode($receipt, JSON_THROW_ON_ERROR));
$files[] = array('path' => 'interaction-states.json', 'content' => json_encode($interactions, JSON_THROW_ON_ERROR));
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html', 'files' => $files,
    'reports' => array('capture-receipt.json', 'interaction-states.json'),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$sourcePages = array_values(array_filter($plan['pages'], static fn(array $page): bool => str_starts_with($page['source_path'], 'website/')));
$assert(3 === count($sourcePages), 'all three routes compile');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'one header part serves every route');
foreach ($sourcePages as $page) {
    $template = implode('', array_column(array_filter($plan['templates'], static fn(array $template): bool => $template['slug'] === ($page['route_template'] ?? 'page')), 'canonical_block_markup'));
    $markup = $page['canonical_block_markup'] . $template;
    $assert(str_contains($markup, '"slug":"header"') && !str_contains($page['canonical_block_markup'], 'site-header'), $page['source_path'] . ' uses the header part');
}
foreach ($plan['diagnostics'] as $diagnostic) {
    if ('wordpress_site_plan_shell_extracted' !== ($diagnostic['code'] ?? null)) continue;
    $assert(!str_contains(json_encode($diagnostic, JSON_THROW_ON_ERROR), 'non_equivalent'), 'no route is excluded as non_equivalent: ' . json_encode($diagnostic));
}
$assert(str_contains(json_encode($headers), 'blocksEngineDialog'), 'the regression exercises a captured drawer');
// Rich navigation can retain its rendered root inside a panel. Its current
// colour is page state even when there is no navigation-link current marker.
$root = '<!-- wp:navigation {"overlayMenu":"never"} --><nav class="wp-block-navigation">' . $menu . '</nav><!-- /wp:navigation -->';
$currentRoot = str_replace('class="wp-block-navigation"', 'class="wp-block-navigation blocks-engine-navigation-current-color-' . str_repeat('a', 64) . '"', $root);
$assert(ShellExtraction::identityMarkup($root) === ShellExtraction::identityMarkup($currentRoot), 'rendered root current colour does not change shell identity');

// Compare the projector on the same source path so only captured current-page
// state varies, not the intentionally document-scoped part of the identity.
$project = static function (array $files, string $menu): string {
    $report = json_decode($files[count($files) - 1]['content'], true);
    $report['pages'] = array($report['pages'][0]);
    // Use a fresh evidence report through the fixture's public adapter.
    $report['pages'][0]['components'] = captured_components(array(array(
        'status' => 'captured',
        'trigger' => array('selector' => 'body > header > button', 'tag' => 'button', 'label' => 'Open drawer', 'dataBindings' => array()),
        'dialog' => array('html' => $menu, 'htmlBytes' => strlen($menu), 'htmlTruncated' => false),
    )));
    $files[count($files) - 1]['content'] = json_encode($report, JSON_THROW_ON_ERROR);
    $projected = (new CapturedDialogProjector())->project($files);
    foreach ($projected['files'] as $file) {
        if ('website/index.html' !== $file['path']) continue;
        preg_match('/id="(blocks-engine-dialog-[a-f0-9]{16})"/', $file['content'], $match);
        return $match[1] ?? '';
    }
    return '';
};
// The projector takes expanded documents, before block conversion.
$projectFiles = $files;
$projectFiles[1]['content'] = '<html><body>' . $header . '</body></html>';
$restingId = $project($projectFiles, $menu);
$currentId = $project($projectFiles, str_replace('class="mega__feat"', 'class="mega__feat" aria-current="page"', $menu));
$assert('' !== $restingId && $restingId === $currentId, 'featured current-page state does not change captured dialog identity');
echo "Featured current link shell: {$failures} failed\n";
exit($failures ? 1 : 0);

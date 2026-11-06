<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../fixtures/captured-components.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Every route includes the same header. Its mega-menu panel resets the nav's
// uppercase tracking, and the panel's featured card links to route b. On route
// b that card is the current item; the header must still be one shared part.
$css = '.nav{display:flex;font:400 12px/1 sans-serif;letter-spacing:.24em;text-transform:uppercase}'
    . '.nav>a,.mega>a{display:inline-block;padding:16px 0;text-decoration:none}'
    . '.mega__panel{position:absolute;left:0;right:0;opacity:0;visibility:hidden;text-transform:none;letter-spacing:.01em}'
    . '.mega:hover .mega__panel,.mega:focus-within .mega__panel{opacity:1;visibility:visible}'
    . '.mega__feat{display:grid;grid-template-columns:1fr 1fr;gap:24px;text-decoration:none}';
$drawer = '<nav aria-label="Menu"><ul><li><a href="/rings/">All rings</a></li><li><a href="/about/">About</a></li></ul></nav>';
$header = '<header class="site-header"><p>Studio</p><button type="button" aria-label="Open drawer" aria-expanded="false">Menu</button><nav class="nav" aria-label="Main"><a href="/about/">About</a>'
    . '<div class="mega"><a href="/rings/">Rings</a><div class="mega__panel"><div><h4>By style</h4><ul><li><a href="/rings/">All rings</a></li><li><a href="/rings/">Bands</a></li></ul></div>'
    . '<a class="mega__feat" href="/rings/b/index.html"><img src="/ring.svg" alt="" width="40" height="40"><span><strong>Ring B</strong><span>Turn it in 360°</span></span></a></div></div></nav></header>';
$files = array(
    array('path' => 'website/parts/header.html', 'content' => $header),
    array('path' => 'website/site.css', 'content' => $css),
    array('path' => 'website/ring.svg', 'content' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="18"/></svg>'),
);
$receipt = array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array());
$interactions = array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array());
foreach (array('website/index.html' => 'Home', 'website/rings/a/index.html' => 'Ring A', 'website/rings/b/index.html' => 'Ring B', 'website/about/index.html' => 'About') as $path => $title) {
    $url = 'https://example.test/' . substr($path, 8, -10);
    $receipt['routes'][] = array('url' => $url, 'path' => $path);
    $interactions['pages'][] = array('sourceUrl' => $url, 'components' => captured_components(array(array(
        'status' => 'captured',
        'trigger' => array('selector' => 'body > header > button', 'tag' => 'button', 'label' => 'Open drawer', 'dataBindings' => array()),
        'dialog' => array('html' => $drawer, 'htmlBytes' => strlen($drawer), 'htmlTruncated' => false),
    ))));
    $files[] = array('path' => $path, 'content' => '<html><head><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body><!--#include virtual="/parts/header.html" --><main><h1>' . $title . '</h1><p>Details for ' . $title . '.</p></main></body></html>');
}
$files[] = array('path' => 'capture-receipt.json', 'content' => json_encode($receipt, JSON_THROW_ON_ERROR));
$files[] = array('path' => 'interaction-states.json', 'content' => json_encode($interactions, JSON_THROW_ON_ERROR));
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files, 'reports' => array('capture-receipt.json', 'interaction-states.json')))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$extracted = array_values(array_filter($plan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_shell_extracted' === ($diagnostic['code'] ?? null) && 'header' === ($diagnostic['area'] ?? null)));
$failures = 0;
if (1 !== count($headers) || array() === $extracted || 4 !== ($extracted[0]['page_count'] ?? 0)) {
    ++$failures;
    fwrite(STDERR, "FAIL: one header part serves every route, including the featured card's own route: " . json_encode($extracted) . "\n");
}
if (!str_contains(json_encode($plan['menus']), '\\"letterSpacing\\":\\".01em\\"')) {
    ++$failures;
    fwrite(STDERR, "FAIL: the shared part keeps the panel's resting typography on the featured card\n");
}
echo "Featured submenu typography shell: {$failures} failed\n";
exit($failures ? 1 : 0);

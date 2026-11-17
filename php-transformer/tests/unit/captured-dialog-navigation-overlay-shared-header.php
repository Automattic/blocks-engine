<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * A header whose menu button opens a wired navigation overlay is one shared
 * header on every route.
 *
 * Builder runtimes render the opened overlay with instance ids minted per
 * visit (`comp-a1_r_menu` on one route, `comp-b2_r_menu` on another), and a
 * route's probe evidence may or may not validate. Neither may split the
 * header: the overlay stays the wired source panel on every route, and its
 * unreferenced instance ids are not shell identity.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . substr($detail, 0, 600) : '') . PHP_EOL);
};

$key = 'data-blocks-engine-capture-dialog-0';
$trigger = '<button type="button" class="menu-open" aria-label="Menu" aria-haspopup="dialog" data-blocks-engine-capture-disclosure-label="Menu" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '" aria-expanded="false">Menu</button>';
$overlay = static fn(string $instance): string => '<div class="data-blocks-engine-capture-dialog" role="dialog" aria-modal="true" hidden id="' . $key . '" data-blocks-engine-capture-dialog-panel="' . $key . '" aria-label="Site">'
    . '<div id="' . $instance . '_r_box" class="box ' . $instance . '_r_box"><div class="' . $instance . '_r_box-container">'
    . '<nav aria-label="Site"><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav></div></div></div>';
$page = static fn(string $instance, string $body): string => '<html><head><title>Shop</title></head><body><header class="site-header"><a href="/">Shop</a>'
    . '<nav class="primary"><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav>'
    . '<div class="menu">' . $trigger . $overlay($instance) . '</div></header>'
    . '<main><h1>' . $body . '</h1><p>' . $body . ' copy.</p></main><footer class="site-footer"><p>Shop footer</p></footer></body></html>';

$captured = '<div class="opened"><nav aria-label="Site"><a href="/">Home</a><a href="/about/">About</a></nav></div>';
$state = static fn(int $bytes): array => array(
    'status' => 'captured',
    'trigger' => array('selector' => 'body > header > div > button', 'tag' => 'button', 'ariaHaspopup' => 'dialog', 'label' => 'Menu', 'dataBindings' => array()),
    'dialog' => array('html' => $captured, 'htmlBytes' => $bytes, 'htmlTruncated' => false, 'presentation' => 'modal'),
);
// Only the entry route's evidence validates; the other declares other bytes.
$routes = array(
    'https://example.test/' => array('website/index.html', 'comp-a1', strlen($captured)),
    'https://example.test/about/' => array('website/about/index.html', 'comp-b2', strlen($captured) + 7),
);
$receipt = array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array());
$interactions = array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array());
$files = array();
foreach ($routes as $url => [$path, $instance, $bytes]) {
    $receipt['routes'][] = array('url' => $url, 'path' => $path);
    $interactions['pages'][] = array('sourceUrl' => $url, 'components' => captured_components(array($state($bytes))));
    $files[] = array('path' => $path, 'content' => $page($instance, basename(dirname($path))));
}
$files[] = array('path' => 'capture-receipt.json', 'content' => (string) json_encode($receipt, JSON_UNESCAPED_SLASHES));
$files[] = array('path' => 'interaction-states.json', 'content' => (string) json_encode($interactions, JSON_UNESCAPED_SLASHES));

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => $files,
    'reports' => array('capture-receipt.json', 'interaction-states.json'),
))->toWordPressSitePlanView()['wordpress_site_plan'] ?? array();

$pages = $plan['pages'] ?? array();
$assert(2 === count($pages), 'both routes compile to pages', (string) json_encode(array_column($pages, 'source_path')));
$headerParts = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headerParts), 'the header with a navigation overlay becomes one template part', (string) json_encode(array_column($headerParts, 'slug')));
$pageMarkup = implode("\n", array_map(static fn(array $page): string => (string) ($page['canonical_block_markup'] ?? ''), $pages));
$assert(!str_contains($pageMarkup, 'site-header'), 'no page keeps an inline copy of the header', $pageMarkup);

fwrite(STDOUT, 'Captured dialog navigation overlay shared header: ' . ($failures ? "{$failures} failed" : 'passed') . "\n");
exit($failures ? 1 : 0);

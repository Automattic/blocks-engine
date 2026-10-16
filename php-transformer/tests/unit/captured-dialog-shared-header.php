<?php
declare(strict_types=1);

/**
 * A header whose menu is a captured dialog is still shared chrome.
 *
 * The projected dialog's generated id hashes the route it was captured on,
 * so two routes with the same header carry different dialog ids. Shell
 * identity must not read that id as a difference, or the header stays inline
 * on every page instead of becoming one template part.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($ok) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . substr($detail, 0, 600) : '') . PHP_EOL);
};

// The capture wires the menu panel in place inside the header, as a
// Next.js/Tailwind mobile menu is captured.
$trigger = '<button type="button" aria-label="Toggle menu" data-blocks-engine-capture-disclosure-label="Toggle menu" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0" aria-controls="data-blocks-engine-capture-dialog-0" aria-expanded="false" aria-haspopup="menu">Menu</button>';
$panel = '<div class="menu-panel data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown" hidden id="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-observed-placement="true" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0"><a href="/">Home</a><a href="/about/">About</a></div>';
$header = '<header class="site-header"><a href="/">Shop</a><nav><a href="/">Home</a><a href="/about/">About</a></nav><div>' . $trigger . '</div>' . $panel . '</header>';
$page = static fn(string $body): string => '<html><head><title>Shop</title></head><body>' . $header . '<main><h1>' . $body . '</h1><p>' . $body . ' copy.</p></main><footer class="site-footer"><p>Shop footer</p></footer></body></html>';
$captured = '<a href="/">Home</a>';
$state = array(
    'status' => 'captured',
    'trigger' => array('selector' => 'body > header > div > button', 'tag' => 'button', 'ariaHaspopup' => 'menu', 'label' => 'Toggle menu', 'dataBindings' => array()),
    'dialog' => array('html' => $captured, 'htmlBytes' => strlen($captured), 'htmlTruncated' => false, 'presentation' => 'dropdown'),
);
$routes = array('https://example.test/' => 'website/index.html', 'https://example.test/about/' => 'website/about/index.html');
$receipt = array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array());
$interactions = array('schema' => 'blocks-engine-capture/captured-interactions/v1', 'pages' => array());
foreach ($routes as $url => $path) {
    $receipt['routes'][] = array('url' => $url, 'path' => $path);
    $interactions['pages'][] = array('sourceUrl' => $url, 'states' => array($state));
}

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => $page('Welcome')),
        array('path' => 'website/about/index.html', 'content' => $page('About us')),
        array('path' => 'capture-receipt.json', 'content' => (string) json_encode($receipt, JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => (string) json_encode($interactions, JSON_UNESCAPED_SLASHES)),
    ),
    'reports' => array('capture-receipt.json', 'interaction-states.json'),
))->toWordPressSitePlanView()['wordpress_site_plan'] ?? array();

$pages = $plan['pages'] ?? array();
$pageMarkup = implode("\n", array_map(static fn(array $page): string => (string) ($page['canonical_block_markup'] ?? ''), $pages));
$assert(2 === count($pages), 'both routes compile to pages', (string) json_encode(array_column($pages, 'source_path')));

$headerParts = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headerParts), 'the header with a captured dialog menu becomes one template part', (string) json_encode(array_column($plan['template_parts'] ?? array(), 'area')));
$headerMarkup = (string) ($headerParts[0]['canonical_block_markup'] ?? $headerParts[0]['content'] ?? '');
$assert(1 === substr_count($headerMarkup, '<dialog'), 'the header part carries the captured dialog once', $headerMarkup);
$assert(!str_contains($pageMarkup, '<dialog') && !str_contains($pageMarkup, 'site-header'), 'no page keeps an inline copy of the header or its dialog', $pageMarkup);

fwrite(STDOUT, "Captured dialog shared header: {$passes} passed, {$failures} failed\n");
exit($failures ? 1 : 0);

<?php
/**
 * A frame named like an application (`.app`) that holds the site's own
 * navigation is the page layout. Its navigation, header and article convert
 * natively, and only the script surfaces inside it stay runtime islands. An
 * application surface without site navigation is still one bounded island.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0; $passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) { ++$passed; return; }
    ++$failures; fwrite(STDERR, "FAIL: {$message}\n");
};
$blockNames = static function (array $blocks) use (&$blockNames): array {
    $names = array();
    foreach ($blocks as $block) { $names[] = $block['blockName'] ?? null; array_push($names, ...$blockNames($block['innerBlocks'] ?? array())); }
    return $names;
};
$appShellIslands = static fn(array $result): array => array_values(array_filter($result['source_reports']['runtime_islands'] ?? array(), static fn(array $island): bool => 'app_shell' === ($island['kind'] ?? null)));
$options = array(
    'runtime_dom_selectors' => array('#sidebar', '#nav-toggle', '.palette'),
    'runtime_script_metadata' => array(array('path' => 'js/site.js')),
);

$frame = (new HtmlTransformer())->transform(
    '<div class="app">'
    . '<aside class="sidebar" id="sidebar"><a class="brand" href="index.html">Harbor Docs</a>'
    . '<nav class="side-nav" aria-label="Guides"><a href="index.html">Overview</a><a href="setup.html">Setup</a><a href="usage.html">Usage</a></nav></aside>'
    . '<div class="content"><header class="topbar"><button id="nav-toggle" aria-label="Toggle navigation">Menu</button><span class="crumb">Overview</span></header>'
    . '<article class="doc"><h1>Harbor Docs</h1><p>Everything you need to moor a boat at the harbor.</p></article>'
    . '<div class="palette"><input aria-label="Search"></div></div></div>',
    $options
)->toArray();
$serialized = (string) ($frame['serialized_blocks'] ?? '');
$names = $blockNames($frame['blocks'] ?? array());
$assert(array() === $appShellIslands($frame), 'A frame holding the site navigation is not preserved as an application shell.');
$assert(in_array('core/heading', $names, true) && in_array('core/paragraph', $names, true), 'The article inside the frame converts to native heading and paragraph blocks.');
$raw = implode('', array_map(static fn(array $m): string => $m[1], preg_match_all('~<!-- wp:html -->(.*?)<!-- /wp:html -->~s', $serialized, $matches, PREG_SET_ORDER) ? $matches : array()));
$assert(!str_contains($raw, 'Everything you need to moor') && !str_contains($raw, 'setup.html'), 'Neither the article nor the site navigation is held in raw HTML.');
$assert(str_contains($serialized, 'class="app') || str_contains($serialized, '"className":"app'), 'The frame keeps its class, so scripts and author CSS still address it.');

$surface = (new HtmlTransformer())->transform(
    '<div class="app"><div class="toolbar"><button id="run">Run</button><a href="help.html">Help</a></div><canvas id="scene"></canvas><div id="log"></div></div>',
    array(
        'runtime_canvas_selectors' => array('#scene'),
        'runtime_dom_selectors' => array('#scene', '#run', '#log'),
        'runtime_script_metadata' => array(array('path' => 'js/app.js')),
    )
)->toArray();
$assert(1 === count($appShellIslands($surface)) && 'core/html' === ($surface['blocks'][0]['blockName'] ?? null), 'An application surface without site navigation stays one bounded runtime island.');

if (0 !== $failures) { fwrite(STDERR, "Runtime app shell site frame: {$failures} failed\n"); exit(1); }
echo "Runtime app shell site frame: {$passed} passed\n";

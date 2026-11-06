<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedInteractionReport;

// Twenty routes share an identical header toggle. Each captured route opens a menu
// marking its own page as current; the last route captured no interaction.
$header = '<header><div id="toggle" role="button" aria-haspopup="true" aria-controls="menu" data-blocks-engine-capture-dialog-trigger="menu">Menu</div></header>';
$routes = $rows = $pages = array();
for ($i = 0; $i < 20; ++$i) {
    $url = 'https://example.test/' . ($i ? 'p' . $i : '');
    $path = $i ? 'website/p' . $i . '/index.html' : 'website/index.html';
    $menu = '<nav><a href="/" ' . (0 === $i ? 'aria-current="page"' : '') . '>Home</a><a href="/p' . $i . '" aria-current="page">Page ' . $i . '</a></nav>';
    $routes[] = array('url' => $url, 'path' => $path);
    $rows[] = array('path' => $path, 'content' => '<html><body>' . $header . '<main><h1>Page ' . $i . '</h1></main></body></html>');
    $state = array('status' => 'captured', 'trigger' => array('selector' => '#toggle', 'tag' => 'div', 'role' => 'button', 'ariaHaspopup' => 'true', 'ariaControls' => 'menu'), 'dialog' => array('html' => $menu, 'htmlBytes' => strlen($menu), 'htmlTruncated' => false));
    $pages[] = array('sourceUrl' => $url, 'components' => 19 === $i ? array() : captured_components(array($state)));
}
$rows[] = array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => $routes), JSON_UNESCAPED_SLASHES));
$rows[] = array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => $pages), JSON_UNESCAPED_SLASHES));

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); }
};
$shared = CapturedInteractionReport::read($rows)['pages'];
$assert(1 === count($shared[3]['components']) && str_contains(json_encode($shared[3]['components']), 'Page 3'), 'a route that captured the shared control keeps only its own variant');
$assert(array() !== $shared[19]['components'], 'a route that did not capture the shared control still inherits it');
$codes = array_column((new CapturedDialogProjector())->project($rows)['diagnostics'], 'code');
$assert(!in_array('captured_interaction_state_limit_exceeded', $codes, true) && !in_array('captured_dialog_trigger_ambiguous', $codes, true), 'per-route variants of one control never exceed the page state limit: ' . json_encode(array_count_values($codes)));

if ($failures) exit(1);
echo "Captured shared evidence own-variant tests passed\n";

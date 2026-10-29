<?php
declare(strict_types=1);

/**
 * Recognized disclosure runtime -> native dialog: attribute-only ancestor rows.
 *
 * The capture records `class`, `style`, `hidden`, `aria-*` and `data-*` changes
 * on a trigger's ancestors, and its disclosure runtime replays every recorded
 * attribute: the opened value on open, the closed value on close and on load,
 * removing the attribute when the recorded value is null. When the runtime is
 * retired, the native dialog must do the same. This test compiles a scoped
 * dropdown whose text-labelled trigger carries attribute-only rows (a header
 * `data-section-theme`, `aria-expanded`/`data-state` on the document scope,
 * a `data-menu` added to body) and checks that every row reaches the native
 * dialog. It writes the fixture for capture-runtime-attribute-parity-browser.mjs,
 * which drives the source runtime and the native dialog in Chromium.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../fixtures/captured-components.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;

$failures = 0;
$assert = static function (bool $condition, string $message, string $context = '') use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n" . ('' !== $context ? substr($context, 0, 1600) . "\n" : ''));
};

$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$assert(CaptureEvidence::DISCLOSURE_RUNTIME_SHA256 === hash('sha256', $runtime), 'the fixture is the published disclosure runtime');

$ancestors = array(
    array('selector' => '#site-header', 'tag' => 'header', 'depth' => 2, 'closed' => array('data-section-theme' => 'dark'), 'opened' => array('data-section-theme' => 'light')),
    array('selector' => '#scope', 'tag' => 'div', 'depth' => 3, 'closed' => array('aria-expanded' => null, 'data-state' => 'closed'), 'opened' => array('aria-expanded' => 'true', 'data-state' => 'open')),
    array('selector' => 'body', 'tag' => 'body', 'depth' => 4, 'closed' => array('data-menu' => null), 'opened' => array('data-menu' => 'open')),
);
$key = 'site-menu-panel';
$trigger = '<button type="button" data-blocks-engine-capture-disclosure-label="Menu" data-blocks-engine-capture-dialog-trigger="' . $key . '" aria-controls="' . $key . '" aria-expanded="false" aria-haspopup="menu" data-blocks-engine-capture-dialog-ancestor-state="' . htmlspecialchars(json_encode($ancestors, JSON_THROW_ON_ERROR), ENT_QUOTES) . '">Menu</button>';
$panel = '<div id="' . $key . '" class="menu-panel data-blocks-engine-capture-dropdown" hidden data-blocks-engine-capture-dialog-panel="' . $key . '"><div><p>Visit</p><a href="#one">One</a></div></div>';
$css = 'body{margin:0;font-family:Arial}header{display:block;padding:8px}'
    . '[data-section-theme="dark"]{background:rgb(20,20,20);color:white}[data-section-theme="light"]{background:rgb(250,250,250);color:black}'
    . '[data-state="open"] main{opacity:.5}body[data-menu="open"]{overflow:hidden}.menu-panel{padding:8px}';
$scope = '<div id="scope" class="data-blocks-engine-capture-desktop-document" data-state="closed" data-blocks-engine-capture-document-scope="">'
    . '<header id="site-header" class="site-header" data-section-theme="dark"><div class="menu-host">' . $trigger . $panel . '</div></header>'
    . '<main><h2 id="one">One</h2><h2 id="two">Two</h2></main></div>';
$page = static fn (string $body): string => '<!doctype html><html><head><style>' . $css . '</style><script data-blocks-engine-capture-disclosure-runtime="true">' . $body . '</script></head><body>' . $scope . '</body></html>';
$state = array(
    'status' => 'captured',
    'trigger' => array('selector' => 'missing', 'label' => 'Menu', 'tag' => 'button', 'ariaHaspopup' => 'menu'),
    'dialog' => array('html' => '<div>Report snapshot</div>', 'htmlBytes' => strlen('<div>Report snapshot</div>'), 'htmlTruncated' => false, 'presentation' => 'dropdown'),
);
$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
    array('path' => 'website/index.html', 'content' => $html),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURE_RECEIPT_SCHEMA, 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURED_INTERACTIONS_SCHEMA, 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))))),
)))->toArray();

$source = $page($runtime);
$native = $compile($source);
$markup = (string) ($native['serialized_blocks'] ?? '');
$proofs = array_column($native['source_reports']['native_runtime_replacements'] ?? array(), 'body_hash', 'attribute');
$assert(CaptureEvidence::DISCLOSURE_RUNTIME_SHA256 === ($proofs[CaptureEvidence::DISCLOSURE_RUNTIME] ?? null), 'the published disclosure runtime is retired with a native replacement proof', json_encode($native['source_reports']['native_runtime_replacements'] ?? null));
$assert(1 === substr_count($markup, '<!-- wp:custom/captured-dialog ') && !str_contains($markup, '<!-- wp:details'), 'the text-labelled dropdown becomes one native dialog block, not core/details', $markup);
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<body>' . $markup . '</body>');
$dialog = (new DOMXPath($dom))->query('//*[@data-blocks-engine-triggers]')->item(0);
$expected = array_map(static fn (array $row): array => array('tag' => $row['tag'], 'closed' => $row['closed'], 'opened' => $row['opened']), $ancestors);
$assert($dialog instanceof DOMElement && $expected === json_decode($dialog->getAttribute('data-blocks-engine-ancestor-state'), true), 'every attribute-only ancestor row travels to the native dialog', $markup);
$assert(0 === ($native['metrics']['fallback_count'] ?? -1), 'the page lowers without fallback blocks');

// Only attributes the capture records and its runtime replays travel. A row
// naming an event handler, a URL attribute or the engine's own namespace is
// dropped; the remaining rows still travel.
$hostile = array_merge($ancestors, array(
    array('tag' => 'header', 'closed' => array('onclick' => null), 'opened' => array('onclick' => 'alert(1)')),
    array('tag' => 'div', 'closed' => array('href' => null), 'opened' => array('href' => 'javascript:alert(1)')),
    array('tag' => 'div', 'closed' => array('data-blocks-engine-triggers' => null), 'opened' => array('data-blocks-engine-triggers' => 'x')),
));
$assert($expected === CapturedDialogBlockGenerator::ancestorState($hostile), 'handler, URL and engine-namespace rows are dropped from the replayable binding');
$assert(array() === CapturedDialogBlockGenerator::ancestorState(array(array('tag' => 'div', 'closed' => array('data-a' => 'x'), 'opened' => array('data-b' => 'y')))), 'a row whose closed and opened attributes differ is rejected');
$definition = (new CapturedDialogBlockGenerator())->definition('custom/captured-dialog');
$assert(str_contains((string) $definition['view_js'], "new RegExp( '" . CapturedDialogBlockGenerator::ANCESTOR_ATTRIBUTE_PATTERN . "' )"), 'the view script replays only the shared attribute allowlist');

file_put_contents(sys_get_temp_dir() . '/capture-runtime-attribute-parity.json', json_encode(array(
    'source' => $source,
    'native' => $markup,
    'css' => $css . (string) ($definition['assets']['style.css'] ?? ''),
    'script' => (string) ($definition['view_js'] ?? ''),
    'ancestors' => $ancestors,
), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

if (0 !== $failures) exit(1);
echo "capture-runtime-attribute-parity: ok\n";

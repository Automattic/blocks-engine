<?php
declare(strict_types=1);

// Issue #6495: a responsive nav panel the source rests visible (an
// existing-panel snapshot) is adopted as an in-place dropdown. When the
// capture disclosure runtime stays because other wiring on the page still
// needs it (here a capture close helper), that runtime must not keep driving
// the adopted trigger: the adopted panel no longer carries its snapshot, so
// the runtime would set `hidden` on it wherever the trigger is concealed.

require_once __DIR__ . '/../fixtures/captured-components.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ($condition) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$runtime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$links = '<a href="#a">Home</a><a href="#b">Services</a>';
$panelState = htmlspecialchars(json_encode(array('html' => $links), JSON_THROW_ON_ERROR), ENT_QUOTES);
$snapshot = htmlspecialchars(json_encode(array(
    'resting' => array('style' => null, 'class' => 'main-nav data-blocks-engine-capture-dropdown', 'hidden' => false),
    'opened' => array('style' => null, 'class' => 'main-nav open data-blocks-engine-capture-dropdown', 'hidden' => false),
), JSON_THROW_ON_ERROR), ENT_QUOTES);
$css = '.main-nav{display:flex}.menu-toggle{display:none}@media(max-width:960px){.menu-toggle{display:flex}.main-nav{display:none}.main-nav.open{display:flex}}';
$html = '<!doctype html><html><head><style>' . $css . '</style><script data-blocks-engine-capture-disclosure-runtime="true">' . $runtime . '</script></head><body>'
    . '<header><div class="nav-inner"><a class="brand" href="#top">Brand</a>'
    . '<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="main-nav" aria-label="Open menu" data-blocks-engine-capture-dialog-trigger="main-nav" data-blocks-engine-capture-dialog-panel-state="' . $panelState . '">Menu</button>'
    . '<nav id="main-nav" class="main-nav data-blocks-engine-capture-dropdown" data-blocks-engine-capture-existing-panel="' . $snapshot . '" data-blocks-engine-capture-resting-panel="" data-blocks-engine-capture-dialog-panel="main-nav">' . $links
    . '<button type="button" hidden data-blocks-engine-capture-dialog-close="main-nav" aria-label="Close menu">Close</button></nav>'
    . '</div></header><main><h1 id="top">Page</h1><p id="a">A</p><p id="b">B</p></main></body></html>';
$components = captured_components(array(array('status' => 'captured', 'trigger' => array('selector' => '.menu-toggle', 'tag' => 'button', 'label' => 'Open menu', 'dataBindings' => array()), 'dialog' => array('html' => $links, 'htmlBytes' => strlen($links), 'htmlTruncated' => false, 'presentation' => 'dropdown'))));
$input = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'capture-receipt.json' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html')))),
    'interaction-states.json' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => $components)))),
));

$result = (new ArtifactCompiler())->compile($input)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$runtimeKept = array() !== array_filter($result['assets'] ?? array(), static fn (array $asset): bool => str_contains((string) ($asset['content'] ?? ''), 'data-blocks-engine-capture-existing-panel'));

$assert($runtimeKept, 'the capture runtime stays for the close helper it still drives');
$assert(str_contains($markup, '"data-blocks-engine-presentation":"dropdown"') && str_contains($markup, '"data-blocks-engine-resting":"authored"'), 'the resting panel is adopted as an in-place dropdown at its authored rest');
$assert(str_contains($markup, '<!-- wp:navigation {"anchor":"main-nav","className":"main-nav"'), 'the adopted panel lowers to core/navigation at its resting class');
$assert(1 === preg_match('/"data-blocks-engine-triggers":"(blocks-engine-dialog-trigger-[a-f0-9]+-1)"/', $markup, $bound) && str_contains($markup, 'id="' . $bound[1] . '"'), 'the dialog binds the toggle by id');
// The capture runtime selects triggers by this attribute and, with no
// existing-panel snapshot on the panel, sets `hidden=!open` on it.
$assert(!str_contains($markup, 'data-blocks-engine-capture-dialog-trigger='), 'the adopted toggle is no longer driven by the capture runtime', $markup);
$assert(!str_contains($markup, 'data-blocks-engine-capture-dialog-panel-state'), 'the adopted toggle drops its spent opened snapshot');

if ($failures > 0) exit(1);
echo "adopted-resting-panel-retained-runtime: ok\n";

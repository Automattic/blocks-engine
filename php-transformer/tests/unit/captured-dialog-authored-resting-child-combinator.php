<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

/**
 * A dropdown panel the source rests closed through a child-combinator rule
 * (`.menus > .panel{opacity:0;height:0}`, shown by `.menus > .panel.open`)
 * is adopted in place with `resting: authored`. The adopted panel is itself
 * the dialog: no element is inserted around it, so it stays a child of
 * `.menus` and the authored resting rule still reaches it on the frontend.
 * Otherwise the closed panel renders as page content.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\CapturedDialogRuntime;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . substr($detail, 0, 1500) : '' ) . PHP_EOL);
    }
};

$opened = array('class' => 'panel open data-blocks-engine-capture-dropdown', 'style' => 'display: block ; visibility: visible !important;');
$snapshot = htmlspecialchars(json_encode(array('resting' => array('style' => null, 'class' => 'panel data-blocks-engine-capture-dropdown', 'hidden' => false), 'opened' => $opened)), ENT_QUOTES);
$panelLinks = '<ul><li><a href="/cuts">Cuts</a></li><li><a href="/color">Color</a></li></ul>';
$panelState = htmlspecialchars(json_encode(array('html' => $panelLinks) + $opened), ENT_QUOTES);
$html = '<!doctype html><html><head><style>'
    . 'body{margin:0}.menus>.panel{position:absolute;left:0;right:0;opacity:0;height:0;overflow:hidden;pointer-events:none}.menus>.panel.open{opacity:1;height:auto;pointer-events:all}'
    . '</style><script data-blocks-engine-capture-disclosure-runtime="true">' . file_get_contents(__DIR__ . '/../fixtures/capture-disclosure-runtime.js') . '</script></head><body>'
    . '<header><nav><a href="/">Home</a><button id="servicesBtn" type="button" aria-expanded="false" aria-controls="servicesPanel" data-blocks-engine-capture-dialog-trigger="servicesPanel" data-blocks-engine-capture-dialog-panel-state="' . $panelState . '">Services</button></nav></header>'
    . '<main><h1>Pricing</h1><p>Editable copy.</p></main><footer><p>Footer</p></footer>'
    . '<div class="menus"><div id="servicesPanel" role="menu" class="panel open data-blocks-engine-capture-dropdown" style="display: block ; visibility: visible !important;" data-blocks-engine-capture-existing-panel="' . $snapshot . '" data-blocks-engine-capture-dialog-panel="servicesPanel">' . $panelLinks . '</div></div>'
    . '</body></html>';
$panelHtml = '<div id="servicesPanel" class="panel open">' . $panelLinks . '</div>';
$state = array(
    'status' => 'captured',
    'trigger' => array('selector' => '#servicesBtn', 'tag' => 'button', 'label' => 'Services', 'dataBindings' => array()),
    'dialog' => array('selector' => '#servicesPanel', 'tag' => 'div', 'presentation' => 'dropdown', 'html' => $panelHtml, 'htmlBytes' => strlen($panelHtml), 'htmlTruncated' => false),
);
$files = array(
    array('path' => 'website/index.html', 'content' => $html),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components(array($state))))), JSON_UNESCAPED_SLASHES)),
);

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$markup = implode('', array_column($plan['pages'] ?? array(), 'canonical_block_markup')) . implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
$css = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '');
}

$document = new DOMDocument();
@$document->loadHTML('<!doctype html><html><body>' . StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup) . '</body></html>');
$panel = $document->getElementById('servicesPanel');
$assert($panel instanceof DOMElement, 'the adopted panel keeps its source id', $markup);
$assert($panel instanceof DOMElement && 'servicesBtn' === $panel->getAttribute('data-blocks-engine-triggers') && 'authored' === $panel->getAttribute('data-blocks-engine-resting') && 'dropdown' === $panel->getAttribute('data-blocks-engine-presentation'), 'the adopted panel itself carries the dialog bindings', $markup);
$assert($panel instanceof DOMElement && $panel->parentNode instanceof DOMElement && SourceDom::hasClass($panel->parentNode, 'menus'), 'the adopted panel stays a child of its source parent', $markup);
$assert(0 === count(array_filter(iterator_to_array($document->getElementsByTagName('*')), static fn(DOMElement $node): bool => $node->hasAttribute('data-blocks-engine-resting') && 'servicesPanel' !== $node->getAttribute('id'))), 'no resting wrapper element is added around the panel', $markup);

// The site's frontend Group filter renders the adopted shell as the panel
// itself: the shell's own element is dropped and its bindings join the
// panel's opening tag.
$filters = array();
function add_filter(string $name, callable $callback, int $priority, int $args): void
{
    global $filters;
    $filters[$name] = $callback;
}
function add_action(string $name, callable $callback): void {}
function esc_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
eval(CapturedDialogRuntime::bootstrap());
$shell = null;
if (1 === preg_match('/<!-- wp:group (\{"metadata":\{"blocksEngineDialog":\{"adopt":true.*?\}) -->/', $markup, $match)) $shell = json_decode($match[1], true);
$assert(is_array($shell) && !isset($shell['anchor']) && !isset($shell['className']), 'the adopted shell holds neither the panel anchor nor its classes', $markup);
$rendered = is_array($shell) ? $filters['render_block_core/group']('<div class="wp-block-group"><div id="servicesPanel" class="wp-block-group panel"><ul><li>Cuts</li></ul></div></div>', array('attrs' => $shell)) : '';
$assert(1 === preg_match('/^<div id="servicesPanel" class="wp-block-group panel" data-blocks-engine-presentation="dropdown" data-blocks-engine-placement="in-place" data-blocks-engine-triggers="servicesBtn" data-blocks-engine-resting="authored" data-blocks-engine-resting-state="[^"]+"><ul><li>Cuts<\/li><\/ul><\/div>$/', $rendered), 'the frontend renders the panel as the dialog, with no element around it', $rendered);

// The emitted rule declaring `opacity:<value>` that reaches the panel.
$reaches = static function (DOMElement $panel, string $value) use ($css): bool {
    $found = false;
    (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body) use ($panel, $value, &$found): void {
        if (! preg_match('/(?:^|;|\{)\s*opacity\s*:\s*' . $value . '\s*(?:;|$)/', $body)) return;
        foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
            if (CssSelectorMatcher::matches($panel, CssSelectorMatcher::parse(trim($arm)))['matches']) $found = true;
        }
    });
    return $found;
};
if ($panel instanceof DOMElement) {
    $assert($reaches($panel, '0'), 'the authored child-combinator resting rule reaches the adopted panel', $css);
    // Opened, the dialog replays the source's opened class onto the panel.
    $panel->setAttribute('class', $panel->getAttribute('class') . ' open');
    $assert($reaches($panel, '1'), 'the authored opened rule reaches the adopted panel once it takes the opened class', $css);
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Captured dialog authored resting child combinator failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "Captured dialog authored resting child combinator passed\n");

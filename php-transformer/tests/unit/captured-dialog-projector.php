<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$dialogHtml = '<nav aria-label="Site"><a href="/features">Features</a></nav>';
$project = static function (array $files) use ($dialogHtml): array {
    return (new CapturedDialogProjector())->project($files);
};
$codes = static function (array $result): array {
    return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $result['diagnostics'] ?? array())));
};
$state = static function (array $trigger) use ($dialogHtml): array {
    return array(
        'status' => 'captured',
        'trigger' => $trigger,
        'dialog' => array('html' => $dialogHtml, 'htmlBytes' => strlen($dialogHtml), 'htmlTruncated' => false),
    );
};
$files = static function (array $pages, array $statesByUrl) use ($state): array {
    $routes = array();
    $pageRows = array();
    $reportPages = array();
    foreach ($pages as $url => $html) {
        $path = $pages === array() ? 'website/index.html' : 'website/' . trim(parse_url($url, PHP_URL_PATH) ?: '/', '/') . '/index.html';
        if ('website//index.html' === $path || 'website/index.html' === $path) {
            $path = 'website/index.html';
        }
        $routes[] = array('url' => $url, 'path' => $path);
        $pageRows[] = array('path' => $path, 'content' => $html);
        $reportPages[] = array('sourceUrl' => $url, 'states' => $statesByUrl[$url] ?? array());
    }
    $pageRows[] = array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => $routes), JSON_UNESCAPED_SLASHES));
    $pageRows[] = array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => $reportPages), JSON_UNESCAPED_SLASHES));
    return $pageRows;
};

$bindingTrigger = array('selector' => 'body > header > nav > a:nth-of-type(2)', 'tag' => 'a', 'ariaHaspopup' => 'dialog', 'label' => 'Contact', 'dataBindings' => array('data-popupid' => 'contact'));
$bindingHtml = '<html><body><header><nav><a href="/">Home</a><a role="button" aria-haspopup="dialog" data-popupid="contact">Contact</a></nav></header></body></html>';
$binding = $project($files(array('https://example.test/' => $bindingHtml), array('https://example.test/' => array($state($bindingTrigger)))));
$assert(1 === ($binding['projected_count'] ?? 0), 'declarative bindings still project one dialog');
$assert(! in_array('captured_dialog_trigger_unmatched', $codes($binding), true), 'declarative bindings do not emit unmatched diagnostics');
$assert(str_contains((string) $binding['files'][0]['content'], 'data-blocks-engine-captured-dialog="true"'), 'declarative bindings inject a captured dialog');

$runtimeForm = $project($files(array('https://example.test/runtime-form' => '<html><body><header><nav><a href="/">Home</a><a role="button" aria-haspopup="dialog" data-popupid="contact">Contact</a></nav></header></body></html>'), array('https://example.test/runtime-form' => array(array(
    'status' => 'captured',
    'trigger' => $bindingTrigger,
    'dialog' => array('html' => '<div><form action="https://provider.example/forms"><input name="name"><script>window.provider=true</script></form></div>', 'htmlBytes' => strlen('<div><form action="https://provider.example/forms"><input name="name"><script>window.provider=true</script></form></div>'), 'htmlTruncated' => false),
)))));
$runtimeFormMarkup = (string) ($runtimeForm['files'][0]['content'] ?? '');
$assert(str_contains($runtimeFormMarkup, 'data-blocks-engine-runtime-form-owner="captured"'), 'sanitized captured forms retain explicit runtime ownership');
$assert(! str_contains($runtimeFormMarkup, 'provider.example') && ! str_contains($runtimeFormMarkup, 'window.provider'), 'runtime ownership survives endpoint and script sanitization');

$menuTrigger = array(
    'selector' => 'body > div > div > div:nth-of-type(2) > header > nav > div > button',
    'tag' => 'button',
    'ariaHaspopup' => '',
    'label' => 'Menu',
    'dataBindings' => array(),
);
$responsiveHtml = '<html><body>'
    . '<div class="data-liberation-desktop-document"><div><div><div></div><div><header><nav><div><details><summary aria-label="Menu">Menu</summary></details></div></nav></header></div></div></div></div>'
    . '<div class="data-liberation-mobile-document"><div><div><div></div><div><header><nav><div><details><summary aria-label="Menu">Menu</summary></details></div></nav></header></div></div></div></div>'
    . '</body></html>';
$responsive = $project($files(array('https://example.test/' => $responsiveHtml), array('https://example.test/' => array($state($menuTrigger)))));
$responsiveAgain = $project($files(array('https://example.test/' => $responsiveHtml), array('https://example.test/' => array($state($menuTrigger)))));
$assert(1 === ($responsive['projected_count'] ?? 0), 'responsive document scopes project one dialog for equivalent rewritten triggers');
$assert(array() === $codes($responsive), 'responsive document scopes emit no trigger diagnostics');
$assert($responsive === $responsiveAgain, 'responsive trigger matching and generated identities are deterministic');
$assert(1 === preg_match('/data-blocks-engine-triggers="([^"]+)"/', (string) $responsive['files'][0]['content'], $triggerIds), 'responsive matches expose trigger ids');
$assert(2 === count(preg_split('/\s+/', trim($triggerIds[1] ?? '')) ?: array()), 'each responsive document contributes one trigger');

$variantHtml = '<html><body>'
    . '<div class="site-document-variant-default"><header><button aria-label="Menu">Menu</button></header></div>'
    . '<div class="site-document-variant-mobile"><header><button aria-label="Menu">Menu</button></header></div>'
    . '</body></html>';
$variant = $project($files(array('https://example.test/variant' => $variantHtml), array('https://example.test/variant' => array($state($menuTrigger)))));
$assert(1 === ($variant['projected_count'] ?? 0) && array() === $codes($variant), 'site document variant wrappers match one trigger per scope');

$homeHtml = '<html><body><div class="data-liberation-mobile-document"><button aria-label="Menu">Menu</button></div></body></html>';
$aboutHtml = '<html><body><div class="data-liberation-mobile-document"><button aria-label="Menu">Menu</button></div></body></html>';
$routed = $project($files(
    array('https://example.test/' => $homeHtml, 'https://example.test/about' => $aboutHtml),
    array('https://example.test/' => array($state($menuTrigger)))
));
$assert(1 === ($routed['projected_count'] ?? 0), 'route scoped matching projects only the captured route');
$assert(str_contains((string) $routed['files'][0]['content'], 'data-blocks-engine-captured-dialog="true"'), 'the captured route receives the dialog');
$assert(! str_contains((string) $routed['files'][1]['content'], 'data-blocks-engine-captured-dialog'), 'an uncaptured route does not receive another route trigger');

$bothRoutes = $project($files(
    array('https://example.test/' => $homeHtml, 'https://example.test/about' => $aboutHtml),
    array('https://example.test/' => array($state($menuTrigger)), 'https://example.test/about' => array($state($menuTrigger)))
));
$assert(2 === ($bothRoutes['projected_count'] ?? 0) && array() === $codes($bothRoutes), 'each captured route binds its own dialog');

$ambiguousHtml = '<html><body><header><button aria-label="Menu">Menu</button><button aria-label="Menu">Menu</button></header></body></html>';
$ambiguous = $project($files(array('https://example.test/ambiguous' => $ambiguousHtml), array('https://example.test/ambiguous' => array($state($menuTrigger)))));
$assert(0 === ($ambiguous['projected_count'] ?? -1), 'ambiguous matches fail closed');
$assert(in_array('captured_dialog_trigger_ambiguous', $codes($ambiguous), true), 'ambiguous matches emit a fail-closed diagnostic');
$assert(! str_contains((string) $ambiguous['files'][0]['content'], 'data-blocks-engine-captured-dialog'), 'ambiguous matches do not inject a dialog');
$assert($ambiguousHtml === $ambiguous['files'][0]['content'], 'ambiguous matching does not mutate the source document');

$missingHtml = '<html><body><header><button aria-label="Search">Search</button></header></body></html>';
$missing = $project($files(array('https://example.test/missing' => $missingHtml), array('https://example.test/missing' => array($state($menuTrigger)))));
$assert(0 === ($missing['projected_count'] ?? -1), 'missing matches fail closed');
$assert(in_array('captured_dialog_trigger_unmatched', $codes($missing), true), 'missing matches emit unmatched diagnostics');

$conflicting = $project($files(array('https://example.test/conflict' => '<html><body><header><button aria-label="Search">Open</button></header></body></html>'), array(
    'https://example.test/conflict' => array($state(array('selector' => 'body > header > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Menu', 'dataBindings' => array()))),
)));
$assert(0 === ($conflicting['projected_count'] ?? -1), 'conflicting captured metadata vetoes a structural match');
$assert(in_array('captured_dialog_trigger_unmatched', $codes($conflicting), true), 'a vetoed structural match does not fall through to weaker evidence');

$selectorHtml = '<html><body><div class="data-liberation-mobile-document"><div><div><div></div><div><header><nav><div><button type="button">Open</button></div></nav></header></div></div></div></div></body></html>';
$selectorTrigger = array('selector' => 'body > div > div > div:nth-of-type(2) > header > nav > div > button', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => '', 'dataBindings' => array());
$selector = $project($files(array('https://example.test/selector' => $selectorHtml), array('https://example.test/selector' => array($state($selectorTrigger)))));
$assert(1 === ($selector['projected_count'] ?? 0), 'wrapper-normalized positional selectors match inside a responsive document');

$closeRuntime = 'document.querySelector("[data-dla-dialog-close]");';
$closeTrigger = array('selector' => 'body > header > button', 'tag' => 'button', 'ariaHaspopup' => 'dialog', 'label' => 'Menu', 'dataBindings' => array());
$closeHtml = '<html><head><script data-dla-disclosure-runtime="true">' . $closeRuntime . '</script></head><body><header><button type="button" data-dla-dialog-trigger="dla-dialog-0" aria-haspopup="dialog" aria-label="Menu">Menu</button></header><button type="button" hidden data-dla-dialog-close="dla-dialog-0" aria-label="Close Menu">Close</button></body></html>';
$closeRows = $files(array('https://example.test/' => $closeHtml), array('https://example.test/' => array($state($closeTrigger))));
$closeRows[] = array('path' => 'website/index.inline-4.js', 'content' => $closeRuntime, 'source' => 'inline-script', 'source_path' => 'website/index.html');
$close = $project($closeRows);
$closeMarkup = (string) ($close['files'][0]['content'] ?? '');
$assert(1 === ($close['projected_count'] ?? 0) && str_contains($closeMarkup, 'data-blocks-engine-add-close="true"'), 'a dialog without an in-panel close still gets the native close control');
$assert(!str_contains($closeMarkup, 'data-dla-dialog-close') && !str_contains($closeMarkup, 'data-dla-disclosure-runtime'), 'a matched capture close helper and its runtime are removed');
$assert(array() === array_values(array_filter($close['files'], static fn (array $file): bool => 'website/index.inline-4.js' === ($file['path'] ?? ''))), 'the extracted disclosure script is omitted');
$assert('native_dialog_close_replaces_capture_close_helper' === ($close['native_runtime_replacements'][0]['reason'] ?? ''), 'replacement proof names the native dialog close');
$siblingHtml = str_replace('</body>', '<button type="button" hidden data-dla-dialog-close="dla-dialog-9" aria-label="Close leftover">Close</button></body>', $closeHtml);
$sibling = $project($files(array('https://example.test/' => $siblingHtml), array('https://example.test/' => array($state($closeTrigger)))));
$siblingMarkup = (string) ($sibling['files'][0]['content'] ?? '');
$assert(!str_contains($siblingMarkup, 'data-dla-dialog-close="dla-dialog-0"') && str_contains($siblingMarkup, 'data-dla-dialog-close="dla-dialog-9"') && str_contains($siblingMarkup, 'data-dla-disclosure-runtime'), 'an unmatched close helper stays with its runtime');
$panelDialog = '<div><button type="button" hidden data-dla-dialog-close="dla-dialog-0">Close</button><p>Panel</p></div>';
$panelState = array('status' => 'captured', 'trigger' => $closeTrigger, 'dialog' => array('html' => $panelDialog, 'htmlBytes' => strlen($panelDialog), 'htmlTruncated' => false));
$panel = $project($files(array('https://example.test/' => $closeHtml), array('https://example.test/' => array($panelState))));
$panelMarkup = (string) ($panel['files'][0]['content'] ?? '');
$assert(1 === substr_count($panelMarkup, 'data-dla-dialog-close="dla-dialog-0"') && str_contains($panelMarkup, '<dialog') && str_contains($panelMarkup, 'data-dla-disclosure-runtime'), 'a matching close helper inside the projected dialog stays with its runtime');
$assert(!str_contains($panelMarkup, 'data-blocks-engine-add-close'), 'an in-panel close is not duplicated by a generated close control');

// A capture that already wired its dialogs in the exported document: each
// trigger sits beside an in-place hidden panel with a hidden close helper. Both
// the matched trigger and one whose positional selector no longer matches must
// bind natively so the wiring runtime is retired.
$wiredRuntime = 'document.querySelectorAll("[data-dla-dialog-trigger]");';
$wiredPanel = static fn (string $key, string $body): string => '<div class="dla-dialog" role="dialog" aria-modal="true" hidden id="' . $key . '" data-dla-dialog-panel="' . $key . '">' . $body . '</div>';
$wiredHtml = '<html><head><script data-dla-disclosure-runtime="true">' . $wiredRuntime . '</script></head><body><main>'
    . '<header><button type="button" aria-label="Menu" data-dla-disclosure-label="Menu" data-dla-dialog-trigger="dla-dialog-1" aria-controls="dla-dialog-1" aria-expanded="false" aria-haspopup="menu">Menu</button>'
    . $wiredPanel('dla-dialog-1', '<nav><a href="/a">Alpha</a></nav>') . '</header>'
    . '<section><div><button type="button" data-dla-disclosure-label="Card title Open the record" data-dla-dialog-trigger="dla-dialog-0" aria-controls="dla-dialog-0" aria-expanded="false" aria-haspopup="dialog">Card title</button>'
    . '<button type="button" hidden data-dla-dialog-close="dla-dialog-0" aria-label="Close Card title">Close</button>'
    . $wiredPanel('dla-dialog-0', '<div><h2>Record details</h2></div>') . '</div></section></main></body></html>';
$wiredStates = array(
    $state(array('selector' => 'body > main > header > button', 'tag' => 'button', 'ariaHaspopup' => 'menu', 'label' => 'Menu', 'dataBindings' => array())),
    array('status' => 'captured', 'trigger' => array('selector' => 'body > main > section > div:nth-of-type(3) > button', 'tag' => 'button', 'ariaHaspopup' => 'dialog', 'label' => 'Card title Open the record', 'dataBindings' => array()), 'dialog' => array('html' => '<div><h2>Record details</h2></div>', 'htmlBytes' => strlen('<div><h2>Record details</h2></div>'), 'htmlTruncated' => false)),
);
$wiredRows = $files(array('https://example.test/' => $wiredHtml), array('https://example.test/' => $wiredStates));
$wiredRows[] = array('path' => 'website/index.inline-5.js', 'content' => $wiredRuntime, 'source' => 'inline-script', 'source_path' => 'website/index.html');
$wired = $project($wiredRows);
$wiredMarkup = (string) ($wired['files'][0]['content'] ?? '');
$assert(2 === substr_count($wiredMarkup, '<dialog'), 'wired panels become exactly one native dialog each', $wiredMarkup);
$assert(!str_contains($wiredMarkup, 'data-dla-dialog-panel') && !str_contains($wiredMarkup, 'data-dla-dialog-close'), 'the in-place panels and close helpers are consumed');
$assert(str_contains($wiredMarkup, 'Record details') && str_contains($wiredMarkup, 'Alpha'), 'the panel content is preserved');
$assert(!str_contains($wiredMarkup, 'data-dla-disclosure-runtime'), 'the wiring runtime is retired once every trigger is bound');
$assert(array() === $codes($wired), 'no unmatched-trigger diagnostics are raised for wired triggers', implode(',', $codes($wired)));
$assert(array() === array_values(array_filter($wired['files'], static fn (array $file): bool => 'website/index.inline-5.js' === ($file['path'] ?? ''))), 'the extracted wiring script is omitted');

if (0 !== $failures) {
    fwrite(STDERR, "captured-dialog-projector failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: captured-dialog-projector passed ({$passes} assertions)\n";

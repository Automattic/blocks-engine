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

if (0 !== $failures) {
    fwrite(STDERR, "captured-dialog-projector failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: captured-dialog-projector passed ({$passes} assertions)\n";

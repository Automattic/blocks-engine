<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ScrollStateProjector;

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

$project = static function (array $files): array {
    return (new ScrollStateProjector())->project($files);
};
$codes = static function (array $result): array {
    return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $result['diagnostics'] ?? array())));
};
$toggle = static function (array $target, array $overrides = array()): array {
    return array_merge(array(
        'status' => 'captured',
        'target' => $target,
        'thresholdPx' => 80,
        'classes' => array('add' => array('sticky-animate'), 'remove' => array()),
        'styleTargets' => array(),
    ), $overrides);
};
$files = static function (array $pages, array $togglesByUrl, array $documentScopeClasses = array()): array {
    $routes = array();
    $pageRows = array();
    $reportPages = array();
    foreach ($pages as $url => $html) {
        $path = 'website/' . trim(parse_url($url, PHP_URL_PATH) ?: '/', '/') . '/index.html';
        if ('website//index.html' === $path) {
            $path = 'website/index.html';
        }
        $routes[] = array('url' => $url, 'path' => $path);
        $pageRows[] = array('path' => $path, 'content' => $html);
        $reportPages[] = array('sourceUrl' => $url, 'toggles' => $togglesByUrl[$url] ?? array());
    }
    // The schema/vendor fields below are producer-shaped test evidence proving
    // the engine ignores unknown/mismatched schema identities and only reads
    // the shape it needs (routes, pages) plus the generic, consumer-declared
    // document_scope_classes list — never a hardcoded capture-tool name.
    $receipt = array('schema' => 'example-capture-tool/capture-receipt/v1', 'routes' => $routes);
    if (array() !== $documentScopeClasses) {
        $receipt['document_scope_classes'] = $documentScopeClasses;
    }
    $pageRows[] = array('path' => 'capture-receipt.json', 'content' => json_encode($receipt, JSON_UNESCAPED_SLASHES));
    $pageRows[] = array('path' => 'scroll-states.json', 'content' => json_encode(array('schema' => 'example-capture-tool/captured-scroll-states/v1', 'pages' => $reportPages), JSON_UNESCAPED_SLASHES));
    return $pageRows;
};

// --- id match -------------------------------------------------------------
$idHtml = '<html><body><header id="site-header" class="hdr"><img id="logo" style="max-height:100px" src="logo.png"></header></body></html>';
$idResult = $project($files(array('https://example.test/' => $idHtml), array('https://example.test/' => array(
    $toggle(array('selector' => '#site-header', 'tag' => 'header', 'id' => 'site-header'), array(
        'styleTargets' => array(array(
            'selector' => '#logo',
            'tag' => 'img',
            'id' => 'logo',
            'properties' => array('max-height' => array('rest' => '100px', 'scrolled' => '50px')),
        )),
    )),
))));
$assert(1 === ($idResult['projected_count'] ?? 0), 'an id-addressed target projects one scroll-state marker');
$markup = (string) ($idResult['files'][0]['content'] ?? '');
$assert(str_contains($markup, 'data-blocks-engine-scroll-state="true"'), 'the matched element receives the scroll-state marker');
$assert(str_contains($markup, 'sticky-animate') && str_contains($markup, '"scrolled":"50px"'), 'the projected config carries the captured class and style diff');
$assert(array() === $codes($idResult), 'a clean id match emits no diagnostics');

// --- structural selector match (no id) -------------------------------------
$structuralHtml = '<html><body><header><div><div><nav></nav></div></div></header></body></html>';
$structuralResult = $project($files(array('https://example.test/structural' => $structuralHtml), array('https://example.test/structural' => array(
    $toggle(array('selector' => 'body > header', 'tag' => 'header')),
))));
$assert(1 === ($structuralResult['projected_count'] ?? 0), 'a structural body-rooted selector matches without an id');

// --- unmatched selector -----------------------------------------------------
$unmatched = $project($files(array('https://example.test/unmatched' => '<html><body><header id="a"></header></body></html>'), array('https://example.test/unmatched' => array(
    $toggle(array('selector' => '#missing', 'tag' => 'header', 'id' => 'missing')),
))));
$assert(0 === ($unmatched['projected_count'] ?? -1), 'an unmatched target projects nothing');
$assert(in_array('captured_scroll_state_target_unmatched', $codes($unmatched), true), 'an unmatched target is reported');

// --- responsive document scopes: the engine's own generic wrapper -----------
// ResponsiveDocumentVariants composes document-variant pairs under its own
// `site-document-variant-*` classes with no consumer configuration required.
$engineScopedHtml = '<html><body>'
    . '<div class="site-document-variant-default"><header id="d1"></header></div>'
    . '<div class="site-document-variant-mobile"><header id="d1"></header></div>'
    . '</body></html>';
$engineScoped = $project($files(array('https://example.test/engine-scoped' => $engineScopedHtml), array('https://example.test/engine-scoped' => array(
    $toggle(array('selector' => '#d1', 'tag' => 'header', 'id' => 'd1')),
))));
$assert(2 === ($engineScoped['projected_count'] ?? 0), 'a duplicate id across the engine\'s own site-document-variant scopes projects once per scope with no consumer configuration');

// --- responsive document scopes: a consumer-declared wrapper class ----------
// A capture tool that marks scope with its own (non-engine) class tokens
// declares those tokens itself via the receipt's document_scope_classes list.
// The engine never hardcodes any such tool-specific token.
$consumerScopedHtml = '<html><body>'
    . '<div class="example-capture-tool-desktop-document"><header id="d1"></header></div>'
    . '<div class="example-capture-tool-mobile-document"><header id="d1"></header></div>'
    . '</body></html>';
$declaredClasses = array('example-capture-tool-desktop-document', 'example-capture-tool-mobile-document');
$consumerScoped = $project($files(array('https://example.test/consumer-scoped' => $consumerScopedHtml), array('https://example.test/consumer-scoped' => array(
    $toggle(array('selector' => '#d1', 'tag' => 'header', 'id' => 'd1')),
)), $declaredClasses));
$assert(2 === ($consumerScoped['projected_count'] ?? 0), 'a duplicate id across consumer-declared document_scope_classes projects once per scope');
$again = $project($files(array('https://example.test/consumer-scoped' => $consumerScopedHtml), array('https://example.test/consumer-scoped' => array(
    $toggle(array('selector' => '#d1', 'tag' => 'header', 'id' => 'd1')),
)), $declaredClasses));
$assert($consumerScoped === $again, 'projection is deterministic');

// An undeclared, consumer-specific wrapper class is not recognized as a scope
// boundary, so the duplicate id becomes ambiguous within one shared scope.
$undeclaredScoped = $project($files(array('https://example.test/undeclared-scoped' => $consumerScopedHtml), array('https://example.test/undeclared-scoped' => array(
    $toggle(array('selector' => '#d1', 'tag' => 'header', 'id' => 'd1')),
))));
$assert(0 === ($undeclaredScoped['projected_count'] ?? -1), 'an undeclared consumer wrapper class is not treated as a document scope boundary');
$assert(in_array('captured_scroll_state_target_ambiguous', $codes($undeclaredScoped), true), 'a duplicate id with no declared scope is reported as ambiguous');

// --- invalid / missing sidecars are inert, not fatal -------------------------
$noSidecar = $project(array(array('path' => 'website/index.html', 'content' => '<html><body></body></html>')));
$assert(0 === ($noSidecar['projected_count'] ?? -1) && array() === $noSidecar['diagnostics'], 'no scroll-states.json is a silent no-op');

// The report is consumed structurally: an arbitrary/mismatched schema string
// does not block projection, because this projector never checks a vendor
// schema identity. Only the shape (pages) is required.
$anySchema = $project($files(array('https://example.test/any-schema' => $idHtml), array('https://example.test/any-schema' => array(
    $toggle(array('selector' => '#site-header', 'tag' => 'header', 'id' => 'site-header')),
))));
$assert(1 === ($anySchema['projected_count'] ?? 0), 'a report and receipt with an unrecognized, non-vendor-specific schema string still project by shape alone');

$malformedShape = $project(array(
    array('path' => 'website/index.html', 'content' => '<html><body></body></html>'),
    array('path' => 'scroll-states.json', 'content' => json_encode(array('schema' => 'anything', 'pages' => 'not-an-array'))),
));
$assert(in_array('captured_scroll_states_invalid', $codes($malformedShape), true), 'a malformed pages shape is reported and ignored regardless of its schema value');

$scopeHtml = '<html><body><div id="topBar" class="topbar"></div></body></html>';
$scopeResult = $project($files(array('https://example.test/scope' => $scopeHtml), array('https://example.test/scope' => array(
    $toggle(array('selector' => '#topBar', 'tag' => 'div', 'id' => 'topBar'), array(
        'classes' => array('add' => array(), 'remove' => array()),
        'styleTargets' => array(array(
            'selector' => ':scope',
            'tag' => 'div',
            'id' => 'topBar',
            'properties' => array(
                'background-color' => array('rest' => 'rgba(0, 0, 0, 0)', 'scrolled' => 'rgb(43, 43, 43)'),
                'position' => array('rest' => 'absolute', 'scrolled' => 'fixed'),
            ),
        )),
    )),
))));
$assert(1 === ($scopeResult['projected_count'] ?? 0), 'a :scope computed-style target still projects onto the header bar');
$assert(str_contains((string) ($scopeResult['files'][0]['content'] ?? ''), '":scope"') && str_contains((string) ($scopeResult['files'][0]['content'] ?? ''), 'background-color'), 'the projected config keeps the :scope computed rest/scrolled styles');

$prefixed = $files(array('https://example.test/prefixed' => $scopeHtml), array('https://example.test/prefixed' => array(
    $toggle(array('selector' => '#topBar', 'tag' => 'div', 'id' => 'topBar'), array(
        'classes' => array('add' => array(), 'remove' => array()),
        'styleTargets' => array(array(
            'selector' => ':scope',
            'tag' => 'div',
            'id' => 'topBar',
            'properties' => array('background-color' => array('rest' => 'rgba(0, 0, 0, 0)', 'scrolled' => 'rgb(43, 43, 43)')),
        )),
    )),
)));
foreach ($prefixed as $index => $file) {
    if ('scroll-states.json' === ($file['path'] ?? '')) {
        $prefixed[$index]['path'] = 'website/scroll-states.json';
    }
    if ('capture-receipt.json' === ($file['path'] ?? '')) {
        $prefixed[$index]['path'] = 'website/capture-receipt.json';
    }
}
$prefixedResult = $project($prefixed);
$assert(1 === ($prefixedResult['projected_count'] ?? 0), 'scroll-states.json still projects when the sidecar is nested under website/');

if (0 !== $failures) {
    fwrite(STDERR, "scroll-state-projector failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: scroll-state-projector passed ({$passes} assertions)\n";

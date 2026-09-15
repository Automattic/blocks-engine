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
$files = static function (array $pages, array $togglesByUrl): array {
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
    $pageRows[] = array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => $routes), JSON_UNESCAPED_SLASHES));
    $pageRows[] = array('path' => 'scroll-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-scroll-states/v1', 'pages' => $reportPages), JSON_UNESCAPED_SLASHES));
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

// --- responsive document scopes: one toggle per scope -----------------------
$responsiveHtml = '<html><body>'
    . '<div class="data-liberation-desktop-document"><header id="d1"></header></div>'
    . '<div class="data-liberation-mobile-document"><header id="d1"></header></div>'
    . '</body></html>';
$responsive = $project($files(array('https://example.test/responsive' => $responsiveHtml), array('https://example.test/responsive' => array(
    $toggle(array('selector' => '#d1', 'tag' => 'header', 'id' => 'd1')),
))));
$assert(2 === ($responsive['projected_count'] ?? 0), 'a duplicate id across responsive document scopes projects once per scope');
$again = $project($files(array('https://example.test/responsive' => $responsiveHtml), array('https://example.test/responsive' => array(
    $toggle(array('selector' => '#d1', 'tag' => 'header', 'id' => 'd1')),
))));
$assert($responsive === $again, 'projection is deterministic');

// --- invalid / missing sidecars are inert, not fatal -------------------------
$noSidecar = $project(array(array('path' => 'website/index.html', 'content' => '<html><body></body></html>')));
$assert(0 === ($noSidecar['projected_count'] ?? -1) && array() === $noSidecar['diagnostics'], 'no scroll-states.json is a silent no-op');

$invalidSchema = $project(array(
    array('path' => 'website/index.html', 'content' => '<html><body></body></html>'),
    array('path' => 'scroll-states.json', 'content' => json_encode(array('schema' => 'wrong', 'pages' => array()))),
));
$assert(in_array('captured_scroll_states_invalid', $codes($invalidSchema), true), 'an unrecognized schema is reported and ignored');

if (0 !== $failures) {
    fwrite(STDERR, "scroll-state-projector failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: scroll-state-projector passed ({$passes} assertions)\n";

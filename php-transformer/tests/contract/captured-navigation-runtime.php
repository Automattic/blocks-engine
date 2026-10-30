<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};
// Only the published disclosure runtime is retired (CaptureEvidence::DISCLOSURE_RUNTIME_SHA256).
$helper = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-runtime.js'));
$item = static function (string $id, string $label, string $extra = ''): string {
    return '<li class="item"><a id="' . $id . '" href="/' . $id . '" aria-haspopup="menu" aria-controls="' . $id . '-panel" aria-expanded="false" data-blocks-engine-capture-dialog-trigger="' . $id . '-panel"' . $extra . '>' . $label . '</a>'
        . '<ul hidden id="' . $id . '-panel" data-blocks-engine-capture-dialog-panel="' . $id . '-panel" class="dropdown"><li><a href="/' . $id . '/a">Alpha</a></li><li><a href="/' . $id . '/b">Beta</a></li></ul></li>';
};
$nav = '<header><nav class="bar"><ul><li><a href="/">Home</a></li>'
    . $item('shop', 'Shop', ' data-blocks-engine-capture-dialog-ancestor-unverified="ancestor-limit"') . $item('care', 'Care')
    . '<li><a href="/contact">Contact</a></li></ul></nav></header>';
$scope = static function (string $name, string $extra = '') use ($nav): string {
    return '<div data-blocks-engine-capture-device-document="' . $name . '" data-blocks-engine-capture-document-scope="' . $name . '">' . $nav . $extra . '<main><h1>' . $name . '</h1><p>Neutral page copy.</p></main></div>';
};
$page = static function (string $body, ?string $runtime = null) use ($helper): string {
    return '<!doctype html><html><head><script>window.captureMarker=true;</script><script data-blocks-engine-capture-disclosure-runtime="true">' . ($runtime ?? $helper) . '</script></head><body>' . $body . '</body></html>';
};
$dialog = '<ul><li><a href="/shop/a">Alpha</a></li><li><a href="/shop/b">Beta</a></li></ul>';
$state = static function (string $selector, string $label) use ($dialog): array {
    return array('status' => 'captured', 'kind' => 'dialog', 'trigger' => array('selector' => $selector, 'tag' => 'a', 'ariaHaspopup' => 'menu', 'label' => $label, 'dataBindings' => array()),
        'dialog' => array('html' => $dialog, 'htmlBytes' => strlen($dialog), 'htmlTruncated' => false, 'role' => 'menu'));
};
$artifact = static function (string $html, array $states): array {
    return array('entrypoint' => 'website/index.html', 'files' => array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components($states)))))),
    ));
};
$scriptPaths = static function (array $result): array {
    $paths = array();
    foreach ($result['assets'] ?? array() as $asset) {
        if (str_contains((string) ($asset['content'] ?? ''), 'data-blocks-engine-capture-dialog-trigger') || str_contains((string) ($asset['path'] ?? ''), 'inline')) $paths[] = (string) ($asset['path'] ?? '');
    }
    sort($paths);
    return $paths;
};
$compileBoth = static function (array $input): array {
    $compiler = new ArtifactCompiler();
    $whole = $compiler->compile($input)->toArray();
    $shared = $compiler->prepareShared($input);
    $staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($input, $shared)))->toArray();
    return array($whole, $staged);
};
$ownedHtml = $page($scope('desktop') . $scope('mobile'));
$ownedStates = array($state('#shop', 'Shop'), $state('#care', 'Care'));
[$whole, $staged] = $compileBoth($artifact($ownedHtml, $ownedStates));
foreach (array('whole' => $whole, 'staged' => $staged) as $driver => $result) {
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $reasons = array_column($result['source_reports']['native_runtime_replacements'] ?? array(), 'reason');
    $assert(2 === substr_count($markup, '<!-- wp:navigation '), $driver . ' emits one native navigation per document scope');
    $assert(4 === substr_count($markup, '<!-- wp:navigation-submenu '), $driver . ' lowers both submenu anchors in each scope');
    $assert(str_contains($markup, '"label":"Shop"') && str_contains($markup, '"label":"Alpha"'), $driver . ' keeps submenu labels and child links');
    $assert(!str_contains($markup, 'data-blocks-engine-capture-dialog-trigger') && !str_contains($markup, 'data-blocks-engine-capture-disclosure-runtime'), $driver . ' generated markup has no capture disclosure controller');
    $assert(!in_array('website/index.inline-2.js', $scriptPaths($result), true), $driver . ' omits the replaced disclosure helper');
    $assert(array('native_navigation_submenu_replaces_capture_disclosure') === array_values(array_unique($reasons)), $driver . ' replacement proof names native submenu ownership');
    $findings = $result['source_reports']['runtime_dependency_parity']['findings'] ?? array();
    $dialogFindings = array_values(array_filter($findings, static fn (array $row): bool => str_contains((string) ($row['selector'] ?? ''), 'dialog-trigger') || str_contains((string) ($row['selector'] ?? ''), '-panel')));
    $assert(array() === $dialogFindings, $driver . ' has no missing disclosure targets after retirement', json_encode($dialogFindings));
}
$assert(($whole['source_reports']['native_runtime_replacements'] ?? null) === ($staged['source_reports']['native_runtime_replacements'] ?? null), 'whole and staged retirement proofs match');
foreach (array('no observed states', 'no interaction report') as $case) {
    $input = $artifact($ownedHtml, array());
    if ('no interaction report' === $case) array_pop($input['files']);
    foreach ($compileBoth($input) as $result) {
        $assetBodies = array_column($result['assets'] ?? array(), 'content');
        $assert(!in_array($helper, $assetBodies, true), $case . ' still proves native ownership from the in-place controls');
        $assert(array() !== ($result['source_reports']['native_runtime_replacements'] ?? array()), $case . ' records retirement provenance');
        $assert(in_array('window.captureMarker=true;', $assetBodies, true), $case . ' retains the unrelated inline script');
    }
}
// One changed byte makes the body unknown. Every navigation retirement path
// (observed states, no observed states, no interaction report) keeps it.
$altered = str_replace('function triggers(){', 'function triggers( ){', $helper);
$assert($altered !== $helper, 'the altered body differs by one byte');
foreach (array('observed states' => $ownedStates, 'no observed states' => array(), 'no interaction report' => null) as $case => $states) {
    $input = $artifact($page($scope('desktop') . $scope('mobile'), $altered), $states ?? array());
    if (null === $states) array_pop($input['files']);
    foreach ($compileBoth($input) as $result) {
        $assert(array() === ($result['source_reports']['native_runtime_replacements'] ?? array()), $case . ': an unrecognized disclosure body is not retired', json_encode($result['source_reports']['native_runtime_replacements'] ?? null));
        $assert(in_array($altered, array_column($result['assets'] ?? array(), 'content'), true), $case . ': the unrecognized disclosure body ships with the page');
    }
}
// A real core/navigation render captured and wired by DLA, not a synthetic menu.
$coreHtml = (string) file_get_contents(dirname(__DIR__) . '/fixtures/core-navigation-capture.html');
$coreReport = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/core-navigation-states.json'), true);
$assert(true === $coreReport['states'][0]['dialog']['restingInline'], 'the real capture proves resting-inline presentation');
$assert(str_contains($coreHtml, $helper), 'the real capture carries the exact current producer body');
foreach (array('observed' => $coreReport['states'], 'no states' => array(), 'no report' => null) as $case => $states) {
    $input = $artifact($coreHtml, $states ?? array());
    if (null === $states) array_pop($input['files']);
    $input['files'][] = array('path' => 'website/style.css', 'content' => (string) file_get_contents(dirname(__DIR__) . '/fixtures/core-navigation-style.css'));
    // Projection must not mutate any of the page's runtime-owned wiring.
    $projection = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector())->project($input['files']);
    $assert($coreHtml === $projection['files'][0]['content'], $case . ': resting-inline source wiring stays byte-identical');
    foreach ($compileBoth($input) as $result) {
        $assert(array() === ($result['source_reports']['native_runtime_replacements'] ?? array()), $case . ': no native replacement proof for the resting-inline page');
        $assert(in_array($helper, array_column($result['assets'] ?? array(), 'content'), true), $case . ': the current runtime ships with the page');
        $assert(str_contains(json_encode($result['diagnostics'] ?? array()), 'captured_disclosure_resting_inline_runtime_kept'), $case . ': diagnostics explain the responsive native parity gap');
        $markup = (string) ($result['serialized_blocks'] ?? '');
        $assert(str_contains($markup, 'data-blocks-engine-capture-existing-panel') && str_contains($markup, 'data-blocks-engine-capture-dialog-trigger'), $case . ': imported markup retains the runtime panel and trigger bindings');
    }
}
// No framework-name or active resting-marker dependency: the same evidence
// must be protected even at a width where the producer hid the panel.
$neutralHtml = str_replace('wp-block-navigation', 'neutral-navigation', $coreHtml);
$neutralHtml = preg_replace('/ data-blocks-engine-capture-resting-panel(?:="[^"]*")?/', '', $neutralHtml);
$neutralInput = $artifact($neutralHtml, $coreReport['states']);
$neutralProjection = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector())->project($neutralInput['files']);
$assert($neutralHtml === $neutralProjection['files'][0]['content'] && 0 === $neutralProjection['projected_count'], 'generic reused non-dropdown evidence keeps the page untouched without an active resting marker');
$assert(str_contains(json_encode($neutralProjection['diagnostics']), 'captured_disclosure_resting_inline_runtime_kept'), 'generic evidence receives the same explicit parity diagnostic');
$unresolved = '<section><div id="notes-toggle" class="menu-cue" role="button" tabindex="0" aria-label="Notes" aria-haspopup="dialog" aria-controls="notes-panel" data-blocks-engine-capture-dialog-trigger="notes-panel"><span></span><span></span><span></span></div><div hidden id="notes-panel" data-blocks-engine-capture-dialog-panel="notes-panel"><p>Unresolved notes.</p></div></section>';
foreach (array('observed' => array_merge($ownedStates, array($state('#notes-toggle', 'Notes'))), 'unobserved' => array()) as $case => $states) {
    foreach ($compileBoth($artifact($page($scope('desktop', $unresolved)), $states)) as $mixed) {
        $mixedMarkup = (string) ($mixed['serialized_blocks'] ?? '');
        $assert(str_contains($mixedMarkup, '<!-- wp:navigation-submenu ') && str_contains($mixedMarkup, 'data-blocks-engine-capture-dialog-trigger="notes-panel"') && str_contains($mixedMarkup, 'data-blocks-engine-capture-document-scope="desktop"'), $case . ' mixed document keeps the unresolved control and its scope');
        $assert(in_array('website/index.inline-2.js', $scriptPaths($mixed), true), $case . ' non-nav control keeps the disclosure runtime');
        $assert(array() === ($mixed['source_reports']['native_runtime_replacements'] ?? array()), $case . ' mixed ownership does not claim complete retirement');
    }
}
if (0 < $failures) { fwrite(STDERR, "captured navigation runtime FAILED: {$passes} passed, {$failures} failed\n"); exit(1); }
echo "captured navigation runtime passed: {$passes} assertions\n";

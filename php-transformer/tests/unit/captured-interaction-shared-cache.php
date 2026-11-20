<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CaptureProjection;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedComponentStates;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedInteractionReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$files = $pages = $routes = array();
foreach (range(1, 3) as $index) {
    $url = 'https://example.test/page-' . $index . '/';
    $path = 'page-' . $index . '.html';
    $files[] = array('path' => $path, 'content' => '<!doctype html><html><head><title>Sample</title></head><body><main><section><button id="control">Open</button><p>Neutral content</p></section></main></body></html>');
    $routes[] = array('url' => $url, 'path' => $path);
    // Unsupported evidence exercises sharing without asking a projector to rewrite HTML.
    $pages[] = array('sourceUrl' => $url, 'components' => 1 === $index ? array(array('ref' => 'control')) : array());
}
$files[] = array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURE_RECEIPT_SCHEMA, 'routes' => $routes)));
$report = array('schema' => CaptureEvidence::CAPTURED_INTERACTIONS_SCHEMA, 'pages' => $pages,
    'sharedComponents' => array('control' => array('kind' => 'unsupported', 'selector' => '#control', 'status' => 'captured', 'states' => array())));
$files[] = array('path' => 'interaction-states.json', 'content' => json_encode($report));

$count = new ReflectionProperty(CapturedComponentStates::class, 'resolutions');
if (!getenv('BE_SHARED_CACHE_BENCHMARK')) {
    $assert(array() === CapturedInteractionReport::diagnostics($files), 'valid report has no reader diagnostics');
    $assert(0 === $count->getValue(), 'cold diagnostics do not resolve shared evidence');
}

// A fresh process can benchmark exactly this fixture against either reader implementation.
$started = hrtime(true);
(new ArtifactCompiler())->prepareShared(array('entrypoint' => 'page-1.html', 'files' => $files));
$elapsed = (hrtime(true) - $started) / 1000000;
if (getenv('BE_SHARED_CACHE_BENCHMARK')) {
    echo sprintf("prepareShared: %.3f ms\n", $elapsed);
    return;
}

$assert(1 === $count->getValue(), 'prepareShared resolves identical shared-evidence inputs only once');
$first = CapturedInteractionReport::read($files);
$assert(1 === count($first['pages'][1]['components']), 'evidence is shared to an identical subtree');
$assert(1 === $count->getValue(), 'staged and original layouts reuse identical routed HTML');
$projected = (new CaptureProjection())->project($files);
$assert($projected === (new CaptureProjection())->project($files), 'repeated projections remain byte-identical');
$assert(1 === $count->getValue(), 'projectors reuse the resolved report');

$changed = $files;
$changed[1]['content'] = str_replace('Neutral content', 'Different content', $changed[1]['content']);
$second = CapturedInteractionReport::read($changed);
$assert(2 === $count->getValue(), 'rewritten routed HTML invalidates shared evidence');
$assert(array() === $second['pages'][1]['components'], 'a changed subtree does not inherit stale evidence');
$assert($second === CapturedInteractionReport::read($changed), 'rewritten input is cached too');
$assert(2 === $count->getValue(), 'each distinct HTML input resolves once');
$changed[] = array('path' => 'unrelated.css', 'content' => 'body { color: black; }');
$changed[] = array('path' => 'unrouted.html', 'content' => '<p>Not a report page</p>');
CapturedInteractionReport::read($changed);
$assert(2 === $count->getValue(), 'unrelated inputs do not invalidate evidence');

$report['pages'][0]['components'][] = array('ref' => 'missing');
$changed[4]['content'] = json_encode($report);
$diagnostics = CapturedInteractionReport::diagnostics($changed);
$assert('captured_interaction_ref_unresolved' === $diagnostics[0]['code'], 'reader diagnostics come from reference expansion');
$assert(2 === $count->getValue(), 'diagnostics never resolve shared evidence');
CapturedInteractionReport::read($changed);
$assert(3 === $count->getValue(), 'changed report content invalidates evidence');

// Receipt routing is an input even when all HTML bytes stay unchanged.
$routes[1]['path'] = 'page-1.html';
$changed[3]['content'] = json_encode(array('schema' => CaptureEvidence::CAPTURE_RECEIPT_SCHEMA, 'routes' => $routes));
$rerouted = CapturedInteractionReport::read($changed);
$assert(4 === $count->getValue() && 1 === count($rerouted['pages'][1]['components']), 'receipt changes invalidate by resolved HTML');
$cache = new ReflectionProperty(CapturedInteractionReport::class, 'resolvedCache');
foreach (range(1, 5) as $variant) {
    $changed[0]['content'] .= '<!-- variant ' . $variant . ' -->';
    CapturedInteractionReport::read($changed);
    $assert(count($cache->getValue()) <= 4, 'resolved cache is bounded');
}
$assert(9 === $count->getValue(), 'distinct inputs resolve even after eviction');
$assert($first === CapturedInteractionReport::read($files), 'evicted input recomputes identically');
$assert(10 === $count->getValue(), 'oldest input was evicted');
echo "Captured interaction shared-evidence cache passed\n";

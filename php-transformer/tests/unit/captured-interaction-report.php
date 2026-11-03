<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../fixtures/captured-components.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedInteractionReport;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedChoiceGroupProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedSelectableSetProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionProjector;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$dialog = array('status' => 'captured', 'trigger' => array('selector' => '#menu', 'tag' => 'button', 'label' => 'Menu', 'ariaHaspopup' => 'dialog', 'dataBindings' => array()),
    'dialog' => array('html' => '<nav><a href="/about">About</a></nav>', 'htmlBytes' => strlen('<nav><a href="/about">About</a></nav>'), 'htmlTruncated' => false));
$tabs = array();
$choices = array();
foreach (array('Alpha', 'Beta') as $index => $label) {
    $html = '<div><h2>' . $label . '</h2></div>';
    $tabs[] = array('status' => 'captured', 'kind' => 'selectable-set',
        'trigger' => array('selector' => 'body > main > div:nth-of-type(1) > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button', 'label' => $label),
        'dialog' => array('selector' => 'body > main > div:nth-of-type(2)', 'tag' => 'div', 'html' => $html, 'htmlBytes' => strlen($html), 'htmlTruncated' => false),
        'set' => array('selector' => 'body > main > div:nth-of-type(1)', 'size' => 2, 'index' => $index));
    $html = '<div id="choices"><button>One</button><button>Two</button></div>';
    $choices[] = array('status' => 'captured', 'kind' => 'choice-group',
        'trigger' => array('selector' => 'body > main > form > div > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button'),
        'choiceGroup' => array(
            'group' => array('selector' => 'body > main > form > div', 'tag' => 'div', 'label' => 'Rating', 'formSelector' => 'body > main > form'),
            'choices' => array_map(static fn(int $i): array => array('index' => $i, 'selector' => 'body > main > form > div > button:nth-of-type(' . ($i + 1) . ')', 'tag' => 'button', 'value' => null), array(0, 1)),
            'transition' => array('selectedIndex' => $index, 'selected' => array(null, null), 'html' => $html, 'htmlBytes' => strlen($html), 'htmlTruncated' => false),
            'replay' => 'activation-determined', 'restoration' => 'verified', 'coverage' => 'complete'));
}
$source = '<html><body><button id="menu" aria-haspopup="dialog">Menu</button><main><div id="tabs"><button>Alpha</button><button>Beta</button></div><div id="panel"><p>Select an item</p></div><form><div id="choices"><button>One</button><button>Two</button></div></form></main></body></html>';
$collectionFiles = require __DIR__ . '/../fixtures/collection-component.php';
$collectionReport = json_decode($collectionFiles[2]['content'], true);
$table = array(
    array(CapturedDialogProjector::class, $source, captured_components(array($dialog))),
    array(CapturedChoiceGroupProjector::class, $source, captured_components($choices)),
    array(CapturedSelectableSetProjector::class, $source, captured_components($tabs)),
    array(CapturedCollectionProjector::class, $collectionFiles[0]['content'], $collectionReport['pages'][0]['components']),
);
foreach ($table as [$class, $html, $components]) {
    $shared = array();
    foreach ($components as $component) $shared['sha256:' . substr(hash('sha256', json_encode($component)), 0, 16)] = $component;
    $results = array();
    foreach (array('v2', 'v3') as $version) {
        $schema = 'v2' === $version ? CaptureEvidence::CAPTURED_INTERACTIONS_V2_SCHEMA : CaptureEvidence::CAPTURED_INTERACTIONS_SCHEMA;
        $files = $pages = $routes = array();
        foreach (range(1, 3) as $route) {
            $url = 'https://example.test/route-' . $route . '/';
            $path = 'website/route-' . $route . '/index.html';
            $files[] = array('path' => $path, 'content' => $html);
            $routes[] = array('url' => $url, 'path' => $path);
            // A route-only inline entry also tests mixed storage and order.
            $entries = 'v2' === $version ? $components : array_map(static fn(string $id): array => array('ref' => $id), array_keys($shared));
            $entries[] = array('kind' => 'unsupported', 'selector' => '#route-' . $route);
            $pages[] = array('schema' => $schema, 'sourceUrl' => $url, 'components' => $entries);
        }
        $files[] = array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURE_RECEIPT_SCHEMA, 'routes' => $routes)));
        $report = array('schema' => $schema, 'pages' => $pages);
        if ('v3' === $version) $report['sharedComponents'] = $shared;
        $files[] = array('path' => 'interaction-states.json', 'content' => json_encode($report));
        $decoded = CapturedInteractionReport::read($files);
        $assert($decoded['pages'][0]['components'] === array_merge($components, array(array('kind' => 'unsupported', 'selector' => '#route-1'))), $class . ': ordered expansion');
        $result = (new $class())->project($files);
        $assert(3 === $result['projected_count'], $class . ': all routes project: ' . json_encode(array($result['projected_count'], $result['diagnostics'])));
        $assert(end($result['files'])['content'] === end($files)['content'], 'sidecar is not rewritten');
        array_pop($result['files']); // The original sidecars intentionally differ by encoding.
        $results[$version] = $result;
    }
    $assert(serialize($results['v2']) === serialize($results['v3']), $class . ': byte-identical projected files and diagnostics');
    $report['pages'][0]['components'][] = array('ref' => 'missing');
    $report['pages'][1]['components'][] = array('ref' => 'also-missing');
    $files[count($files) - 1]['content'] = json_encode($report);
    $unresolved = (new $class())->project($files);
    $warnings = array_values(array_filter($unresolved['diagnostics'], static fn(array $d): bool => 'captured_interaction_ref_unresolved' === $d['code']));
    $assert(1 === count($warnings) && 'warning' === $warnings[0]['severity'] && 2 === count($warnings[0]['context']['references']), 'one structured warning for skipped refs');
    $assert(3 === $unresolved['projected_count'], 'unresolved refs do not prevent valid projections');
    $assert(1 === count(CapturedInteractionReport::mergeDiagnostics($warnings, $warnings)), 'pipeline merges reader warnings once');
    $assert($unresolved === (new $class())->project($files), 'cached report remains immutable across projections');
}
echo "Captured interaction report parity passed\n";

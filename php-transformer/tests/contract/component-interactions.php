<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = dirname(__DIR__) . '/fixtures/';
$report = json_decode(file_get_contents($fixture . 'component-capture.json'), true, 512, JSON_THROW_ON_ERROR);
$compile = static function (array $report, ?string $html = null) use ($fixture): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
        array('path' => 'website/index.html', 'content' => $html ?? file_get_contents($fixture . 'component-capture.html')),
        array('path' => 'interaction-states.json', 'content' => json_encode($report, JSON_THROW_ON_ERROR)),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => CaptureEvidence::CAPTURE_RECEIPT_SCHEMA, 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
    )))->toArray();
};
$markup = static fn(array $result): string => $result['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'];
$result = $compile($report);
$html = $markup($result);
$assert(!isset($report['pages'][0]['states']), 'Real emitter fixture has one component inventory.');
$assert(array('dialog', 'listbox', 'tabs', 'disclosure', 'carousel', 'unsupported') === array_column($report['pages'][0]['components'], 'kind'), 'Real capture covers all six surfaces.');
foreach (array('custom/captured-dialog', 'custom/authored-select', 'tabs', 'tab-list', 'tab-panels', 'tab-panel', 'details', 'custom/authored-carousel') as $block) {
    $assert(str_contains($html, '<!-- wp:' . $block . ' ') || str_contains($html, '<!-- wp:' . $block . ' -->'), $block . ' keeps its pre-migration native block family.');
}
$assert(str_contains($html, '"triggerIds":["open-dialog"]') && str_contains($html, '"resting":"hidden"'), 'Dialog retains its native trigger binding and closed resting state.');
$assert(str_contains($html, '<option value="Small" selected>Small</option>') && str_contains($html, '<option value="Large">Large</option>'), 'Listbox retains native selection behavior.');
$assert(2 === substr_count($html, '<!-- wp:tab-panel ') && str_contains($html, 'Lazy observed content'), 'Lazy tab is editable native panel content.');
$assert(str_contains($html, '"showContent":true') && str_contains($html, '<details id="disclosure" class="wp-block-details" open>'), 'Authored open disclosure stays open.');
$assert(str_contains($html, '"slideCount":2') && str_contains($html, '"initialSlide":0') && str_contains($html, 'actions.next'), 'Carousel retains its initial frame and native directional runtime.');
$assert(!str_contains($html, 'Unsupported secret content') && !str_contains($html, 'id="unknown-panel"'), 'Unsupported surface materializes no hidden panel.');
$proofs = $result['source_reports']['native_runtime_replacements'] ?? array();
$assert(in_array(CaptureEvidence::COMPONENT_RUNTIME, array_column($proofs, 'attribute'), true) && in_array(CaptureEvidence::COMPONENT_CAROUSEL_RUNTIME, array_column($proofs, 'attribute'), true), 'Native tabs and carousel replace their exact capture helpers.');

// A partial node must not infer a third panel/slide from controls or transitions.
$partial = $report;
$partial['pages'][0]['components'][2]['status'] = 'partial';
$partial['pages'][0]['components'][2]['transitions'][] = array('from' => 'tab-1', 'to' => 'tab-2', 'trigger' => array('selector' => '#unobserved', 'action' => 'click'));
$partial['pages'][0]['components'][4]['status'] = 'partial';
$partialHtml = $markup($compile($partial));
$assert(2 === substr_count($partialHtml, '<!-- wp:tab-panel ') && str_contains($partialHtml, '"slideCount":2'), 'Partial components project only observed states.');

// Even valid-looking probe evidence under an unsupported node is not permission.
$unsupported = $report;
$unsupported['pages'][0]['components'] = array(array_merge($report['pages'][0]['components'][0], array('kind' => 'unsupported', 'status' => 'unsupported')));
$bare = '<html><body><button id="open-dialog">Open</button></body></html>';
$assert(!str_contains($markup($compile($unsupported, $bare)), '<!-- wp:custom/captured-dialog'), 'Unsupported node cannot smuggle a probe into the dialog projector.');

// Neither the retired schema nor a flat page inventory is read.
$legacy = array('schema' => 'blocks-engine-capture/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array($report['pages'][0]['components'][0]['states'][1]['evidence']))));
$assert(!str_contains($markup($compile($legacy, $bare)), '<!-- wp:custom/captured-dialog'), 'Retired schema is not a parallel reader.');
$legacy['schema'] = CaptureEvidence::CAPTURED_INTERACTIONS_SCHEMA;
$assert(!str_contains($markup($compile($legacy, $bare)), '<!-- wp:custom/captured-dialog'), 'V2 never falls back to a flat trigger inventory.');
echo "Component interactions public compiler contract passed.\n";

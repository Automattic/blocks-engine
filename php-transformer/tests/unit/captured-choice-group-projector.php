<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedChoiceGroupProjector;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$htmlFor = static function (int $selected): string {
    $buttons = '';
    for ($index = 0; $index < 3; ++$index) {
        $fill = $index <= $selected ? 'fill:gold' : 'fill:none';
        $buttons .= '<button data-blocks-engine-node-id="button-' . $index . '" type="button" data-blocks-engine-capture-choice-index="' . $index . '"><svg data-blocks-engine-node-id="svg-' . $index . '" style="' . $fill . '"><path data-blocks-engine-node-id="path-' . $index . '" d="M1 1"></path></svg></button>';
    }
    return '<div data-blocks-engine-node-id="group" class="choices"><span data-blocks-engine-node-id="label">Choice controls</span>' . $buttons . '</div>';
};
$state = static function (int $index, string $html, array $selected = array(null, null, null)): array {
    return array(
        'status' => 'captured',
        'kind' => 'choice-group',
        'trigger' => array('selector' => 'body > main > form > div > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button', 'dataBindings' => array()),
        'choiceGroup' => array(
            'group' => array('selector' => 'body > main > form > div', 'tag' => 'div', 'label' => 'Rating', 'formSelector' => 'body > main > form'),
            'choices' => array_map(static fn(int $choice): array => array('index' => $choice, 'selector' => 'body > main > form > div > button:nth-of-type(' . ($choice + 1) . ')', 'tag' => 'button', 'value' => null), range(0, 2)),
            'transition' => array('selectedIndex' => $index, 'selected' => $selected, 'html' => $html, 'htmlBytes' => strlen($html), 'htmlTruncated' => false),
            'replay' => 'activation-determined',
            'restoration' => 'verified',
            'coverage' => 'complete',
        ),
    );
};
$source = '<html><body><main><form><label>Rating</label><div class="choices"><button type="button">One</button><button type="button">Two</button><button type="button">Three</button></div></form></main></body></html>';
$states = array($state(0, $htmlFor(0)), $state(1, $htmlFor(1)), $state(2, $htmlFor(2)));
$files = array(
    array('path' => 'website/index.html', 'content' => $source),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/feedback/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/feedback/', 'components' => captured_components($states)))), JSON_UNESCAPED_SLASHES)),
);
$projected = (new CapturedChoiceGroupProjector())->project($files);
$markup = (string) ($projected['files'][0]['content'] ?? '');
$assert(1 === ($projected['projected_count'] ?? 0), 'complete choice transitions project one group');
$assert(str_contains($markup, 'data-blocks-engine-choice-group="true"'), 'projected group carries the companion marker');
$assert(str_contains($markup, 'data-blocks-engine-choice-config='), 'projected group carries bounded replay configuration');
$assert(! str_contains($markup, 'data-blocks-engine-capture-choice-index'), 'capture-only choice markers do not ship');
$assert(! str_contains($markup, 'data-blocks-engine-node-id'), 'capture node identity does not ship in encoded replay config or initial markup');
$assert(str_contains($files[2]['content'], 'data-blocks-engine-node-id'), 'projection leaves the captured sidecar unchanged');
$assert(str_contains($markup, 'fill:gold') && str_contains($markup, 'fill:none'), 'the initial transition preserves source visual state');

// The shared reader also owns nested captured markup, not just choice transitions.
$shard = '<section DATA-BLOCKS-ENGINE-NODE-ID=shard data-authored="keep"><span data-blocks-engine-node-id=child>Evidence</span></section>';
$shardReport = json_decode($files[2]['content'], true);
$shardReport['pages'][0]['semanticEvidence'] = array('sectionSnapshots' => array(array('html' => $shard, 'htmlBytes' => strlen($shard))));
$shardFiles = $files;
$shardFiles[2]['content'] = json_encode($shardReport);
$read = \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedInteractionReport::read($shardFiles);
$snapshot = $read['pages'][0]['semanticEvidence']['sectionSnapshots'][0];
$assert(! str_contains(strtolower($snapshot['html']), 'data-blocks-engine-node-id'), 'nested semantic-evidence section snapshots lose root and descendant carriers');
$assert(str_contains($snapshot['html'], 'data-authored="keep"') && $snapshot['htmlBytes'] === strlen($snapshot['html']), 'captured markup preserves authored attributes and updates byte counts');

// Each device document is its own scope, whether it is marked by a variant class or declared by the capture.
$main = substr($source, strlen('<html><body>'), -strlen('</body></html>'));
$declared = static fn(string $device): string => CaptureEvidence::DOCUMENT_SCOPE . '="' . $device . '" ' . CaptureEvidence::DEVICE_DOCUMENT . '="' . $device . '"';
foreach (array(
    'variant classes' => '<div class="site-document-variant-desktop">' . $main . '</div><div class="site-document-variant-mobile">' . $main . '</div>',
    'declared scopes' => '<div ' . $declared('desktop') . '>' . $main . '</div><div ' . $declared('mobile') . '>' . $main . '</div>',
) as $label => $body) {
    $scopedFiles = $files;
    $scopedFiles[0]['content'] = '<html><body>' . $body . '</body></html>';
    $assert(2 === ((new CapturedChoiceGroupProjector())->project($scopedFiles)['projected_count'] ?? 0), $label . ' project one group per device document');
}

$sourcePreserved = '<html><body><main><form><label>Rating</label><div class="choices"><button type="button">Saved source label</button><button type="button">Two</button><button type="button">Three</button></div></form></main></body></html>';
$sourcePreservedFiles = $files;
$sourcePreservedFiles[0]['content'] = $sourcePreserved;
$sourcePreservedResult = (new CapturedChoiceGroupProjector())->project($sourcePreservedFiles);
$sourcePreservedMarkup = (string) ($sourcePreservedResult['files'][0]['content'] ?? '');
$assert(str_contains($sourcePreservedMarkup, 'Saved source label'), 'projection preserves the source DOM instead of replacing it with the first transition');

$ineligible = $files;
$ineligibleReport = json_decode((string) $ineligible[2]['content'], true);
$ineligibleReport['pages'][0]['components'][0]['states'][1]['evidence']['choiceGroup']['replay'] = 'unsupported';
$ineligible[2]['content'] = json_encode($ineligibleReport, JSON_UNESCAPED_SLASHES);
$ineligibleResult = (new CapturedChoiceGroupProjector())->project($ineligible);
$assert(0 === ($ineligibleResult['projected_count'] ?? -1), 'unsupported producer evidence is not projected');
$assert(str_contains(json_encode($ineligibleResult['diagnostics'] ?? array()), 'captured_choice_group_ineligible'), 'unsupported producer evidence emits an eligibility diagnostic');
$ineligibleDiagnostic = $ineligibleResult['diagnostics'][0] ?? array();
$assert('behavior_loss' === ($ineligibleDiagnostic['conversion_classification'] ?? null) && 'unsupported_loss' === ($ineligibleDiagnostic['loss_class'] ?? null), 'ineligible choice-group diagnostics count as unsupported loss');

$contentChanging = $files;
$contentChangingReport = json_decode((string) $contentChanging[2]['content'], true);
$contentChangingReport['pages'][0]['components'][1]['states'][1]['evidence']['choiceGroup']['transition']['html'] = str_replace('Choice controls', 'Changed controls', $contentChangingReport['pages'][0]['components'][1]['states'][1]['evidence']['choiceGroup']['transition']['html']);
$contentChangingReport['pages'][0]['components'][1]['states'][1]['evidence']['choiceGroup']['transition']['htmlBytes'] = strlen($contentChangingReport['pages'][0]['components'][1]['states'][1]['evidence']['choiceGroup']['transition']['html']);
$contentChanging[2]['content'] = json_encode($contentChangingReport, JSON_UNESCAPED_SLASHES);
$contentChangingResult = (new CapturedChoiceGroupProjector())->project($contentChanging);
$assert(0 === ($contentChangingResult['projected_count'] ?? -1), 'content-changing transitions are not projected without editable state bindings');

$incomplete = $files;
$incomplete[2]['content'] = json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/feedback/', 'components' => captured_components(array($states[0], $states[1]))))), JSON_UNESCAPED_SLASHES);
$incompleteResult = (new CapturedChoiceGroupProjector())->project($incomplete);
$assert(0 === ($incompleteResult['projected_count'] ?? -1), 'incomplete observed transitions are not replayed');
$assert(str_contains(json_encode($incompleteResult['diagnostics'] ?? array()), 'captured_choice_group_incomplete'), 'incomplete transitions emit a bounded diagnostic');

$nullValue = json_decode((string) $files[2]['content'], true);
$assert(array_key_exists('value', $nullValue['pages'][0]['components'][0]['states'][1]['evidence']['choiceGroup']['choices'][0]) && null === $nullValue['pages'][0]['components'][0]['states'][1]['evidence']['choiceGroup']['choices'][0]['value'], 'unknown original values remain explicit null evidence');

if (0 !== $failures) {
    fwrite(STDERR, "captured-choice-group-projector failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: captured-choice-group-projector passed ({$passes} assertions)\n";

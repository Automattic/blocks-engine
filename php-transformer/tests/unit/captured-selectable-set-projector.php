<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedSelectableSetProjector;

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
    return (new CapturedSelectableSetProjector())->project($files);
};
$codes = static function (array $result): array {
    return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $result['diagnostics'] ?? array())));
};
$member = static function (int $index, string $label, string $html, string $status = 'captured', bool $truncated = false, array $trigger = array(), array $set = array()): array {
    return array(
        'status' => $status,
        'kind' => 'selectable-set',
        'trigger' => array_merge(array('selector' => 'body > main > div > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button', 'label' => $label, 'ariaHaspopup' => '', 'dataBindings' => array()), $trigger),
        'dialog' => array(
            'selector' => 'body > main > div:nth-of-type(2)',
            'tag' => 'div',
            'html' => $html,
            'htmlBytes' => strlen($html),
            'htmlTruncated' => $truncated,
        ),
        'set' => array_merge(array('selector' => 'body > main > div:nth-of-type(1)', 'size' => 2, 'index' => $index), $set),
    );
};
$files = static function (string $html, array $states): array {
    return array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => json_encode(array(
            'schema' => 'data-liberation/captured-interactions/v1',
            'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => $states)),
        ), JSON_UNESCAPED_SLASHES)),
    );
};

$source = '<html><body><main><div><button type="button">Alpha</button><button type="button">Beta</button></div><div><p>Select an item</p></div></main></body></html>';
$alphaHtml = '<div><h2>Alpha</h2><p>Alpha specification</p></div>';
$betaHtml = '<div><h2>Beta</h2><p>Beta specification</p></div>';
$captured = $project($files($source, array($member(0, 'Alpha', $alphaHtml), $member(1, 'Beta', $betaHtml))));
$markup = (string) ($captured['files'][0]['content'] ?? '');
$assert(1 === ($captured['projected_count'] ?? 0), 'two captured members project one selectable set');
$assert(str_contains($markup, 'data-blocks-engine-captured-selectable-set="true"'), 'the shared region is marked as a captured selectable set');
$assert(str_contains($markup, 'role="tablist"') && str_contains($markup, 'aria-label="Items"'), 'the projection emits an accessible tablist');
$assert(! str_contains($markup, 'data-blocks-engine-tablist-presentation="hidden"'), 'a distinct source trigger row keeps a visible tablist');
$assert(str_contains($markup, 'role="tab"') && str_contains($markup, '>Alpha<') && str_contains($markup, '>Beta<'), 'member labels become tab names');
$assert(str_contains($markup, 'Alpha specification') && str_contains($markup, 'Beta specification'), 'each captured region is inlined as a tab panel');
$assert(! str_contains($markup, 'Select an item'), 'the frozen placeholder is replaced');
$assert(str_contains($markup, 'data-blocks-engine-tablist-row='), 'a distinct source trigger row is linked onto the projected tablist');

$rowSource = '<html><body><main><div class="step-row" style="display:flex;width:1216px"><button type="button">Alpha</button><button type="button">Beta</button></div><div class="detail"><p>Select an item</p></div></main></body></html>';
$rowCaptured = $project($files($rowSource, array($member(0, 'Alpha', $alphaHtml), $member(1, 'Beta', $betaHtml))));
$rowMarkup = (string) ($rowCaptured['files'][0]['content'] ?? '');
$assert(1 === preg_match('/role="tablist"[^>]*class="step-row"/', $rowMarkup) || 1 === preg_match('/class="step-row"[^>]*role="tablist"/', $rowMarkup), 'the tablist inherits the source trigger row class');
$assert(str_contains($rowMarkup, 'display:flex') && str_contains($rowMarkup, 'width:1216px'), 'the tablist inherits the source trigger row inline geometry');
$assert(2 === substr_count($rowMarkup, 'data-blocks-engine-tablist-row='), 'the surviving trigger row and tablist share one row identity');

$fragmentSource = '<main><div><button type="button">Alpha</button><button type="button">Beta</button></div><div><p>Select an item</p></div></main>';
$fragment = $project($files($fragmentSource, array($member(0, 'Alpha', $alphaHtml), $member(1, 'Beta', $betaHtml))));
$fragmentMarkup = (string) ($fragment['files'][0]['content'] ?? '');
$assert(1 === ($fragment['projected_count'] ?? 0), 'a body-less document still projects one set');
$assert(! str_contains($fragmentMarkup, 'Select an item') && str_contains($fragmentMarkup, 'Alpha specification'), 'a body-less document replaces the shared region instead of appending');
$assert(! in_array('captured_selectable_set_region_appended', $codes($fragment), true), 'a matched body-less region does not emit the append diagnostic');
$assert(1 === substr_count($markup, 'aria-selected="true"'), 'exactly one tab starts selected');

$failed = $project($files($source, array(
    $member(0, 'Alpha', $alphaHtml),
    $member(1, 'Beta', $betaHtml),
    $member(2, 'Gamma', '<div><p>Gamma</p></div>', 'click-failed'),
    $member(3, 'Delta', '<div><p>Delta</p></div>', 'no-dialog'),
)));
$failedMarkup = (string) ($failed['files'][0]['content'] ?? '');
$assert(1 === ($failed['projected_count'] ?? 0), 'failed members do not prevent projection of captured siblings');
$assert(! str_contains($failedMarkup, 'Gamma') && ! str_contains($failedMarkup, 'Delta'), 'click-failed and no-dialog members do not become controls');
$assert(in_array('captured_selectable_set_member_failed', $codes($failed), true), 'failed members emit an honest diagnostic');

$truncatedHtml = '<div><p>Huge</p></div>';
$truncated = $project($files($source, array(
    $member(0, 'Alpha', $alphaHtml),
    array_merge($member(1, 'Beta', $truncatedHtml), array('dialog' => array(
        'selector' => 'body > main > div:nth-of-type(2)',
        'tag' => 'div',
        'html' => $truncatedHtml,
        'htmlBytes' => strlen($truncatedHtml),
        'htmlTruncated' => true,
    ))),
)));
$assert(0 === ($truncated['projected_count'] ?? -1), 'a truncated sibling leaves fewer than two members and does not project');
$assert(in_array('captured_selectable_set_member_truncated', $codes($truncated), true), 'truncated members emit a diagnostic');
$assert(in_array('captured_selectable_set_insufficient_members', $codes($truncated), true), 'a set that drops below two members is reported');

$dialogOnly = $project($files($source, array(array(
    'status' => 'captured',
    'kind' => 'dialog',
    'trigger' => array('selector' => 'body > main > div > button', 'tag' => 'button', 'label' => 'Open', 'ariaHaspopup' => 'dialog', 'dataBindings' => array()),
    'dialog' => array('html' => '<div><p>Dialog</p></div>', 'htmlBytes' => strlen('<div><p>Dialog</p></div>'), 'htmlTruncated' => false),
))));
$assert(0 === ($dialogOnly['projected_count'] ?? -1) && array() === $codes($dialogOnly), 'dialog-kind states are ignored without diagnostics');

$nineteen = array();
for ($index = 0; $index < 19; ++$index) {
    $html = '<div><h2>Item ' . $index . '</h2><p>' . str_repeat('spec ', 20) . $index . '</p></div>';
    $nineteen[] = $member($index, 'Item ' . $index, $html);
}
$large = $project($files($source, $nineteen));
$largeMarkup = (string) ($large['files'][0]['content'] ?? '');
$assert(1 === ($large['projected_count'] ?? 0), 'nineteen captured members still project as one set');
$assert(19 === substr_count($largeMarkup, 'role="tabpanel"'), 'all nineteen region states are inlined');
$assert(str_contains($largeMarkup, 'Item 0') && str_contains($largeMarkup, 'Item 18'), 'first and last member labels are present');

$dialogLimit = (new CapturedDialogProjector())->project($files($source, $nineteen));
$assert(0 === ($dialogLimit['projected_count'] ?? -1), 'selectable-set states do not count toward the captured-dialog page limit');
$assert(! in_array('captured_interaction_state_limit_exceeded', $codes($dialogLimit), true), 'nineteen selectable-set states do not trip the dialog state cap');

$scripted = $project($files($source, array(
    $member(0, 'Alpha', '<div><p>Safe</p><script>window.x=1</script><form action="https://evil.example"><p>Kept</p></form></div>'),
    $member(1, 'Beta', $betaHtml),
)));
$scriptedMarkup = (string) ($scripted['files'][0]['content'] ?? '');
$assert(str_contains($scriptedMarkup, 'Safe') && str_contains($scriptedMarkup, 'Kept'), 'sanitized region content is retained');
$assert(! str_contains($scriptedMarkup, 'window.x') && ! str_contains($scriptedMarkup, 'evil.example') && ! str_contains($scriptedMarkup, '<script'), 'executable markup and form endpoints are stripped');

$unmatched = $project($files('<html><body><main><p>No region</p></main></body></html>', array($member(0, 'Alpha', $alphaHtml), $member(1, 'Beta', $betaHtml))));
$unmatchedMarkup = (string) ($unmatched['files'][0]['content'] ?? '');
$assert(1 === ($unmatched['projected_count'] ?? 0), 'an unmatched region still projects by appending');
$assert(str_contains($unmatchedMarkup, 'Alpha specification'), 'appended tabs still carry captured content');
$assert(in_array('captured_selectable_set_region_appended', $codes($unmatched), true), 'unmatched regions emit an append diagnostic');

$graphicSource = '<html><body><main><div><svg><g><text>FR1</text><text>1,530 ft²</text></g><g><text>FR2</text><text>1,530 ft²</text></g></svg></div><div><p>Select a zone</p></div></main></body></html>';
$graphicTrigger = static function (int $index, string $label): array {
    return array(
        'selector' => 'body > main > div:nth-of-type(1) > svg > g:nth-of-type(' . ($index + 1) . ')',
        'tag' => 'g',
        'label' => $label,
        'ariaHaspopup' => '',
        'dataBindings' => array(),
    );
};
$graphicSet = array('selector' => 'body > main > div:nth-of-type(1) > svg', 'size' => 2);
$graphic = $project($files($graphicSource, array(
    $member(0, 'FR11,530 ft²', $alphaHtml, 'captured', false, $graphicTrigger(0, 'FR11,530 ft²'), $graphicSet),
    $member(1, 'FR21,530 ft²', $betaHtml, 'captured', false, $graphicTrigger(1, 'FR21,530 ft²'), $graphicSet),
)));
$graphicMarkup = (string) ($graphic['files'][0]['content'] ?? '');
$assert(1 === ($graphic['projected_count'] ?? 0), 'graphic-host members still project one selectable set');
$assert(str_contains($graphicMarkup, 'data-blocks-engine-tablist-presentation="hidden"'), 'triggers inside a graphic host hide the projected tablist');
$assert(! str_contains($graphicMarkup, 'data-blocks-engine-tablist-row='), 'graphic-host triggers do not project a visible trigger-row identity onto the tablist');
$assert(! str_contains($graphicMarkup, 'display:none') && ! str_contains($graphicMarkup, 'display: none'), 'the hidden tablist does not use display:none');
$assert(str_contains($graphicMarkup, 'role="tablist"') && 2 === substr_count($graphicMarkup, 'role="tab"'), 'the hidden tablist remains an accessible tab control');
$assert(str_contains($graphicMarkup, '>FR1 1,530 ft') && str_contains($graphicMarkup, '>FR2 1,530 ft'), 'graphic-host labels keep element-separated text');
$assert(! str_contains($graphicMarkup, '>FR11,530'), 'run-together graphic-host labels are not kept');

if (0 !== $failures) {
    fwrite(STDERR, "captured-selectable-set-projector failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: captured-selectable-set-projector passed ({$passes} assertions)\n";

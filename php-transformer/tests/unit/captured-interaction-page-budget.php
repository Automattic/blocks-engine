<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../fixtures/captured-components.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedChoiceGroupProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedSelectableSetProjector;

$dialog = '<div><p>Captured content</p></div>';
$states = array();
foreach (array('Alpha', 'Beta') as $index => $label) {
    $states[] = array(
        'status' => 'captured', 'kind' => 'selectable-set',
        'trigger' => array('selector' => '#controls > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button', 'label' => $label),
        'dialog' => array('selector' => '#panel', 'tag' => 'div', 'html' => $dialog, 'htmlBytes' => strlen($dialog), 'htmlTruncated' => false),
        'set' => array('selector' => '#controls', 'size' => 2, 'index' => $index),
    );
}
$choices = array();
foreach (array('Alpha', 'Beta') as $index => $label) {
    $html = '<div id="controls"><button data-blocks-engine-capture-choice-index="0" aria-pressed="' . (0 === $index ? 'true' : 'false') . '">Alpha</button><button data-blocks-engine-capture-choice-index="1" aria-pressed="' . (1 === $index ? 'true' : 'false') . '">Beta</button></div>';
    $choices[] = array(
        'status' => 'captured', 'kind' => 'choice-group',
        'trigger' => array('selector' => '#controls > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button'),
        'choiceGroup' => array(
            'group' => array('selector' => 'body > div#controls', 'tag' => 'div', 'label' => 'Choice'),
            'choices' => array_map(static fn(int $i): array => array('index' => $i, 'selector' => '#controls > button:nth-of-type(' . ($i + 1) . ')', 'tag' => 'button', 'value' => null), array(0, 1)),
            'transition' => array('selectedIndex' => $index, 'selected' => array(null, null), 'html' => $html, 'htmlBytes' => strlen($html), 'htmlTruncated' => false),
            'replay' => 'activation-determined', 'restoration' => 'verified', 'coverage' => 'complete',
        ),
    );
}
$cases = array(
    array(new CapturedDialogProjector(), array(array('status' => 'captured', 'trigger' => array('selector' => '#open', 'tag' => 'button', 'label' => 'Open', 'ariaHaspopup' => 'dialog'), 'dialog' => array('html' => $dialog, 'htmlBytes' => strlen($dialog), 'htmlTruncated' => false))), 'captured_interactions_limit_exceeded', 'data-blocks-engine-captured-dialog="true"'),
    array(new CapturedChoiceGroupProjector(), $choices, 'captured_choice_group_limit_exceeded', 'data-blocks-engine-choice-group="true"'),
    array(new CapturedSelectableSetProjector(), $states, 'captured_selectable_set_limit_exceeded', 'data-blocks-engine-captured-selectable-set="true"'),
);
$failures = array();
foreach ($cases as [$projector, $evidence, $code, $marker]) {
    // The last page deliberately repeats valid evidence: it must not be projected.
    $page = array('sourceUrl' => 'https://example.test/', 'components' => captured_components($evidence));
    foreach (array(128, 129) as $pageCount) {
        $pages = array_merge(array($page), array_fill(0, $pageCount - 2, array('sourceUrl' => 'https://example.test/empty/', 'components' => array())), array($page));
        $files = array(
            array('path' => 'website/index.html', 'content' => '<html><body><button id="open" aria-haspopup="dialog">Open</button><div id="controls"><button>Alpha</button><button>Beta</button></div><div id="panel"><p>Select an item</p></div></body></html>'),
            array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
            array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => $pages))),
        );
        $result = $projector->project($files);
        $limits = array_values(array_filter($result['diagnostics'], static fn(array $row): bool => $code === $row['code']));
        if (1 > $result['projected_count'] || !str_contains($result['files'][0]['content'], $marker)) {
            $failures[] = get_class($projector) . ': first page state was not projected (' . $pageCount . ' pages): ' . json_encode($result['diagnostics']);
        }
        if (129 === $pageCount && (1 !== $result['projected_count'] || 1 !== count($limits) || array('max_pages' => 128, 'skipped_pages' => 1) !== ($limits[0]['context'] ?? null) || !str_contains($limits[0]['message'], '1'))) {
            $failures[] = get_class($projector) . ': expected one skipped page diagnostic and only the first page projection';
        }
        if (128 === $pageCount && array() !== $limits) {
            $failures[] = get_class($projector) . ': unexpected page limit diagnostic at the budget';
        }
    }
}
if (array() !== $failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "OK: captured interaction page budget preserves early states and reports skipped pages\n";

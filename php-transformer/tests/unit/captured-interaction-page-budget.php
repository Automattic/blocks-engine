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
    // Duplicate route evidence is harmless and no longer imposes a page budget.
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
        if (array() !== $limits) {
            $failures[] = get_class($projector) . ': unexpected page limit diagnostic';
        }
    }
}
// Only route one observed the mobile toggle. All later routes contain the same
// source header, while the negative control merely reuses the selector.
$pages = $routes = $files = array();
$header = '<header><nav style="--mobile-buffer-y: 0px;--menu-scrollbar-width: 0px;--mobile-offset-x: -0px"><button id="open" aria-haspopup="dialog"><span style="">Open</span></button></nav></header>';
$otherHeader = str_replace(array('--menu-scrollbar-width: 0px;--mobile-offset-x: -0px', ' style=""'), array('--mobile-offset-x: -0px;--menu-scrollbar-width: 0px', ''), $header);
for ($i = 0; $i < 140; ++$i) {
    $url = 'https://example.test/route-' . $i;
    $path = 'website/route-' . $i . '.html';
    $pages[] = array('sourceUrl' => $url, 'components' => 0 === $i ? captured_components($cases[0][1]) : array());
    $routes[] = array('url' => $url, 'path' => $path);
    $files[] = array('path' => $path, 'content' => '<html><body>' . (139 === $i ? str_replace('Open</span>', 'Different</span>', $otherHeader) : (0 === $i ? $header : $otherHeader)) . '<main><p>Route ' . $i . '</p></main></body></html>');
}
$files[] = array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => $routes)));
$files[] = array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => $pages)));
$before = memory_get_usage(true);
$result = (new CapturedDialogProjector())->project($files);
if (139 !== $result['projected_count'] || !str_contains($result['files'][138]['content'], 'data-blocks-engine-captured-dialog="true"') || str_contains($result['files'][139]['content'], 'data-blocks-engine-captured-dialog="true"')) {
    $failures[] = 'Identical source headers must inherit evidence; different subtrees must not: ' . $result['projected_count'];
}
if (memory_get_peak_usage(true) - $before > 64 * 1024 * 1024) $failures[] = 'Shared interaction projection exceeded 64 MiB working memory';
foreach ($result['diagnostics'] as $diagnostic) if (str_contains($diagnostic['code'], 'limit_exceeded')) $failures[] = 'Unexpected route limit';

// Shared template extraction must see the same projected header on every route.
// Include the evidence sidecars while selecting a route that never observed the toggle.
$compiled = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/route-138.html', 'files' => $files,
))->toArray();
$headerParts = $compiled['source_reports']['compiled_site']['template_parts'] ?? array();
if (!$headerParts || !str_contains(json_encode($headerParts), 'data-blocks-engine-triggers') || !str_contains(json_encode($headerParts), 'blocksEngineDialog')) $failures[] = 'Shared header part lost captured trigger or panel';

// Projected accordion title buttons are editable controls, not RichText HTML.
$heading = '<h3 class="bde-accordion__title-tag"><button type="button" class="bde-accordion__button" aria-controls="accordion-panel-150" disabled data-blocks-engine-collection-choice="{&quot;index&quot;:1,&quot;active&quot;:{},&quot;inactive&quot;:{}}">Title</button></h3>';
$converted = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($heading)->toArray();
if (str_contains(json_encode($converted['blocks'] ?? array()), 'core/html') || !empty($converted['fallbacks'])) $failures[] = 'Projected accordion heading fell back to HTML';
// Choice evidence on route 140 must be projected before native heading conversion.
$accordionSource = '<div id="controls"><h3 class="bde-accordion__title-tag"><button class="bde-accordion__button" aria-controls="accordion-panel-150">Alpha</button></h3><h3 class="bde-accordion__title-tag"><button class="bde-accordion__button" aria-controls="accordion-panel-151">Beta</button></h3></div>';
$accordionChoices = $choices;
foreach ($accordionChoices as &$choice) {
    $choice['choiceGroup']['group']['selector'] = 'body > div#controls';
    foreach ($choice['choiceGroup']['choices'] as $i => &$descriptor) $descriptor['selector'] = '#controls > h3:nth-of-type(' . ($i + 1) . ') > button';
    unset($descriptor);
    $transition = &$choice['choiceGroup']['transition'];
    $transition['html'] = preg_replace('/(<button[^>]*>.*?<\/button>)/', '<h3 class="bde-accordion__title-tag">$1</h3>', $transition['html']);
    $transition['htmlBytes'] = strlen($transition['html']);
    unset($transition);
}
unset($choice);
$latePages = array_fill(0, 139, array('sourceUrl' => 'https://example.test/empty', 'components' => array()));
$latePages[] = array('sourceUrl' => 'https://example.test/late', 'components' => captured_components($accordionChoices));
$late = (new CapturedChoiceGroupProjector())->project(array(
    array('path' => 'website/late.html', 'content' => '<html><body>' . $accordionSource . '</body></html>'),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/late', 'path' => 'website/late.html'))))),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => $latePages))),
));
$lateBlocks = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($late['files'][0]['content'])->toArray();
if (1 !== $late['projected_count'] || str_contains($lateBlocks['serialized_blocks'], 'wp:html')) $failures[] = 'Late-page accordion choice failed native projection: ' . json_encode($late['diagnostics']);

if (array() !== $failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "OK: captured interaction page budget has no route-count cap\n";

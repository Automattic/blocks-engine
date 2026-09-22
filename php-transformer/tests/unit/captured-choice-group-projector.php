<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedChoiceGroupProjector;

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
        $buttons .= '<button type="button" data-dla-choice-index="' . $index . '"><svg style="' . $fill . '"><path d="M1 1"></path></svg></button>';
    }
    return '<div class="choices"><span>Choice controls</span>' . $buttons . '</div>';
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
        ),
    );
};
$source = '<html><body><main><form><label>Rating</label><div class="choices"><button type="button">One</button><button type="button">Two</button><button type="button">Three</button></div></form></main></body></html>';
$states = array($state(0, $htmlFor(0)), $state(1, $htmlFor(1)), $state(2, $htmlFor(2)));
$files = array(
    array('path' => 'website/index.html', 'content' => $source),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/feedback/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/feedback/', 'states' => $states))), JSON_UNESCAPED_SLASHES)),
);
$projected = (new CapturedChoiceGroupProjector())->project($files);
$markup = (string) ($projected['files'][0]['content'] ?? '');
$assert(1 === ($projected['projected_count'] ?? 0), 'complete choice transitions project one group');
$assert(str_contains($markup, 'data-blocks-engine-choice-group="true"'), 'projected group carries the companion marker');
$assert(str_contains($markup, 'data-blocks-engine-choice-config='), 'projected group carries bounded replay configuration');
$assert(! str_contains($markup, 'data-dla-choice-index'), 'capture-only choice markers do not ship');
$assert(str_contains($markup, 'fill:gold') && str_contains($markup, 'fill:none'), 'the initial transition preserves source visual state');

$incomplete = $files;
$incomplete[2]['content'] = json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/feedback/', 'states' => array($states[0], $states[1])))), JSON_UNESCAPED_SLASHES);
$incompleteResult = (new CapturedChoiceGroupProjector())->project($incomplete);
$assert(0 === ($incompleteResult['projected_count'] ?? -1), 'incomplete observed transitions are not replayed');
$assert(str_contains(json_encode($incompleteResult['diagnostics'] ?? array()), 'captured_choice_group_incomplete'), 'incomplete transitions emit a bounded diagnostic');

$nullValue = json_decode((string) $files[2]['content'], true);
$assert(array_key_exists('value', $nullValue['pages'][0]['states'][0]['choiceGroup']['choices'][0]) && null === $nullValue['pages'][0]['states'][0]['choiceGroup']['choices'][0]['value'], 'unknown original values remain explicit null evidence');

if (0 !== $failures) {
    fwrite(STDERR, "captured-choice-group-projector failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: captured-choice-group-projector passed ({$passes} assertions)\n";

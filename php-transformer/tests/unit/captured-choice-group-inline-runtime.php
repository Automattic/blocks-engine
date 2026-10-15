<?php
declare(strict_types=1);

/**
 * A captured choice group whose only evidence is inline in the imported HTML
 * (Automattic/blocks-engine#2826): the producer marks the group and its
 * choices and ships one choice runtime holding each choice's transition. No
 * interaction report travels with the page.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$labels = array('Small', 'Medium', 'Large', 'Extra large');
$group = static function (?int $selected) use ($labels): string {
    $buttons = '';
    foreach ($labels as $index => $label) {
        $pressed = $index === $selected ? 'true' : 'false';
        $buttons .= '<button type="button" class="size" aria-pressed="' . $pressed . '" data-blocks-engine-capture-choice-index="' . $index . '">' . $label . '</button>';
    }
    return '<div role="group" aria-label="Size" class="sizes">' . $buttons . '</div>';
};
$page = static function (array $configs) use ($group): string {
    $runtime = str_replace('var configs=[];', 'var configs=' . str_replace('<', '\u003c', (string) json_encode($configs, JSON_UNESCAPED_SLASHES)) . ';', trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-choice-runtime.js')));
    $source = str_replace('<div role="group"', '<div data-blocks-engine-capture-choice-group="0" role="group"', $group(null));
    return '<html><head><script data-blocks-engine-capture-choice-runtime="true">' . $runtime . '</script></head><body><main><h1>Order</h1>' . $source . '</main></body></html>';
};
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array(
    'site' => array('name' => 'Inline Choice Site', 'slug' => 'inline-choice-site'),
    'entrypoint' => 'website/index.html',
    'files' => array(array('path' => 'website/index.html', 'content' => $html)),
))->toArray();
$transitions = array();
foreach (array_keys($labels) as $index) {
    $transitions[(string) $index] = $group($index);
}

$result = $compile($page(array(array('transitions' => $transitions))));
$markup = (string) ($result['serialized_blocks'] ?? '');
$companion = array_values(array_filter($result['source_reports']['companion_plugin_payload']['blocks'] ?? array(), static fn(array $block): bool => 'captured-choice-group' === ($block['name'] ?? null)));
$viewJs = (string) ($companion[0]['view_js'] ?? '');
$config = array();
if (1 === preg_match('/data-blocks-engine-choice-config="([^"]*)"/', $markup, $match)) {
    $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true) ?: array();
}
$diagnostics = (string) json_encode($result['source_reports'] ?? array());
$assert(1 === ($result['source_reports']['captured_interactions']['projected_choice_group_count'] ?? null), 'the inline choice group is projected');
$assert(str_contains($markup, 'inline-choice-site/captured-choice-group'), 'the inline choice group materializes as the captured choice-group companion');
$assert(! str_contains($markup, 'wp:html') && ! str_contains($markup, 'core/html'), 'the inline choice group is not frozen as raw HTML');
$assert(4 === substr_count($markup, '<!-- wp:button ') && str_contains($markup, '>Extra large</button>'), 'the four choices stay editable button blocks');
$assert(! str_contains($markup, 'data-blocks-engine-capture-choice') && ! str_contains($markup, 'configFor'), 'capture markers and the producer runtime do not ship');
$assert(! str_contains($diagnostics, 'runtime_dom_contract_fallback'), 'the consumed producer runtime is not a runtime-island fallback');
$assert(str_contains($viewJs, 'click') && str_contains($viewJs, 'keydown') && str_contains($viewJs, 'bindings'), 'click and keyboard selection replay the bounded state bindings');
$assert(array(0, 1, 2, 3) === array_column($config['states'] ?? array(), 'selectedIndex'), 'every observed selection is carried to the companion behavior');
$pressed = array_map(static fn(array $state): array => array_map(static fn(array $binding): ?string => $binding['nodes'][0]['attributes']['aria-pressed'] ?? null, $state['bindings'] ?? array()), $config['states'] ?? array());
$assert(array(array('true', 'false', 'false', 'false'), array('false', 'true', 'false', 'false'), array('false', 'false', 'true', 'false'), array('false', 'false', 'false', 'true')) === $pressed, 'selecting a choice presses only that choice');

$truncated = $transitions;
unset($truncated['3']);
$invalid = $compile($page(array(array('transitions' => $truncated))));
$invalidMarkup = (string) ($invalid['serialized_blocks'] ?? '');
$assert(0 === ($invalid['source_reports']['captured_interactions']['projected_choice_group_count'] ?? 0), 'incomplete inline evidence is not projected');
$assert(! str_contains($invalidMarkup, 'inline-choice-site/captured-choice-group'), 'incomplete inline evidence keeps the current output');
$assert(str_contains((string) json_encode($invalid['source_reports'] ?? array()), 'captured_choice_group_incomplete'), 'incomplete inline evidence emits the projector diagnostic');

if (0 !== $failures) {
    fwrite(STDERR, "captured-choice-group-inline-runtime failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: captured-choice-group-inline-runtime passed ({$passes} assertions)\n";

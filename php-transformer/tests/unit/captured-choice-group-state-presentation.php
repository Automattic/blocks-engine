<?php
declare(strict_types=1);

/**
 * Automattic/blocks-engine#3044: a captured choice group whose states differ
 * only by the choices' `class`. Conversion resolves the source state's class
 * styles into the choice blocks (inline background/color), which outrank the
 * class the runtime swaps in on selection. Each state's presentation must
 * follow the selection instead of the source state staying painted.
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

// The issue's reduced reproduction: `.on` marks the selected button.
$labels = array('One', 'Two', 'Three');
$group = static function (int $selected) use ($labels): string {
    $buttons = '';
    foreach ($labels as $index => $label) {
        $buttons .= '<button class="' . ($index === $selected ? 'on' : 'off') . '" data-blocks-engine-capture-choice-index="' . $index . '">' . $label . '</button>';
    }
    return '<div id="g">' . $buttons . '</div>';
};
$transitions = array();
foreach (array_keys($labels) as $index) {
    $transitions[(string) $index] = $group($index);
}
$runtime = str_replace(
    'var configs=[];',
    'var configs=' . str_replace('<', '\u003c', (string) json_encode(array(array('transitions' => $transitions)), JSON_UNESCAPED_SLASHES)) . ';',
    trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-choice-runtime.js'))
);
$source = str_replace('<div id="g"', '<div data-blocks-engine-capture-choice-group="0" id="g"', $group(0));
$html = '<html><head><style>.on{background:#122a4d;color:#fff}.off{background:#fff;color:#0b1320}</style>'
    . '<script data-blocks-engine-capture-choice-runtime="true">' . $runtime . '</script></head>'
    . '<body><main>' . $source . '</main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'site' => array('name' => 'Choice State Site', 'slug' => 'choice-state-site'),
    'entrypoint' => 'website/index.html',
    'files' => array(array('path' => 'website/index.html', 'content' => $html)),
))->toArray();

$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'] ?? array(), 'is_array')));
$companion = array_values(array_filter($result['source_reports']['companion_plugin_payload']['blocks'] ?? array(), static fn (array $block): bool => 'captured-choice-group' === ($block['name'] ?? null)));
$viewJs = (string) ($companion[0]['view_js'] ?? '');

$assert(1 === ($result['source_reports']['captured_interactions']['projected_choice_group_count'] ?? null), 'the reduced reproduction projects one choice group');
$assert(3 === substr_count($markup, '<!-- wp:button '), 'the three choices stay editable button blocks');
$assert(str_contains($viewJs, "'data-blocks-engine-choice-active'"), 'the runtime flags each choice as active or inactive on selection');

// The baseline still renders the source state (the static inline paint), so
// the state rules must be able to outrank it once a selection is made.
$assert(str_contains($markup, 'style="color:#fff;background-color:#122a4d">One<'), 'before any selection the source state is rendered');

$groupClass = '';
if (1 === preg_match('/<div id="g" class="([^"]*)" data-blocks-engine-choice-group="true"/', $markup, $match)) {
    foreach (preg_split('/\s+/', $match[1]) ?: array() as $token) {
        if (str_starts_with($token, 'blocks-engine-choice-state-')) {
            $groupClass = $token;
        }
    }
}
$assert('' !== $groupClass, 'the choice group carries a class that scopes its per-state presentation');
$rule = static function (int $index, string $active) use ($css, $groupClass): string {
    $selector = '.' . $groupClass . ' [data-blocks-engine-choice-index="' . $index . '"][data-blocks-engine-choice-active="' . $active . '"]{';
    $start = '' === $groupClass ? false : strpos($css, $selector);
    if (false === $start) {
        return '';
    }
    $start += strlen($selector);
    return substr($css, $start, (int) strpos($css, '}', $start) - $start);
};
foreach (array_keys($labels) as $index) {
    $active = $rule($index, 'true');
    $inactive = $rule($index, 'false');
    $assert(str_contains($active, 'background:#122a4d!important') && str_contains($active, 'color:#fff!important'), "selecting choice {$index} paints it with the active state's background over the baked inline style");
    $assert(str_contains($inactive, 'background:#fff!important') && str_contains($inactive, 'color:#0b1320!important'), "deselecting choice {$index} paints it with the inactive state's background over the baked inline style");
}

// The state classes are bound by the runtime; the static wrappers must not
// keep painting the source state after the selection moves.
$staticClasses = array();
preg_match_all('/<!-- wp:button(?:s)? (\{.*?\}) -->/', $markup, $comments);
foreach ($comments[1] as $json) {
    $attrs = json_decode($json, true);
    foreach (preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array() as $token) {
        $staticClasses[$token] = true;
    }
}
preg_match_all('/<div class="wp-block-button[^"]*"/', $markup, $wrappers);
foreach ($wrappers[0] as $wrapper) {
    foreach (preg_split('/\s+/', substr($wrapper, 12, -1)) ?: array() as $token) {
        $staticClasses[$token] = true;
    }
}
$assert(! isset($staticClasses['on']) && ! isset($staticClasses['off']), 'state-bound classes are not baked onto the static choice wrappers');

if (0 !== $failures) {
    fwrite(STDERR, "captured-choice-group-state-presentation failed: {$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}
echo "OK: captured-choice-group-state-presentation passed ({$passes} assertions)\n";

<?php
declare(strict_types=1);

require_once __DIR__ . '/../fixtures/captured-components.php';

// #3131: a captured selectable set whose region holds every member's content,
// with only the selected one visible, keeps in each tab panel only what that
// member's state renders. Content hidden there at every breakpoint that another
// member renders is pruned; shared content and responsive-hidden UI stay.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if (! $condition) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? PHP_EOL . $detail : '') . PHP_EOL);
    }
};

$descriptions = array('First description text', 'Second description text', 'Third description text');
$region = static function (int $selected) use ($descriptions): string {
    $html = '<div class="card"><div class="left">';
    foreach ($descriptions as $index => $text) {
        $html .= '<div class="desc' . ($index === $selected ? '' : ' hidden') . '"><h3>Item ' . ($index + 1) . '</h3><p>' . $text . '</p></div>';
    }
    $html .= '<p class="hidden md:block">Wide screen note</p>';
    $html .= '<p class="hidden">Always hidden note</p>';

    return $html . '</div><aside><p>Shared aside content</p></aside></div>';
};
$member = static function (int $index, string $label) use ($region): array {
    $html = $region($index);

    return array(
        'status' => 'captured',
        'kind' => 'selectable-set',
        'trigger' => array('selector' => 'body > main > div > button:nth-of-type(' . ($index + 1) . ')', 'tag' => 'button', 'label' => $label, 'ariaHaspopup' => '', 'dataBindings' => array()),
        'dialog' => array('selector' => 'body > main > div:nth-of-type(2)', 'tag' => 'div', 'html' => $html, 'htmlBytes' => strlen($html), 'htmlTruncated' => false),
        'set' => array('selector' => 'body > main > div:nth-of-type(1)', 'size' => 3, 'index' => $index),
    );
};

$style = '<style>.hidden{display:none}@media (min-width:768px){.md\:block{display:block}}.row{display:flex;gap:8px}</style>';
$source = '<html><head>' . $style . '</head><body><main><div class="row"><button type="button">One</button><button type="button">Two</button><button type="button">Three</button></div><div>' . $region(0) . '</div></main></body></html>';

$result = (new ArtifactCompiler())->compile(array(
    'site' => array('name' => 'Selectable Set Dead Content Site', 'slug' => 'selectable-set-dead-content-site'),
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => $source),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array(
            'schema' => 'blocks-engine-capture/capture-receipt/v1',
            'routes' => array(array('url' => 'https://example.com/', 'path' => 'website/index.html')),
        ), JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => json_encode(array(
            'schema' => 'blocks-engine-capture/captured-interactions/v2',
            'pages' => array(array(
                'sourceUrl' => 'https://example.com/',
                'components' => captured_components(array($member(0, 'One'), $member(1, 'Two'), $member(2, 'Three'))),
            )),
        ), JSON_UNESCAPED_SLASHES)),
    ),
))->toArray();

$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(str_contains($markup, '<!-- wp:tabs'), 'the selectable set projects native core/tabs', $markup);
$panels = preg_split('/<!-- wp:tab-panel /', $markup) ?: array();
array_shift($panels);
$assert(3 === count($panels), 'three tab panels are projected', $markup);
foreach ($panels as $index => $panel) {
    foreach ($descriptions as $other => $text) {
        $expected = $other === $index ? 1 : 0;
        $assert($expected === substr_count($panel, $text), sprintf('panel %d holds %s of "%s"', $index, $expected ? 'its own copy' : 'no copy', $text), $panel);
    }
    $assert(1 === substr_count($panel, 'Shared aside content'), sprintf('panel %d keeps the shared aside', $index), $panel);
    $assert(1 === substr_count($panel, 'Wide screen note'), sprintf('panel %d keeps the hidden md:block element', $index), $panel);
    $assert(1 === substr_count($panel, 'Always hidden note'), sprintf('panel %d keeps hidden UI no member renders', $index), $panel);
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' captured selectable-set dead content assertion(s) failed.' . PHP_EOL);
    exit(1);
}

echo 'Captured selectable-set dead member content checks passed.' . PHP_EOL;

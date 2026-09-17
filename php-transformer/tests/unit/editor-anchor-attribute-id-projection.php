<?php
declare(strict_types=1);

/**
 * Contract for projecting an authored id target onto the deterministic editor
 * anchor class when the author addresses it by attribute.
 *
 * The editor replaces a block wrapper's id with its own client id, so a rule
 * written against that id matches nothing on the canvas. The projection exists
 * to restate those rules on `blocks-engine-editor-anchor-<id>`, which survives
 * in both contexts.
 *
 * Mesh builders address a component by attribute at least as often as by id
 * syntax: on a Wix export every child placement rule is `[id="comp-…"]`, and
 * none use `#comp-…`. Projecting only the `#` spelling left the mesh container
 * a grid in the editor while each child lost its `grid-area` and stacked in
 * source order.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$editorCss = static function (string $css): string {
    $html = '<!doctype html><html><body><div id="site-root"><div id="masterPage">'
        . '<div data-mesh-id="secinlineContent-gridContainer">'
        . '<div id="comp-aaa"><p>One</p></div>'
        . '<div id="comp-bbb"><p>Two</p></div>'
        . '</div></div></div></body></html>';
    $result = (new HtmlTransformer())->transform($html, array('source' => 'index.html', 'static_css' => $css));
    $editor = '';
    foreach ((is_array($result->toArray()['assets'] ?? null) ? $result->toArray()['assets'] : array()) as $asset) {
        if (is_array($asset) && 'editor-static-state' === ($asset['source'] ?? '')) {
            $editor .= (string) ($asset['content'] ?? '');
        }
    }
    return $editor;
};

$grid = '[data-mesh-id=secinlineContent-gridContainer]{display:grid;grid-template-columns:100%}';

// Attribute-addressed placement, the spelling a mesh export actually uses.
$attribute = $editorCss(
    $grid
    . '[data-mesh-id=secinlineContent-gridContainer] > [id="comp-aaa"]{grid-area:1 / 1 / 2 / 2;left:20px;position:relative}'
    . '[data-mesh-id=secinlineContent-gridContainer] > [id="comp-bbb"]{grid-area:2 / 1 / 3 / 2;position:relative}'
);
$assert(
    2 === preg_match_all('/blocks-engine-editor-anchor-comp-(?:aaa|bbb)[^{}]*\{[^}]*grid-area/', $attribute),
    'Attribute-addressed child placement reaches the editor anchor class.'
);

// Unquoted and single-quoted spellings are the same target.
foreach (array("[id=comp-aaa]", "[id='comp-aaa']") as $spelling) {
    $projected = $editorCss($grid . '[data-mesh-id=secinlineContent-gridContainer] > ' . $spelling . '{grid-area:1 / 1 / 2 / 2}');
    $assert(
        str_contains($projected, 'blocks-engine-editor-anchor-comp-aaa'),
        'The ' . $spelling . ' spelling projects onto the editor anchor class.'
    );
}

// The id spelling keeps working.
$hash = $editorCss($grid . '[data-mesh-id=secinlineContent-gridContainer] > #comp-aaa{grid-area:1 / 1 / 2 / 2}');
$assert(
    str_contains($hash, 'blocks-engine-editor-anchor-comp-aaa'),
    'The id spelling still projects onto the editor anchor class.'
);

// An id the document never carries stays untouched rather than inventing a class.
$unknown = $editorCss($grid . '[data-mesh-id=secinlineContent-gridContainer] > [id="comp-missing"]{grid-area:1 / 1 / 2 / 2}');
$assert(
    ! str_contains($unknown, 'blocks-engine-editor-anchor-comp-missing'),
    'An id that no source element carries is not projected.'
);

if (0 < $failures) {
    fwrite(STDERR, sprintf('editor anchor attribute id projection: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('editor anchor attribute id projection tests: %d passed%s', $passes, PHP_EOL));

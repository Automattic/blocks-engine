<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A bound captured control's artwork is a core image inside selectable core
// boxes. The native button must not be an opaque contentParts companion.
$result = (new HtmlTransformer())->transform(
    '<div class="control-wrapper"><button id="opener" type="button" class="opener" aria-label="Menu" aria-controls="panel" '
    . 'data-blocks-engine-capture-dialog-ancestor-state="[]" '
    . 'data-blocks-engine-capture-dialog-trigger="panel"><div class="icon"><div>'
    . '<svg viewBox="0 0 20 20" width="20" height="20"><path d="M0 0h20v2H0z"/></svg>'
    . '</div></div></button></div><div id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener" hidden>Menu contents</div>',
    array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]'))
)->toArray();
$markup = (string) $result['serialized_blocks'];
$checks = array(
    !str_contains($markup, '/authored-button'),
    !str_contains($markup, '<!-- wp:html'),
    str_contains($markup, '<!-- wp:group '),
    str_contains($markup, '"blocksEngineControlBox":{"id":"opener","type":"button"'),
    !str_contains($markup, '/layout-shell'),
    str_contains($markup, 'blocksEngineControlBox'),
    str_contains($markup, '<!-- wp:image '),
    str_contains($markup, 'blocksEngineInlineSvg'),
    str_contains($markup, 'data-blocks-engine-capture-dialog-trigger'),
    'pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''),
);
foreach ($checks as $index => $ok) {
    if (!$ok) {
        fwrite(STDERR, 'FAIL: captured icon core contract ' . $index . "\n" . $markup . "\n");
        exit(1);
    }
}
// CSS-only artwork also has a selectable core tree with native source tags.
$painted = (new HtmlTransformer())->transform(
    '<button id="painted" aria-label="Menu" aria-controls="panel" '
    . 'data-blocks-engine-capture-dialog-trigger="panel"><span class="bars"></span></button>'
    . '<div id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="painted" hidden>Menu</div>',
    array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]'))
)->toArray();
$paintedMarkup = (string) $painted['serialized_blocks'];
if (str_contains($paintedMarkup, '/authored-button')
    || !str_contains($paintedMarkup, '"blocksEngineControlTag":"button"')
    || !str_contains($paintedMarkup, '"blocksEngineControlTag":"span"')
    || !str_contains($paintedMarkup, '"class":"bars"')
    || !preg_match('/<button\\b[^>]*>.*<span\\b/s', $paintedMarkup)) {
    throw new RuntimeException('CSS-painted controls retain editable core boxes and source tag metadata.');
}
echo "Captured icon control: " . (count($checks) + 1) . " assertions passed\n";

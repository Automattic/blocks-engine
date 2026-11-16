<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A landmark can start a native wrapper chain; it need not have an outer div.
// Exercise the captured-control path through the public transformer, including
// safe coalescing of the neutral artwork box after runtime control projection.
$result = (new HtmlTransformer())->transform(
    '<nav class="opener"><div class="slot"><button id="opener" aria-label="Menu" aria-controls="panel" '
    . 'data-blocks-engine-capture-dialog-trigger="panel"><div class="icon"><div>'
    . '<svg viewBox="0 0 20 20"><path d="M0 0h20v2H0z"/></svg>'
    . '</div></div></button></div></nav>'
    . '<div id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener" hidden>Menu contents</div>',
    array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]'))
)->toArray();
$markup = (string) $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || !str_contains($markup, '<!-- wp:image ') || !str_contains($markup, '"tagName":"nav"')) {
    throw new RuntimeException('Landmark-rooted icon ancestry must remain core-editable: ' . $markup);
}
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//nav[contains(@class,"opener")]/div[contains(@class,"slot")]/figure[contains(@class,"icon")]/img')->length) {
    throw new RuntimeException('Native wrappers must retain the landmark and icon carrier without redundant artwork groups.');
}
if ('pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) {
    throw new RuntimeException('Native landmark chain must serialize valid core blocks.');
}
echo "Native navigation icon control chain passed\n";

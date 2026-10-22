<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) $failures[] = $message;
};
$compile = static fn (string $body): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Product</title></head><body>' . $body . '</body></html>'),
))->toArray();
$appShells = static fn (array $result): array => array_values(array_filter(
    $result['source_reports']['runtime_islands'] ?? array(),
    static fn (array $island): bool => 'runtime_app_shell' === ($island['preservation_reason'] ?? '')
));

// Reduced issue markup, with executable selector evidence in place of its
// listener placeholder so the real compiler discovers both runtime targets.
$html = <<<'HTML'
<div id="wrapper"><header><nav><a href="/">Home</a> <a href="/about">About</a></nav></header>
<main><h1>Product title</h1><p>Description paragraph.</p>
<button type="button" data-dialog-open="size-guide">Size guide</button>
<div id="size-guide" role="dialog" aria-modal="true" hidden><button type="button" data-dialog-close>Close</button><p>Sizes S M L</p></div>
<button type="button" data-dialog-open="shipping">Shipping</button>
<div id="shipping" role="dialog" aria-modal="true" hidden><button type="button" data-dialog-close>Close</button><p>Ships in 3 days</p></div>
<template id="recommendation-card"><div class="card"><p></p></div></template></main>
<footer><p>Footer text</p></footer></div>
<script>document.querySelectorAll('[data-dialog-open]').forEach(function(button){button.addEventListener('click',function(){});});document.querySelectorAll('[data-dialog-close]').forEach(function(button){button.addEventListener('click',function(){});});</script>
HTML;
// The producer's in-place panel and trigger/close contract, without a document
// scope exemption: these bounded dialogs must not own the surrounding page.
$captured = str_replace(array(
    'data-dialog-open=', 'data-dialog-close>Close',
    'id="size-guide" role=', 'id="shipping" role=',
), array(
    'data-blocks-engine-capture-dialog-trigger=', 'data-blocks-engine-capture-dialog-close="size-guide">Close',
    'data-blocks-engine-capture-dialog-panel="size-guide" id="size-guide" role=',
    'data-blocks-engine-capture-dialog-panel="shipping" id="shipping" role=',
), $html);
$captured = str_replace(array('trigger="size-guide"', 'trigger="shipping"'), array('trigger="size-guide" aria-controls="size-guide"', 'trigger="shipping" aria-controls="shipping"'), $captured);
$captured = str_replace(array('[data-dialog-open]', '[data-dialog-close]'), array('[data-blocks-engine-capture-dialog-trigger]', '[data-blocks-engine-capture-dialog-close]'), $captured);
// Give each close control its own panel key.
$captured = str_replace('id="shipping" role="dialog" aria-modal="true" hidden><button type="button" data-blocks-engine-capture-dialog-close="size-guide"',
    'id="shipping" role="dialog" aria-modal="true" hidden><button type="button" data-blocks-engine-capture-dialog-close="shipping"', $captured);

foreach (array('neutral' => $html, 'capture contract' => $captured,
    'dialog bindings beside a workspace' => str_replace('<template id="recommendation-card">', '<div contenteditable="true">Notes</div><template id="recommendation-card">', $captured),
) as $label => $source) {
    $result = $compile($source);
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(array() === $appShells($result), "$label: no runtime_app_shell finding");
    $assert(str_contains($markup, '<!-- wp:heading'), "$label: native heading");
    $assert(str_contains($markup, '<!-- wp:paragraph') && str_contains($markup, 'Description paragraph.'), "$label: native description");
    $assert(str_contains($markup, '/authored-button') || str_contains($markup, '<!-- wp:button '), "$label: native buttons");
    $assert(str_contains($markup, 'Sizes S M L') && str_contains($markup, 'Ships in 3 days'), "$label: panel content survives");
    $assert(str_contains($markup, '/layout-shell') || str_contains($markup, '/captured-dialog'), "$label: bounded panels remain native blocks");
}

// Isolate workspace evidence with genuine, non-dialog runtime targets.
$surface = static fn (string $workspace): string => '<div id="wrapper"><h1>Product title</h1>'
    . '<button data-run>Run</button><div data-output>Result</div>' . $workspace . '</div>'
    . '<script>document.querySelector("[data-run]").addEventListener("click",function(){document.querySelector("[data-output]").textContent="Done";});</script>';
foreach (array('<template><p>Card</p></template>', '<template><canvas></canvas><div contenteditable="true">Draft</div></template>') as $template) {
    $result = $compile($surface($template));
    $assert(array() === $appShells($result), 'inert template (including its descendants) is not a workspace');
    $assert(str_contains($result['serialized_blocks'] ?? '', '<!-- wp:heading'), 'template does not swallow native content');
}
$result = $compile($surface('<canvas width="320" height="200"></canvas>'));
$assert(array() !== $appShells($result), 'real canvas application surface stays preserved');
$assert(str_contains($result['serialized_blocks'] ?? '', '<!-- wp:html'), 'real app retains its HTML island');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "app shell captured dialog template tests passed\n";

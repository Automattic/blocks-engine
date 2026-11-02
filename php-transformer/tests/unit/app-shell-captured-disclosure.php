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
    'files' => array('index.html' => '<!doctype html><html><head><title>Visit</title></head><body>' . $body . '</body></html>'),
))->toArray();
$appShells = static fn (array $result): array => array_values(array_filter(
    $result['source_reports']['runtime_islands'] ?? array(),
    static fn (array $island): bool => 'runtime_app_shell' === ($island['preservation_reason'] ?? '')
));
$item = static fn (int $n): string => '<li><h3><button data-toggle aria-expanded="false" aria-controls="panel-' . $n . '" data-blocks-engine-capture-component-disclosure="' . $n . '">Question ' . $n . '</button></h3><div id="panel-' . $n . '"><p>Answer ' . $n . '</p></div></li>';
$source = '<div id="canvas"><header><h1>Visit us</h1></header><main><p>Opening hours.</p><ul class="accordion">' . $item(0) . $item(1) . '</ul></main></div>'
    . '<script>document.querySelectorAll("[data-toggle]").forEach(function(button){button.addEventListener("click",function(){});});</script>';

// (a) Two bounded captured targets must not turn the page into an app shell.
$captured = $compile($source);
$markup = $captured['serialized_blocks'] ?? '';
$assert(array() === $appShells($captured), 'captured disclosure targets do not establish a runtime_app_shell');
$assert(str_contains($markup, '<!-- wp:heading') && str_contains($markup, '<!-- wp:paragraph'), 'surrounding page remains editable');
$assert(2 === substr_count($markup, '<!-- wp:accordion-item '), 'both disclosures become native accordion items');
$assert(! str_contains($markup, '<!-- wp:html'), 'captured page has no HTML fallback');

// (d) The same real app-root token with uncaptured runtime targets remains
// preserved. Assert the contrast as well, detecting blanket exemptions.
$uncaptured = $compile(preg_replace('/ data-blocks-engine-capture-component-disclosure="[^"]*"/', '', $source) ?? '');
$assert(array() !== $appShells($uncaptured) && array() === $appShells($captured), 'only uncaptured targets establish the app shell');
$assert(str_contains($uncaptured['serialized_blocks'] ?? '', '<!-- wp:html'), 'uncaptured app keeps its HTML island');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "app shell captured disclosure tests passed\n";

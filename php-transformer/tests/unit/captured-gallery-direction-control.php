<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// A captured gallery whose runtime is kept (no interaction proof retires it)
// steps through icon-only previous/next controls: text-less spans whose arrow
// is painted by source CSS alone. Each control must be an identifiable native
// button that keeps the source selectors and the direction the runtime reads.
$galleryRuntime = trim((string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-gallery-runtime.js'));
$frames = '<div class="frame" data-blocks-engine-capture-gallery-frame="0" data-blocks-engine-capture-gallery-display="block"><img src="/a.png" alt="First"></div>'
    . '<div class="frame" data-blocks-engine-capture-gallery-frame="1" data-blocks-engine-capture-gallery-display="block" hidden><img src="/b.png" alt="Second"></div>';
$control = static fn(string $class, string $direction): string => '<div class="button-wrap" style="display:inline-block"><span class="' . $class . ' step-button" data-blocks-engine-capture-gallery-direction="' . $direction . '"><span class="step-button-inner"><span class="step-button-icon"></span></span><span class="step-button-bg"></span></span></div>';
$page = static fn(string $controls): string => '<html><head><style>.step-button{display:inline-block;width:32px;height:32px}.step-prev .step-button-icon{background:#333}</style><script data-blocks-engine-capture-gallery-runtime>' . $galleryRuntime . '</script></head><body>'
    . '<div class="show" id="show" data-blocks-engine-capture-gallery data-blocks-engine-capture-gallery-source="#show" data-blocks-engine-capture-gallery-index="0">'
    . '<div class="slides" data-blocks-engine-capture-gallery-stage data-blocks-engine-capture-gallery-initial="0">' . $frames . '</div>'
    . '<div class="overlay-left"><div class="button-wrap"><span class="step-play step-button"><span class="step-button-inner">Play <span class="step-button-icon"></span></span></span></div></div>'
    . '<div class="overlay-right">' . $controls . '</div></div></body></html>';
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $html)))->toArray();
$runtime = new Runtime();
$visit = static function (array $blocks) use (&$visit): array { $all = array(); foreach ($blocks as $block) { $all[] = $block; $all = array_merge($all, $visit($block['innerBlocks'] ?? array())); } return $all; };
$failures = array(); $passes = 0;
$assert = static function (bool $ok, string $message) use (&$failures, &$passes): void { if (!$ok) $failures[] = $message; else ++$passes; };

$result = $compile($page($control('step-prev', '-1') . '&nbsp;' . $control('step-next', '1')));
$markup = (string) $result['serialized_blocks'];
$tree = $visit($runtime->parseBlocks($markup));
$controls = array_values(array_filter($tree, static fn(array $block): bool => str_ends_with((string) ($block['blockName'] ?? ''), '/authored-button')));
$assert(0 === substr_count($markup, '<!-- wp:html'), "Icon-only captured direction controls produce no core/html.");
$assert(2 === count($controls), 'Each direction control is its own editable button control block.');
$labels = array_map(static fn(array $block): string => (string) ($block['attrs']['ariaLabel'] ?? ''), $controls);
$assert(array('Previous slide', 'Next slide') === $labels, 'Text-less controls get an accessible name from their captured direction: ' . json_encode($labels));
foreach ($controls as $index => $block) {
    $html = (string) $block['innerHTML'];
    $direction = 0 === $index ? '-1' : '1';
    $class = 0 === $index ? 'step-prev' : 'step-next';
    $assert(str_starts_with($html, '<span ') && str_contains($html, 'role="button"') && str_contains($html, 'tabindex="0"'), 'The control keeps its source element and becomes a focusable role button: ' . $html);
    $assert(str_contains($html, 'class="' . $class . ' step-button"') && str_contains($html, 'data-blocks-engine-capture-gallery-direction="' . $direction . '"'), 'Source classes still paint the arrow and the runtime still reads the direction: ' . $html);
    $assert(str_contains($html, '<span class="step-button-inner"><span class="step-button-icon"') && str_contains($html, '<span class="step-button-bg"></span>'), 'CSS-painted descendants survive inside the control: ' . $html);
}
$assert('pass' === (new BlockValidityValidator())->validateBlocks($result['blocks'])['status'], 'Canonical block structure remains valid.');
$assert($runtime->serializeBlocks($runtime->parseBlocks($markup)) === $markup, 'The control blocks round-trip through parse/serialize.');
$assert('pass' === ($result['source_reports']['runtime_dependency_parity']['status'] ?? null), 'The kept gallery runtime still finds every DOM target it addresses.');
$codes = array_column($result['diagnostics'] ?? array(), 'code');
$assert(!in_array('runtime_dom_contract_fallback', $codes, true), 'No direction control is reported as a raw runtime DOM fallback.');
$retained = array_values(array_filter($result['diagnostics'] ?? array(), static fn(array $row): bool => 'runtime_dom_contract_preserved' === ($row['code'] ?? '') && str_ends_with((string) ($row['block_name'] ?? ''), '/authored-button')));
$assert(1 === count($retained), 'The direction contract is retained by the native control block.');
$assert(str_contains(json_encode($result['assets']), 'function all(selector,root){'), 'The unretired gallery runtime still ships to step the controls.');

// A control with source text or its own accessible name keeps it.
$named = $compile($page(str_replace('<span class="step-button-inner">', '<span class="step-button-inner">Back', $control('step-prev', '-1'))));
$namedControls = array_values(array_filter($visit($runtime->parseBlocks((string) $named['serialized_blocks'])), static fn(array $block): bool => str_ends_with((string) ($block['blockName'] ?? ''), '/authored-button')));
$assert(1 === count($namedControls) && '' === (string) ($namedControls[0]['attrs']['ariaLabel'] ?? '') && str_contains((string) $namedControls[0]['innerHTML'], 'Back'), 'A text-bearing control is not renamed.');

// A responsive capture may wire only one direction (the other unrendered).
$single = $compile($page($control('step-next', '1') . '<div class="button-wrap"><span class="step-prev step-button"><span class="step-button-inner"><span class="step-button-icon"></span></span></span></div>'));
$singleMarkup = (string) $single['serialized_blocks'];
$singleControls = array_values(array_filter($visit($runtime->parseBlocks($singleMarkup)), static fn(array $block): bool => str_ends_with((string) ($block['blockName'] ?? ''), '/authored-button')));
$assert(0 === substr_count($singleMarkup, '<!-- wp:html'), 'A single wired direction produces no core/html.');
$assert(1 === count($singleControls) && 'Next slide' === ($singleControls[0]['attrs']['ariaLabel'] ?? null), 'Only the wired control becomes a direction button.');

// Outside a captured gallery the attribute is not capture evidence.
$orphan = $compile('<html><body><div class="wrap"><span class="step-next" data-blocks-engine-capture-gallery-direction="1"><span class="icon"></span></span></div></body></html>');
$assert(!str_contains((string) $orphan['serialized_blocks'], 'Next slide'), 'A direction attribute outside a captured gallery is not projected as a gallery control.');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Captured gallery direction control contract passed ({$passes} assertions)\n";

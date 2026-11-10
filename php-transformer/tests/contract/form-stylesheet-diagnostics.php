<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$html = '<form method="post"><div class="fields">'
    . '<div><label for="name">Name</label><input id="name" name="name"></div>'
    . '<div><label for="email">Email</label><input id="email" name="email" type="email"></div>'
    . '<div><label for="message">Message</label><textarea id="message" name="message"></textarea></div>'
    . '</div><button type="submit">Send</button></form>';
$css = 'form, .fields, .fields > div { display: flex; gap: 12px; color: #123456; }'
    . 'label { font-size: 14px; } input, textarea { padding: 8px; }'
    . '@media (max-width: 600px) { .fields { flex-direction: column; color: #654321; } }';
$selectors = array(':host(.unrelated)', 'summary::-webkit-details-marker', '.elsewhere:hover');
foreach ($selectors as $selector) $css .= $selector . ' { color: red; }';
// Repeat a selector, including across conditions: its diagnostic is still one stylesheet fact.
$css .= '@media (min-width: 900px) { :host(.unrelated) { color: blue; } }';
$result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
$layout = $result['fallbacks'][0]['layout_graph'];
$presentation = $result['fallbacks'][0]['presentation_graph'];
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); }
};
foreach ($selectors as $selector) {
    $diagnostic = 'unsupported_selector:' . $selector;
    $assert(1 === count(array_filter($layout['diagnostics'], static fn ($item) => $item === $diagnostic)), 'layout graph reports once: ' . $selector);
    $assert(1 === count(array_filter($presentation['diagnostics'], static fn ($item) => $item === $diagnostic)), 'presentation graph reports once: ' . $selector);
}
$elements = array_filter(array_column($layout['nodes'], 'presentation'));
$assert(count($elements) >= 3, 'fixture exercises multiple element presentations');
foreach ($elements as $element) {
    $assert(array() === array_filter($element['diagnostics'], static fn ($item) => str_starts_with($item, 'unsupported_selector:')), 'element diagnostics contain no stylesheet facts');
}
FormLayoutGraphBuilder::assertValid($layout);
FormPresentationGraphBuilder::assertValid($presentation);

$rejects = static function (callable $validate, string $message) use ($assert): void {
    try { $validate(); $assert(false, $message); } catch (InvalidArgumentException) { $assert(true, $message); }
};
$invalidLayout = $layout;
$invalidLayout['diagnostics'][] = $layout['diagnostics'][0];
$rejects(static fn () => FormLayoutGraphBuilder::assertValid($invalidLayout), 'layout validator rejects duplicate diagnostics');
$invalidPresentation = $presentation;
$invalidPresentation['diagnostics'][] = $presentation['diagnostics'][0];
$rejects(static fn () => FormPresentationGraphBuilder::assertValid($invalidPresentation), 'presentation validator rejects duplicate diagnostics');
$element = array_values($elements)[0];
$element['diagnostics'] = array('unsupported_selector::host(.unrelated)');
$rejects(static fn () => FormPresentationGraphBuilder::assertElement($element), 'element validator rejects stylesheet diagnostics');
$element['diagnostics'] = array('provenance_limit');
$element['truncated'] = true;
FormPresentationGraphBuilder::assertElement($element);

// Pin complete transform output except diagnostics and timings, including styles,
// provenance, responsive variants and compiled blocks (not just selected values).
$withoutDiagnostics = static function (array $value) use (&$withoutDiagnostics): array {
    unset($value['diagnostics'], $value['metrics']);
    foreach ($value as &$item) if (is_array($item)) $item = $withoutDiagnostics($item);
    return $value;
};
$hash = hash('sha256', json_encode($withoutDiagnostics($result), JSON_THROW_ON_ERROR));
// Updated only for serialized topology, sibling-pair and layout node limits.
$assert('1702bf7e4bc0d5f66fc41974c078a933e02cf146e8fc163f99358c8f8084b819' === $hash, 'non-diagnostic transform output retains the control-row presentation baseline: ' . $hash);
fwrite(STDOUT, 'fixture layout graph bytes: ' . strlen(json_encode($layout, JSON_THROW_ON_ERROR)) . PHP_EOL);
exit($failures ? 1 : 0);

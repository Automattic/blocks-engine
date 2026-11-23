<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

// #5862: Tailwind v4 stacks a form's items with a child-combinator rule on
// the parent (`:where(.space-y-4>:not(:last-child))`). That margin spaces the
// item slot the provider field wrapper takes over, not the control itself:
// carried onto the control it opens a second gap inside every field.
$html = <<<'HTML'
<style>
*{margin:0;box-sizing:border-box}
:where(.space-y-4>:not(:last-child)){margin-block-start:0;margin-block-end:1rem}
.field{width:100%;padding:.75rem;border:1px solid #333}
.own{margin-block-end:2rem}
</style>
<form class="space-y-4">
  <input class="field" placeholder="Name">
  <input class="field own" placeholder="Email">
  <textarea class="field" placeholder="Message"></textarea>
  <button type="submit">Send</button>
</form>
HTML;
$document = new DOMDocument();
$document->loadHTML($html);
$graph = (new FormPresentationGraphBuilder())->build(
    $document->getElementsByTagName('form')->item(0),
    array(),
    $document->getElementsByTagName('style')->item(0)->textContent
);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$controls = array_column($graph['controls'], 'control', 'index');
foreach (array(0, 2) as $index) {
    $styles = $controls[$index]['styles'] ?? array();
    $assert(!isset($styles['margin_block_end']) && !isset($styles['margin_block_start']), "Control {$index} must not carry its parent's stacking margin: " . json_encode($styles));
    $assert('100%' === ($styles['width'] ?? null), "Control {$index} keeps its own presentation.");
    foreach ($controls[$index]['provenance'] as $fact) {
        $assert(!str_contains((string) $fact['selector'], 'space-y-4'), "Control {$index} provenance must not cite the stacking rule.");
    }
}
$assert('2rem' === ($controls[1]['styles']['margin_block_end'] ?? null), 'A margin the control declares itself still wins and is carried.');
FormPresentationGraphBuilder::assertValid($graph);
echo "form presentation parent stacking margin passed\n";

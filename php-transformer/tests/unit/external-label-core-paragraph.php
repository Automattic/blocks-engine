<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NativeLabelRuntime;

// #6453: a label associated with a control outside its block (`for`, or named
// by `aria-labelledby`) is an editable core paragraph, not a companion block.
// The theme renderer publishes the source `<label>` element in its place.
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$result = (new HtmlTransformer())->transform('<!DOCTYPE html><html><head><style>.caption{font-weight:500}</style></head><body><div class="row"><label class="caption" for="name" style="--gap:1px" data-role="caption" title="A &quot;name&quot;" onclick="bad()">Full <strong>Name</strong></label><p>Hint</p></div></body></html>')->toArray();
$markup = $result['serialized_blocks'];
$assert(!str_contains($markup, 'authored-label') && array() === $result['source_reports']['generated_blocks'], 'No label companion is generated: ' . $markup);
$flatten = static function (array $blocks) use (&$flatten): array { $out = array(); foreach ($blocks as $block) { $out[] = $block; $out = array_merge($out, $flatten($block['innerBlocks'] ?? array())); } return $out; };
$labels = array_values(array_filter($flatten($result['blocks']), static fn (array $block): bool => isset($block['attrs']['metadata']['blocksEngineLabel'])));
$assert(1 === count($labels) && 'core/paragraph' === $labels[0]['blockName'], 'The caption is a core paragraph');
$assert('Full <strong>Name</strong>' === ($labels[0]['attrs']['content'] ?? null), 'The caption keeps its editable rich text');
$assert(in_array('caption', explode(' ', (string) ($labels[0]['attrs']['className'] ?? '')), true), 'The source class is edited on the paragraph');
$assert(array('for' => 'name', 'style' => '--gap:1px', 'data-role' => 'caption', 'title' => 'A "name"') === $labels[0]['attrs']['metadata']['blocksEngineLabel'], 'Association and passive host attributes are metadata, without event handlers');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'The paragraph validates');

function add_filter($name, $callback, ...$args) { $GLOBALS['label_filter'][$name] = $callback; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
eval(NativeLabelRuntime::source());
$render = $GLOBALS['label_filter']['render_block_core/paragraph'];
$class = esc_attr($labels[0]['attrs']['className']);
$html = $render($labels[0]['innerHTML'], $labels[0]);
$assert('<label for="name" class="' . $class . '" style="--gap:1px" data-role="caption" title="A &quot;name&quot;">Full <strong>Name</strong></label>' === $html, 'The front end publishes the source label element: ' . $html);
$labels[0]['attrs']['metadata']['blocksEngineLabel']['onclick'] = 'bad()';
$assert(!str_contains($render($labels[0]['innerHTML'], $labels[0]), 'onclick'), 'Edited metadata cannot add event handlers');
$assert('<p>Hint</p>' === $render('<p>Hint</p>', array('blockName' => 'core/paragraph', 'attrs' => array())), 'Ordinary paragraphs are untouched');

// An empty external label still keeps its association.
$empty = (new HtmlTransformer())->transform('<!DOCTYPE html><html><head></head><body><label for="name"></label></body></html>')->toArray();
$assert(!str_contains($empty['serialized_blocks'], 'authored-label') && '<label for="name"></label>' === $render($empty['blocks'][0]['innerHTML'], $empty['blocks'][0]), 'Empty external labels keep their association: ' . $empty['serialized_blocks']);

// The theme renders labels wherever a page holds one.
$artifact = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><label for="choice">Choice</label><input id="choice" type="checkbox"></main>')))->toArray();
$functions = '';
foreach ($artifact['source_reports']['wordpress_site_plan']['writes'] as $write) if ('functions.php' === ($write['target_path'] ?? null)) $functions = (string) $write['payload']['data'];
$assert(str_contains($functions, "render_block_core/paragraph") && str_contains($functions, 'blocksEngineLabel'), 'The theme installs the label renderer');
echo "External label core paragraph regression passed\n";

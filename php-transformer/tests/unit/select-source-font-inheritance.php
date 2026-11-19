<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>html{line-height:1.5}body{font-family:Arial,sans-serif}.field select{font:inherit;font-size:16px}</style>'
    . '<form class="field" method="post"><label for="topic">Topic</label><select id="topic" name="topic"><option>One</option></select><button>Send</button></form>'
)->toArray();
$styles = $result['fallbacks'][0]['presentation_graph']['controls'][0]['control']['styles'] ?? array();
if ('Arial,sans-serif' !== ($styles['font_family'] ?? null)
    || isset($styles['line_height'])
    || '16px' !== ($styles['font_size'] ?? null)
) {
    throw new RuntimeException('Select must inherit its source font, not the provider dropdown shell: ' . json_encode($styles));
}
// Materializers resolve shorthand overlaps using this public provenance.
// The resolved value still belongs to the select's winning inheritance rule:
// giving it the ancestor's lower specificity lets font:inherit reset it again.
$provenance = $result['fallbacks'][0]['presentation_graph']['controls'][0]['control']['provenance'] ?? array();
foreach (array('font-family') as $property) {
    $owners = array_values(array_filter($provenance, static fn (array $fact): bool => in_array($property, $fact['properties'], true)));
    if (1 !== count($owners) || '.field select' !== $owners[0]['selector']) {
        throw new RuntimeException('Resolved select font must retain its own cascade rank: ' . json_encode($provenance));
    }
}
// Resolving a select must not restyle sibling controls that still inherit
// through their original source container rather than a dropdown wrapper.
$withSibling = (new HtmlTransformer())->transform(
    '<style>html{line-height:1.5}body{font-family:Arial,sans-serif}.field select,.field input{font:inherit;font-size:16px}</style>'
    . '<form class="field" method="post"><label for="name">Name</label><input id="name" name="name"><label for="topic">Topic</label><select id="topic" name="topic"><option>One</option></select><button>Send</button></form>'
)->toArray();
$inputStyles = $withSibling['fallbacks'][0]['presentation_graph']['controls'][0]['control']['styles'] ?? array();
if ('inherit' !== ($inputStyles['font'] ?? null) || isset($inputStyles['font_family']) || isset($inputStyles['line_height'])) {
    throw new RuntimeException('Select family resolution must not change sibling control metrics: ' . json_encode($inputStyles));
}
// The provider dropdown shell must not supply its own bold/italic text when
// the source explicitly inherits the initial values. Exercise the public API
// and compare every non-select control with a form lacking font inheritance.
$markup = '<form method="post"><label for="name">Name</label><input id="name" name="name"><label for="topic">Topic</label><select id="topic" name="topic"><option>One</option></select><button>Send</button></form>';
$transform = static fn (string $css): array => (new HtmlTransformer())->transform('<style>body{font-family:Arial,sans-serif}' . $css . '</style>' . $markup)->toArray()['fallbacks'][0]['presentation_graph']['controls'];
$before = array_column($transform(''), null, 'index');
$after = array_column($transform('select{font:inherit;font-size:16px}'), null, 'index');
foreach (array('font_weight', 'font_style', 'font_variant') as $property) {
    if ('normal' !== ($after[1]['control']['styles'][$property] ?? null)) {
        throw new RuntimeException('Select must not inherit provider text defaults: ' . $property);
    }
}
foreach (array(0, 2) as $index) {
    if (($before[$index] ?? null) !== ($after[$index] ?? null)) throw new RuntimeException('Select inheritance changed another control');
}
echo "Select source font inheritance passed\n";

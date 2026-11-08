<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
// No source classes: membership is the decrease / input / increase position.
$html = '<form method="post"><div><button type="button">−</button><input type="number" name="quantity"><button type="button">+</button></div><button type="submit">Buy</button></form>';
$css = 'form > div { display:flex; width:144px; box-sizing:border-box; border:1px solid #123456; border-radius:24px; background-color:#ffffff; column-gap:0; grid-template-columns:1fr }'
    . '@media (max-width:600px) { form > div { width:120px; box-sizing:content-box; border:2px dashed #654321; border-radius:12px; background-color:#eeeeee; } }';
// Exercise each per-side width/style/color, not just the shorthand.
foreach (array('top', 'right', 'bottom', 'left') as $side) {
    $css .= 'form > div { border-' . $side . '-width:1px; border-' . $side . '-style:solid; border-' . $side . '-color:#123456; }';
    $css .= '@media (max-width:600px) { form > div { border-' . $side . '-width:2px; border-' . $side . '-style:dashed; border-' . $side . '-color:#654321; } }';
}
$result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
$graph = $result['fallbacks'][0]['presentation_graph'];
$assert('generic/computed-form-presentation/v3' === $graph['schema'], 'frame facts use v3');
$rows = array_values(array_filter($graph['control_rows'], static fn (array $row): bool => array(0, 1, 2) === array_column($row['members'], 'index')));
$assert(1 === count($rows), 'the dedicated stepper row has three positional members');
$row = $rows[0];
$expected = array('width' => '144px', 'box_sizing' => 'border-box', 'border' => '1px solid #123456', 'border_radius' => '24px', 'background_color' => '#ffffff');
$patch = array('width' => '120px', 'box_sizing' => 'content-box', 'border' => '2px dashed #654321', 'border_radius' => '12px', 'background_color' => '#eeeeee');
foreach (array('top', 'right', 'bottom', 'left') as $side) {
    foreach (array('width' => array('1px', '2px'), 'style' => array('solid', 'dashed'), 'color' => array('#123456', '#654321')) as $property => $values) {
        $expected['border_' . $side . '_' . $property] = $values[0];
        $patch['border_' . $side . '_' . $property] = $values[1];
    }
}
foreach ($expected as $property => $value) $assert($value === ($row['styles'][$property] ?? null), 'row frame retains ' . $property);
$variants = array_values(array_filter($graph['variants'], static fn (array $variant): bool => 'control_row' === $variant['role'] && $row['index'] === $variant['index']));
$assert(1 === count($variants), 'conditional row frame is a single owned variant');
foreach ($patch as $property => $value) $assert($value === ($variants[0]['style_patch'][$property] ?? null), 'conditional frame retains ' . $property);
$assert(!empty($row['provenance']) && !empty($variants[0]['provenance']) && !empty($variants[0]['condition']), 'frame facts retain provenance and condition');
FormPresentationGraphBuilder::assertValid($graph);

// The shared element vocabulary must not grow with row-only distribution facts.
$document = new DOMDocument();
$document->loadHTML($html);
$element = (new FormPresentationGraphBuilder())->buildElement($document->getElementsByTagName('div')->item(0), array(), $css);
$assert(!isset($element['styles']['column_gap']) && !isset($element['styles']['grid_template_columns']), 'row-only properties never leak into element presentation');
FormPresentationGraphBuilder::assertElement($element);
foreach (array('unknown_frame_property', 'unsafe_value', 'member_frame', 'conditional_property', 'conditional_value') as $mutation) {
    $invalid = $graph;
    if ('unknown_frame_property' === $mutation) $invalid['control_rows'][$row['index']]['styles']['background_image'] = 'none';
    if ('unsafe_value' === $mutation) $invalid['control_rows'][$row['index']]['styles']['width'] = 144;
    if ('member_frame' === $mutation) $invalid['control_rows'][$row['index']]['members'][0]['styles']['border_radius'] = '24px';
    $variantIndex = array_search($variants[0], $graph['variants'], true);
    if ('conditional_property' === $mutation) $invalid['variants'][$variantIndex]['style_patch']['background_image'] = 'none';
    if ('conditional_value' === $mutation) $invalid['variants'][$variantIndex]['style_patch']['border'] = array('2px', 'solid');
    try {
        FormPresentationGraphBuilder::assertValid($invalid);
        $assert(false, 'closed row validation rejects ' . $mutation);
    } catch (InvalidArgumentException) {
        // Expected: the existing closed property and value validation applies.
    }
}
echo "Form control rows retain validated base and conditional frame paint.\n";

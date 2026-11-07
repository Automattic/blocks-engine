<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$document = new DOMDocument();
$document->loadHTML('<form><div class="purchase"><div class="quantity"><input type="number" name="quantity"></div><div class="submit"><button type="submit">Buy</button></div></div></form>');
$css = '
.purchase { display:flex; align-items:start; width:100%; gap:11px; column-gap:11px; grid-template-columns:1fr }
.quantity { flex:3 1 0; min-width:0 }
.submit { flex:10 1 0 }
@media (max-width:600px) {
    .purchase { flex-wrap:wrap }
    .quantity { flex:1 1 90px }
    .submit { flex-grow:2 }
}';
$graph = (new FormPresentationGraphBuilder())->build($document->getElementsByTagName('form')->item(0), array(), $css);
$row = $graph['control_rows'][0] ?? array();
// Row-only properties describe the row fact, never shared element presentation consumed by layout graphs.
$element = (new FormPresentationGraphBuilder())->buildElement($document->getElementsByTagName('div')->item(0), array(), $css);
$variants = array_values(array_filter($graph['variants'], static fn (array $variant): bool => in_array($variant['role'], array('control_row', 'control_row_item'), true)));
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$assert('generic/computed-form-presentation/v3' === $graph['schema'], 'row facts select the versioned schema');
$assert('11px' === ($row['styles']['gap'] ?? null) && 'flex' === ($row['styles']['display'] ?? null), 'shared row retains display and gap');
$assert('3' === ($row['members'][0]['styles']['flex_grow'] ?? null) && '10' === ($row['members'][1]['styles']['flex_grow'] ?? null), 'member wrappers retain the source proportions');
$assert('0' === ($row['members'][0]['styles']['min_width'] ?? null) && isset($row['source_selector'], $row['members'][0]['source_selector']), 'row and item selectors identify source elements');
$assert(3 === count($variants) && 'wrap' === ($variants[0]['style_patch']['flex_wrap'] ?? null) && '90px' === ($variants[1]['style_patch']['flex_basis'] ?? null) && '2' === ($variants[2]['style_patch']['flex_grow'] ?? null), 'one media condition carries container and member patches');
$assert(!empty($row['provenance']) && !empty($row['members'][0]['provenance']) && !empty($variants[0]['condition']) && !empty($variants[0]['provenance']), 'base and conditional facts retain provenance');
$assert(!isset($element['styles']['column_gap']) && !isset($element['styles']['grid_template_columns']) && '11px' === ($row['styles']['column_gap'] ?? null), 'row-only properties stay out of element presentation');
FormPresentationGraphBuilder::assertValid($graph);
foreach (array('unknown_key', 'wrong_property', 'unknown_member', 'unknown_row', 'unknown_precedence', 'missing_layout') as $mutation) {
    $invalid = $graph;
    if ('unknown_key' === $mutation) $invalid['control_rows'][0]['extra'] = true;
    if ('wrong_property' === $mutation) $invalid['control_rows'][0]['members'][0]['styles']['color'] = 'red';
    if ('unknown_member' === $mutation) $invalid['variants'][count($invalid['variants']) - 1]['member_index'] = 127;
    if ('unknown_row' === $mutation) $invalid['variants'][count($invalid['variants']) - 1]['index'] = 127;
    if ('unknown_precedence' === $mutation) $invalid['variants'][count($invalid['variants']) - 1]['precedence']['flex-grow']['extra'] = true;
    if ('missing_layout' === $mutation) {
        $invalid['control_rows'][0]['styles'] = array();
        $invalid['control_rows'][0]['provenance'] = array();
    }
    try {
        FormPresentationGraphBuilder::assertValid($invalid);
        $assert(false, 'strict validation rejects ' . $mutation);
    } catch (InvalidArgumentException) {
        // Expected: row facts have closed keys, property vocabularies and variant ownership.
    }
}
echo "form presentation control row passed\n";

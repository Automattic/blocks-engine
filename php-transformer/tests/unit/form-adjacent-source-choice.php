<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<main><style>@media (min-width: 640px) {.grid {grid-template-columns: repeat(2, minmax(0, 1fr))}}</style><form><div class="grid grid-cols-1 sm:grid-cols-2 gap-4" style="display:grid"><div class="field">'
    . '<label id="contact-label">Preferred Contact</label>'
    . '<div class="select-shell"><button type="button" role="combobox" aria-haspopup="listbox" aria-labelledby="contact-label" aria-required="true" data-dla-listbox-trigger="contact">Email</button>'
    . '<div hidden data-dla-listbox-panel="contact"><div class="fixed bottom-0 z-50" style="display:grid"><div role="listbox"><button type="button" role="option">Email</button><button type="button" role="option">Phone</button></div></div></div>'
    . '<select aria-hidden="true" tabindex="-1" required><option value="email" selected>Email</option><option value="phone">Phone</option></select></div></div>'
    . '<div class="field"><label id="service-label">Service of Interest</label><div class="select-shell">'
    . '<button type="button" role="combobox" aria-haspopup="listbox" aria-labelledby="service-label" data-dla-listbox-trigger="service">Choose a service</button>'
    . '<div hidden data-dla-listbox-panel="service"><div class="fixed bottom-0 z-50" style="display:grid"><div role="listbox"><button type="button" role="option">Service 0</button></div></div></div>'
    . '<select hidden>';
for ( $i = 0; $i < 11; ++$i ) {
    $html .= '<option value="service-' . $i . '">Service ' . $i . '</option>';
}
$html .= '</select></div></div></div><button type="submit">Send</button></form></main>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$declarations = array_values(array_filter(
    $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
    static fn (array $item): bool => 'forms' === ($item['type'] ?? null)
));
$entity = $declarations[0]['payload']['entities'][0] ?? array();
$controls = $entity['controls'] ?? array();
$triggers = array_values(array_filter($controls, static fn (array $control): bool => 'combobox' === ($control['role'] ?? '')));
$failures = array();
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ( ! $ok ) $failures[] = $message;
};
$check(2 === count($triggers), 'two visible choice triggers reach generic/forms/v1');
$check('Preferred Contact' === ($triggers[0]['label'] ?? null), 'trigger retains its visible label');
$check(true === ($triggers[0]['required'] ?? null), 'required state reaches trigger');
$check('email' === ($triggers[0]['options'][0]['value'] ?? null) && 'Email' === ($triggers[0]['options'][0]['label'] ?? null)
    && true === ($triggers[0]['options'][0]['selected'] ?? null) && 'phone' === ($triggers[0]['options'][1]['value'] ?? null), 'distinct native values, labels and selection survive');
$check(11 === count($triggers[1]['options'] ?? array()) && 'service-10' === ($triggers[1]['options'][10]['value'] ?? null), 'all eleven literal service choices survive');
$check(isset($triggers[0]['choice_source_selector'], $triggers[1]['choice_source_selector'])
    && $triggers[0]['choice_source_selector'] !== $triggers[1]['choice_source_selector'], 'each trigger points past its linked captured panel to its native select');
$check(2 === count(array_filter($controls, static fn (array $control): bool => 'select' === ($control['tag'] ?? null))), 'native controls stay in the manifest');
$check(5 === count($controls), 'portal option buttons are not provider controls');
$nodes = $entity['control_topology']['nodes'] ?? array();
$siblings = array_values(array_filter($nodes, static fn (array $node): bool => 'control' === ($node['kind'] ?? '') && in_array($node['control'] ?? -1, array(0, 1), true)));
$check(2 === count($siblings) && ($siblings[0]['parent'] ?? null) === ($siblings[1]['parent'] ?? null)
    && ($siblings[0]['order'] ?? -1) < ($siblings[1]['order'] ?? -1)
    && isset($entity['layout_graph']), 'adjacent trigger/select wrapper topology and layout graph reach entity');
$wrappers = array_values(array_filter($nodes, static fn (array $node): bool => 'wrapper' === ($node['kind'] ?? '')));
$grid = array_values(array_filter($wrappers, static fn (array $node): bool => str_contains($node['class'] ?? '', 'grid-cols-1')));
$check(1 === count($grid), 'authored responsive grid stays in control topology');
$check(0 === count(array_filter($wrappers, static fn (array $node): bool => str_contains($node['class'] ?? '', 'fixed'))), 'hidden portal wrappers leave provider topology');
$graph = $entity['layout_graph'] ?? array();
$check(0 === count(array_filter($graph['nodes'] ?? array(), static fn (array $node): bool => in_array('fixed', $node['source']['classes'] ?? array(), true))), 'hidden portal layout leaves provider graph');
$check(0 < count(array_filter($graph['nodes'] ?? array(), static fn (array $node): bool => in_array('grid-cols-1', $node['source']['classes'] ?? array(), true))), 'authored grid stays in layout graph');
$check(0 < count($graph['variants'] ?? array()), 'responsive grid variant stays available');
$check(! isset($entity['form']['action']), 'no submission handler inferred');

// A nearby but unrelated select must not be borrowed across a field wrapper.
$unrelated = new DOMDocument();
$unrelated->loadHTML('<form><div><button type="button" role="combobox">Pick</button></div><div><select hidden><option value="wrong">Wrong</option></select></div></form>');
$builder = new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder(
    static fn (DOMElement $element): string => $element->getNodePath()
);
$button = $unrelated->getElementsByTagName('button')->item(0);
$check($button instanceof DOMElement && ! isset($builder->control($button)['choice_source_selector']), 'unrelated select is not associated');

$direct = new DOMDocument();
$direct->loadHTML('<form><button type="button" role="combobox">Pick</button><select hidden><option value="direct">Direct</option></select></form>');
$directButton = $direct->getElementsByTagName('button')->item(0);
$check($directButton instanceof DOMElement && 'direct' === ($builder->control($directButton)['options'][0]['value'] ?? null), 'direct sibling remains associated');

foreach (array(
    'unlinked panel' => '<div hidden data-dla-listbox-panel="other"></div>',
    'ordinary intervening node' => '<div>Other field content</div>',
    'two linked panels' => '<div hidden data-dla-listbox-panel="choice"></div><div hidden data-dla-listbox-panel="choice"></div>',
) as $case => $between) {
    $document = new DOMDocument();
    $document->loadHTML('<form><button type="button" role="combobox" data-dla-listbox-trigger="choice">Pick</button>'
        . $between . '<select aria-hidden="true" tabindex="-1"><option value="wrong">Wrong</option></select></form>');
    $trigger = $document->getElementsByTagName('button')->item(0);
    $check($trigger instanceof DOMElement && ! isset($builder->control($trigger)['choice_source_selector']), $case . ' does not bridge to a select');
}

foreach (array(
    'unlinked portal' => 'other',
    'visible authored panel' => 'choice',
    'no native value carrier' => 'choice',
) as $case => $panelKey) {
    $document = new DOMDocument();
    $document->loadHTML('<form><button type="button" role="combobox" data-dla-listbox-trigger="choice">Pick</button>'
        . '<div ' . ('visible authored panel' === $case ? '' : 'hidden ') . 'data-dla-listbox-panel="' . $panelKey . '"><button type="button">Keep</button></div>'
        . ('no native value carrier' === $case ? '' : '<select hidden><option value="source">Source</option></select>') . '</form>');
    $portalButton = $document->getElementsByTagName('button')->item(1);
    $check($portalButton instanceof DOMElement
        && ! Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier::isNonAuthoredControl($portalButton), $case . ' control stays authored');
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "form adjacent source choice passed\n";

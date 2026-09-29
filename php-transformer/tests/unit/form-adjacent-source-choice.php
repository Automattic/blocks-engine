<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<main><form><div class="grid"><div class="field">'
    . '<label id="contact-label">Preferred Contact</label>'
    . '<div class="select-shell"><button type="button" role="combobox" aria-haspopup="listbox" aria-labelledby="contact-label" aria-required="true">Email</button>'
    . '<select aria-hidden="true" tabindex="-1" required><option value="email" selected>Email</option><option value="phone">Phone</option></select></div></div>'
    . '<div class="field"><label id="service-label">Service of Interest</label><div class="select-shell">'
    . '<button type="button" role="combobox" aria-haspopup="listbox" aria-labelledby="service-label">Choose a service</button>'
    . '<select hidden><option value="">Choose a service</option>';
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
$check(12 === count($triggers[1]['options'] ?? array()) && 'service-10' === ($triggers[1]['options'][11]['value'] ?? null), 'all eleven literal service choices survive');
$check(isset($triggers[0]['choice_source_selector'], $triggers[1]['choice_source_selector'])
    && $triggers[0]['choice_source_selector'] !== $triggers[1]['choice_source_selector'], 'each trigger points to its own adjacent native select');
$check(2 === count(array_filter($controls, static fn (array $control): bool => 'select' === ($control['tag'] ?? null))), 'native controls stay in the manifest');
$nodes = $entity['control_topology']['nodes'] ?? array();
$siblings = array_values(array_filter($nodes, static fn (array $node): bool => 'control' === ($node['kind'] ?? '') && in_array($node['control'] ?? -1, array(0, 1), true)));
$check(2 === count($siblings) && ($siblings[0]['parent'] ?? null) === ($siblings[1]['parent'] ?? null)
    && ($siblings[0]['order'] ?? -1) < ($siblings[1]['order'] ?? -1)
    && isset($entity['layout_graph']), 'adjacent trigger/select wrapper topology and layout graph reach entity');
$check(! isset($entity['form']['action']), 'no submission handler inferred');

// A nearby but unrelated select must not be borrowed across a field wrapper.
$unrelated = new DOMDocument();
$unrelated->loadHTML('<form><div><button type="button" role="combobox">Pick</button></div><div><select hidden><option value="wrong">Wrong</option></select></div></form>');
$builder = new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder(
    static fn (DOMElement $element): string => $element->getNodePath()
);
$button = $unrelated->getElementsByTagName('button')->item(0);
$check($button instanceof DOMElement && ! isset($builder->control($button)['choice_source_selector']), 'unrelated select is not associated');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "form adjacent source choice passed\n";

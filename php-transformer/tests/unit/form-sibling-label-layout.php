<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$compile = static function (string $row): array {
    $html = '<style>.consent{display:flex;gap:8px}.consent input{margin:0;width:13px;height:13px}</style>'
        . '<form>' . $row . '<input type="submit" value="Send"></form>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $fallback = current(array_filter($result['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)));
    if (!is_array($fallback)) throw new RuntimeException('Compiled fixture lost its form metadata.');
    FormLayoutGraphBuilder::assertValid($fallback['layout_graph']);
    FormControlTopologyBuilder::assertValid($fallback['control_topology'], count($fallback['controls']));
    FormControlTopologyBuilder::assertSiblingRelations($fallback['sibling_relations'], count($fallback['controls']));
    return $fallback;
};
$input = '<input type="checkbox" name="news" id="news">';
$label = '<label for="news">Yes, I want news</label>';
foreach (array(false, true) as $labelFirst) {
    $form = $compile('<div class="consent">' . ($labelFirst ? $label . $input : $input . $label) . '</div>');
    $pair = $form['sibling_relations']['pairs'][0] ?? array();
    $nodes = array_column($form['layout_graph']['nodes'], null, 'id');
    $parent = $nodes[$pair['parent'] ?? ''] ?? array();
    if (0 !== ($pair['control'] ?? null) || ($labelFirst ? array('label', 'control') : array('control', 'label')) !== ($pair['order'] ?? null)
        || !in_array('consent', $parent['source']['classes'] ?? array(), true) || 'wrapper-0' !== ($parent['id'] ?? null)
        || 'flex' !== ($parent['layout']['display'] ?? null) || '8px' !== ($parent['layout']['gap'] ?? null)
        || $form['sibling_relations']['truncated']) {
        throw new RuntimeException('Associated sibling relation lost parent, order, identity or layout: ' . json_encode($pair));
    }
}
foreach (array('<div class="consent"><label>' . $input . 'Yes, I want news</label></div>', '<div class="consent">' . $input . '</div><div>' . $label . '</div>', '<div class="consent">' . $input . '<label for="other">Other</label></div>') as $row) {
    if (array() !== $compile($row)['sibling_relations']['pairs']) throw new RuntimeException('Non-sibling or unrelated label acquired a relation.');
}
foreach (array(array('control' => 0, 'parent' => 'unsafe"', 'order' => array('control', 'label')), array('control' => 0, 'parent' => 'wrapper-0', 'order' => array('label'))) as $bad) {
    $relations = $form['sibling_relations'];
    $relations['pairs'] = array($bad);
    try {
        FormControlTopologyBuilder::assertSiblingRelations($relations, 2);
        throw new RuntimeException('Invalid relation accepted.');
    } catch (InvalidArgumentException) {
    }
}
// Relations only name retained parents, and share the graph's bounded signal.
$document = new DOMDocument();
$rows = '';
for ($i = 0; $i < 130; ++$i) {
    $rows .= '<div style="display:flex"><input id="c' . $i . '" name="c' . $i . '"><label for="c' . $i . '">Choice</label></div>';
}
$document->loadHTML('<form>' . $rows . '</form>');
$element = $document->getElementsByTagName('form')->item(0);
$graph = (new FormLayoutGraphBuilder())->build($element, array());
$relations = (new FormControlTopologyBuilder())->directLabelControlPairs($element, $graph);
FormControlTopologyBuilder::assertSiblingRelations($relations, 130);
$ids = array_column($graph['nodes'], 'id');
if (!$relations['truncated'] || count($relations['pairs']) > $relations['max_pairs'] || count($graph['nodes']) > $graph['limits']['nodes']) {
    throw new RuntimeException('Sibling relations exceeded their bounded contract.');
}
foreach ($relations['pairs'] as $pair) {
    if (!in_array($pair['parent'], $ids, true)) throw new RuntimeException('Relation names an omitted layout parent.');
}
echo "Form sibling label layout passed.\n";

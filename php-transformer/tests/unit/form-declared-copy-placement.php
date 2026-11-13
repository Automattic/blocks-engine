<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$cases = array(
    'group' => '<input name="n"><label>About you</label><div><input type="radio" name="w" id="a" value="A"><label for="a">Author</label><input type="radio" name="w" id="b" value="B"><label for="b">Other</label></div>',
    'field' => '<input name="n"><div><label>Upload manuscript</label><input type="file" name="f"><button type="button">Upload File</button></div>',
    'context' => '<input name="n"><p>We will email you a quote.</p>',
    'ambiguous' => '<input name="n"><label>Which field?</label><div><input name="a"><input name="b"></div>',
);
$failures = array();
foreach ( $cases as $case => $html ) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><form>' . $html . '<button type="submit">Send</button></form></main>')))->toArray();
    $entity = null;
    $diagnostic = false;
    $walk = static function (mixed $node) use (&$walk, &$entity, &$diagnostic): void {
        if ( ! is_array($node) ) return;
        if ( 'form_field_context_unrepresented' === ($node['code'] ?? '') ) $diagnostic = true;
        if ( 'entity_collection' === ($node['kind'] ?? '') && 'forms' === ($node['type'] ?? '') ) $entity = $node['payload']['entities'][0] ?? null;
        foreach ( $node as $child ) $walk($child);
    };
    $walk($result);
    if ( ! is_array($entity) || $diagnostic !== ('ambiguous' === $case) ) {
        $failures[] = $case . ': declaration/diagnostic';
        continue;
    }
    FormControlTopologyBuilder::assertValid($entity['control_topology'], count($entity['controls']));
    FormLayoutGraphBuilder::assertValid($entity['layout_graph']);
    FormPresentationGraphBuilder::assertValid($entity['presentation_graph']);
    $placed = match ($case) {
        'group' => in_array('About you', array_column($entity['control_topology']['nodes'], 'group_label'), true),
        'field' => 'Upload manuscript' === ($entity['controls'][1]['label'] ?? null),
        'context' => 'We will email you a quote.' === ($entity['form']['context_after'][0]['text'] ?? null)
            && $entity['controls'][0]['selector'] === ($entity['form']['context_after'][0]['after_control_selector'] ?? null),
        default => true,
    };
    if ( ! $placed ) $failures[] = $case . ': placement';
}
if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Declared form copy placement: ok\n";

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
    'checkbox group' => '<input name="n"><label>Cover (select all)</label><div><input type="checkbox" name="c" id="p" value="P"><label for="p">Paperback</label><input type="checkbox" name="c" id="h" value="H"><label for="h">Hardcover</label></div>',
    'label for hidden' => '<input name="n"><div><label for="u">Upload manuscript</label><input type="file" id="f" name="f"><input type="hidden" id="u" name="u"><button type="button">Upload File</button></div>',
    'label for authored trigger' => '<input name="n"><div><label for="u">Upload manuscript</label><input type="file" id="f" name="f"><button type="button" id="u">Upload File</button></div>',
    'mixed choices' => '<input name="n"><label>Which field?</label><div><input type="radio" name="c"><input type="checkbox" name="c"></div>',
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
    if ( ! is_array($entity) || $diagnostic !== in_array($case, array('ambiguous', 'mixed choices'), true) ) {
        $failures[] = $case . ': declaration/diagnostic';
        continue;
    }
    FormControlTopologyBuilder::assertValid($entity['control_topology'], count($entity['controls']));
    FormLayoutGraphBuilder::assertValid($entity['layout_graph']);
    FormPresentationGraphBuilder::assertValid($entity['presentation_graph']);
    $placed = match ($case) {
        'group', 'checkbox group' => 1 === count(array_filter($entity['control_topology']['nodes'], static fn (array $node): bool =>
            ('group' === $case ? 'About you' : 'Cover (select all)') === ($node['group_label'] ?? null)
            && ('group' === $case ? 'radiogroup' : 'group') === ($node['group_role'] ?? null))),
        'field', 'label for hidden' => 'Upload manuscript' === ($entity['controls'][1]['label'] ?? null),
        'label for authored trigger' => 'Upload manuscript' !== ($entity['controls'][1]['label'] ?? null)
            && 'Upload manuscript' === ($entity['controls'][2]['label'] ?? null),
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

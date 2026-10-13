<?php
declare(strict_types=1);

/**
 * The compiler never declares a form whose control topology or sibling
 * relations violate the producer contract consumers enforce on the entity.
 *
 * A topology that misses a flat control (as the builder did for a native radio
 * carried inside `button[role=radio]` before the nested-control fix) made the
 * importer reject the whole forms declaration. The compiler now checks the same
 * contract before it declares the form: an invalid topology declines that one
 * form with a named finding, and invalid sibling relations are dropped with a
 * finding while the form stays declared.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;

$failures = array();
$assert = static function (bool $condition, string $label, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ' ' . $detail : '');
    }
};

$html = '<main><form method="post" action="/rsvp" class="rsvp">'
    . '<label><span>Name</span><input name="guest"></label>'
    . '<label><span>Email</span><input type="email" name="email"></label>'
    . '<label><input type="checkbox" name="attending" value="yes"> Attending</label>'
    . '<button type="submit">Send</button></form></main>';
$compiler = new ArtifactCompiler();
$result = $compiler->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$fallback = current(array_filter($result['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)));
$assert(is_array($fallback) && 4 === count($fallback['controls'] ?? array()), 'fixture-form-has-four-controls');
$fallback = is_array($fallback) ? $fallback : array();

$declare = static function (array $fallback) use ($compiler): array {
    $diagnostics = array();
    $records = array();
    $method = new ReflectionMethod(ArtifactCompiler::class, 'runtimeDeclarationsFromFallbacks');
    $declarations = $method->invokeArgs($compiler, array(array(), array($fallback), 'index.html', array(), &$diagnostics, &$records));
    $forms = array();
    foreach ( $declarations as $declaration ) {
        if ( 'forms' === ($declaration['type'] ?? null) ) {
            $forms = array_merge($forms, $declaration['payload']['entities'] ?? array());
        }
    }
    return array('forms' => $forms, 'diagnostics' => $diagnostics);
};
$validTopology = static function (array $form): bool {
    if ( ! is_array($form['control_topology'] ?? null) ) {
        return true;
    }
    try {
        FormControlTopologyBuilder::assertValid($form['control_topology'], count($form['controls'] ?? array()));
        return true;
    } catch ( InvalidArgumentException ) {
        return false;
    }
};

// Control: the compiled fallback declares one form with a valid topology.
$baseline = $declare($fallback);
$assert(1 === count($baseline['forms']) && $validTopology($baseline['forms'][0]) && isset($baseline['forms'][0]['control_topology'], $baseline['forms'][0]['sibling_relations']), 'valid-topology-form-is-declared');

// A topology that misses one flat control.
$missing = $fallback;
$missing['control_topology']['nodes'] = array_values(array_filter(
    $fallback['control_topology']['nodes'] ?? array(),
    static fn (array $node): bool => 'control' !== ($node['kind'] ?? null) || 2 !== ($node['control'] ?? null)
));
$declined = $declare($missing);
$assert(array() === array_filter($declined['forms'], static fn (array $form): bool => ! $validTopology($form)), 'no-declared-form-carries-an-invalid-topology');
$assert(array() === $declined['forms'], 'form-with-invalid-topology-is-not-declared');
$finding = current(array_filter($declined['diagnostics'], static fn (array $row): bool => 'runtime_form_declaration_declined' === ($row['code'] ?? null) && 'control_topology_invalid' === ($row['failed_check'] ?? null)));
$assert(is_array($finding), 'invalid-topology-is-named-as-a-declined-form', json_encode($declined['diagnostics']) ?: '');
$assert(is_array($finding) && str_contains((string) ($finding['message'] ?? ''), 'must cover every flat control exactly once') && 'index.html' === ($finding['source_path'] ?? null) && 'warning' === ($finding['severity'] ?? null), 'declined-finding-carries-the-contract-reason');

// Sibling relations that reference a control the form does not have.
$badRelations = $fallback;
$badRelations['sibling_relations']['pairs'] = array(array('control' => 9));
$relations = $declare($badRelations);
$assert(1 === count($relations['forms']) && ! isset($relations['forms'][0]['sibling_relations']) && $validTopology($relations['forms'][0]), 'invalid-sibling-relations-are-dropped-and-the-form-is-kept');
$assert(is_array(current(array_filter($relations['diagnostics'], static fn (array $row): bool => 'runtime_form_graph_rejected' === ($row['code'] ?? null) && 'sibling_relations' === ($row['graph'] ?? null)))), 'invalid-sibling-relations-are-named');

// A custom choice widget carries its native radio inside `button[role=radio]`.
// The declared entity must cover every flat control, so the form is declared
// (not declined) with a topology that satisfies the contract.
$rsvp = '<main><form method="post" action="/rsvp"><label><span>Name</span><input name="guest"></label>'
    . '<div role="radiogroup"><button type="button" role="radio" aria-checked="false"><input type="radio" name="attending" value="Yes" tabindex="-1" aria-hidden="true"><span>Yes</span></button>'
    . '<button type="button" role="radio" aria-checked="false"><input type="radio" name="attending" value="No" tabindex="-1" aria-hidden="true"><span>No</span></button></div>'
    . '<button type="submit">Send</button></form></main>';
$rsvpResult = $compiler->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $rsvp)))->toArray();
$rsvpFallback = current(array_filter($rsvpResult['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)));
$rsvpDeclared = is_array($rsvpFallback) ? $declare($rsvpFallback) : array('forms' => array(), 'diagnostics' => array());
$assert(1 === count($rsvpDeclared['forms']) && $validTopology($rsvpDeclared['forms'][0]) && 6 === count($rsvpDeclared['forms'][0]['controls'] ?? array()), 'native-control-inside-a-role-control-is-declared-with-a-covering-topology', json_encode($rsvpDeclared['diagnostics']) ?: '');
$assert(array() === array_filter($rsvpDeclared['diagnostics'], static fn (array $row): bool => 'runtime_form_declaration_declined' === ($row['code'] ?? null)), 'covering-topology-is-not-declined');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Form declaration control topology contract tests passed\n";

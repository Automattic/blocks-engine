<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static fn (string $controls): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Questions</title></head><body><main><form method="post" action="/answers">' . $controls . '</form></main></body></html>'),
))->toArray();
$choices = '<label><input type="radio" name="answer" value="yes" checked>Yes</label><label><input type="radio" name="answer" value="no">No</label>';
foreach (array(
    $choices . '<button>Send</button><button type="button">Results</button><input type="reset" value="Reset">',
    str_replace('radio', 'checkbox', $choices) . '<input type="submit" value="Send">',
    $choices . '<input type="image" src="send.png" alt="Send">',
) as $controls) {
    $result = $compile($controls);
    $markup = $result['serialized_blocks'];
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    foreach ((new DOMXPath($dom))->query('//input | //button | //select') as $control) {
        if (!$control->hasAttribute('disabled')) throw new RuntimeException('Every unresolved choice-form control must be disabled: ' . $dom->saveHTML($control) . ' in ' . $controls);
    }
    if (!str_contains($markup, 'This form is not accepting responses yet.')) throw new RuntimeException('Inactive form needs visible explanation');
    if (str_contains($markup, 'wp:html')) throw new RuntimeException('Inactive forms must remain editable');
    $forms = array_values(array_filter($result['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'form' === ($island['kind'] ?? '')));
    if (1 !== count($forms) || 'choice_submission' !== $forms[0]['form_intent']) throw new RuntimeException('Inactive form must retain submission intent');
    if (!str_contains($forms[0]['source_snippet'], $controls) || str_contains($forms[0]['source_snippet'], 'disabled')) throw new RuntimeException('Display-only disabling must not change source evidence');
    if (!str_contains($markup, 'checked')) throw new RuntimeException('Selected source choices must stay selected');
}
$preference = $compile($choices);
if (str_contains($preference['serialized_blocks'], ' disabled') || str_contains($preference['serialized_blocks'], 'This form is not accepting responses yet.')) throw new RuntimeException('Preference controls without submission must stay unchanged');
echo "Inactive choice submission forms passed.\n";

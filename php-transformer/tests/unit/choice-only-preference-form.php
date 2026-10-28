<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ( ! $condition ) $failures[] = 'FAIL: ' . $label;
};
$select = '<select name="currency"><option value="A">A</option><option value="B" selected>B</option></select>';
$contact = '<form method="post" action="/contact"><input type="text" name="name"><input type="email" name="email"><textarea name="message"></textarea><button>Send</button></form>';
$cases = array(
    'issue fixture' => array('post', $select, 'preference', false),
    'radios' => array('post', '<label><input type="radio" name="choice" value="A">A</label><label><input type="radio" name="choice" value="B" checked>B</label>', 'preference', false),
    'checkbox' => array('post', '<label><input type="checkbox" name="enabled" checked>Enabled</label>', 'preference', false),
    'mixed choices and submit' => array('post', $select . '<input type="checkbox" name="enabled"><button>Apply</button>', 'preference', false),
    'other method' => array('dialog', $select, 'preference', false),
    'default GET' => array('', $select, 'filter_sort', false),
    'GET' => array('get', $select, 'filter_sort', false),
    'text unchanged' => array('post', $select . '<input type="text" name="name">', 'unknown', true),
    'implicit text unchanged' => array('post', $select . '<input name="name">', 'unknown', true),
    'email unchanged' => array('post', $select . '<input type="email" name="email">', 'unknown', true),
    'textarea unchanged' => array('post', $select . '<textarea name="message"></textarea>', 'unknown', true),
    'number unchanged' => array('post', $select . '<input type="number" name="amount">', 'unknown', true),
    'file unchanged' => array('post', $select . '<input type="file" name="upload">', 'unknown', true),
);
foreach ( $cases as $label => [$method, $controls, $intent, $fallback] ) {
    $form = '<form method="' . $method . '" action="/settings/update"><input type="hidden" name="return_to" value="/">' . $controls . '</form>';
    $dom = new DOMDocument();
    $dom->loadHTML($form);
    $actualIntent = FormRuntimeRequirementAnalyzer::formIntent($dom->getElementsByTagName('form')->item(0));
    $assert($intent === $actualIntent, $label . ' intent: expected ' . $intent . ', got ' . $actualIntent);
    $html = '<!doctype html><html><head><title>Preferences</title></head><body><main>' . $form . $contact . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '')));
    $assert(($fallback ? 2 : 1) === count($findings), $label . ' fallback count');
    $contactFinding = $findings[count($findings) - 1] ?? array();
    $assert('contact' === ($contactFinding['form_intent'] ?? '') && 'form_provider' === ($contactFinding['materialization_target']['provider_role'] ?? ''), $label . ' contact unchanged');
    if ( 'preference' === $intent ) {
        $islands = array_values(array_filter($result['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => '/settings/update' === ($island['form']['action'] ?? '')));
        $assert(1 === count($islands), $label . ' one bounded island');
        $island = $islands[0] ?? array();
        $assert('form_requires_runtime' === ($island['preservation_reason'] ?? '') && !empty($island['readable_blocks']), $label . ' runtime retains readable blocks');
        $assert(str_contains($island['source_snippet'] ?? '', 'name="return_to"') && str_contains($island['source_snippet'] ?? '', $controls), $label . ' hidden and selected source state retained');
        $assert(str_contains($result['serialized_blocks'] ?? '', 'wp:group'), $label . ' readable group emitted');
    }
}
if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Choice-only preference form: passed\n";

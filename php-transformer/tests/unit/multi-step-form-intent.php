<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #5659: source step visibility must not decide what the form collects.
$wizard = '<form class="quote-form"><section data-step="1">'
    . '<label>Trip type <select name="tripType" required><option>Umrah</option><option>Hotel</option></select></label>'
    . '<button type="button">Next</button></section><section data-step="2" hidden>'
    . '<label>Name <input name="name" required></label>'
    . '<label>Email <input name="email" type="email" required></label>'
    . '<label>Phone <input name="phone" type="tel"></label>'
    . '<label>Requirements <textarea name="requirements"></textarea></label>'
    . '<button type="submit">Send enquiry</button></section></form>';
$cases = array(
    'issue wizard' => array($wizard, 'contact'),
    'disabled alternate does not override contact' => array(str_replace('</form>', '<input type="password" name="password" disabled></form>', $wizard), 'contact'),
    // Force a public form finding so its intent is observable. The event does
    // not add a field or change the GET filter's select-only data semantics.
    'GET select only' => array('<form method="get" onchange="apply()"><select name="sort"><option>Price</option></select></form>', 'filter_sort'),
    'GET disabled alternate fields' => array('<form method="get" onchange="apply()"><select name="sort"><option>Price</option></select><section hidden><input type="email" name="email" disabled><textarea name="message" disabled></textarea><button disabled>Donate</button></section></form>', 'filter_sort'),
);
$failures = array();
foreach ( $cases as $label => [$form, $intent] ) {
    $html = '<!doctype html><html><body><main>' . $form . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null)));
    $finding = $findings[0] ?? array();
    if ( $intent !== ($finding['form_intent'] ?? null) ) $failures[] = $label . ': intent must be ' . $intent;
    if ( 'contact' !== $intent ) continue;
    if ( 'form_provider' !== ($finding['materialization_target']['provider_role'] ?? null)
        || 'materialize_form_provider' !== ($finding['suggested_repair_class'] ?? null) ) {
        $failures[] = $label . ': contact must route to the form provider';
    }
    $entities = array();
    foreach ( $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration ) {
        if ( 'forms' === ($declaration['type'] ?? null) ) $entities = $declaration['payload']['entities'] ?? array();
    }
    $names = array_column($entities[0]['controls'] ?? array(), 'name');
    foreach ( array('tripType', 'name', 'email', 'phone', 'requirements') as $name ) {
        if ( ! in_array($name, $names, true) ) $failures[] = $label . ': provider contract must retain ' . $name;
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Multi-step form intent: passed\n";

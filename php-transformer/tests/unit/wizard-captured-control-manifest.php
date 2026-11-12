<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #5706: browser capture records the later wizard step as not rendered.
$html = <<<'HTML'
<form class="quote-form">
  <section data-step="1"><label>Trip type <select name="tripType" required><option>Umrah</option></select></label><button type="button">Next</button></section>
  <section data-step="2" hidden>
    <label>Name <input name="name" required data-blocks-engine-capture-native-control-state='{"version":1,"kind":"input","rendered":false,"type":"text","value":""}'></label>
    <label>Email <input name="email" type="email" required data-blocks-engine-capture-native-control-state='{"version":1,"kind":"input","rendered":false,"type":"email","value":""}'></label>
    <label>Requirements <textarea name="requirements"></textarea></label>
    <button type="submit">Send enquiry</button>
  </section>
</form>
HTML;
// The issue abbreviates capture state; also exercise the producer's complete,
// validated payload so rendered:false participates in authored classification.
$capturedHtml = str_replace('"value":""}', '"value":"","defaultValue":"","checked":false,"defaultChecked":false,"indeterminate":false}', $html);
$failures = array();
$ordinaryHtml = str_replace(array(' data-step="1"', ' data-step="2"'), '', $capturedHtml);
foreach ( array($html, $capturedHtml, $ordinaryHtml) as $index => $source ) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<!doctype html><html><body><main>' . $source . '</main></body></html>'),
    ))->toArray();
    $diagnostics = array_values(array_filter($result['diagnostics'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['code'] ?? null)));
    $finding = $diagnostics[0] ?? array();
    if ( 'contact' !== ($finding['form_intent'] ?? null) ) $failures[] = 'Intent must be contact';
    $names = array_column($finding['controls'] ?? array(), 'name');
    foreach ( array('tripType', 'name', 'email', 'requirements') as $name ) {
        $expected = 2 !== $index || ! in_array($name, array('name', 'email'), true);
        if ( $expected !== in_array($name, $names, true) ) $failures[] = 'Unexpected diagnostic control membership: ' . $name;
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Wizard captured control manifest: passed\n";

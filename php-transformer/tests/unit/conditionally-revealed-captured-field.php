<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6521: a captioned field under a `hidden` wrapper, revealed by picking a
// choice, is captured rendered:false. It is authored content, not a decoy.
$state = htmlspecialchars('{"version":1,"kind":"input","rendered":false,"type":"text","value":"","defaultValue":"","checked":false,"defaultChecked":false,"indeterminate":false}', ENT_QUOTES);
$page = '<form id="give-form"><h3>Support us</h3>'
    . '<fieldset><legend>Choose an amount</legend>'
    . '<label><input type="radio" name="amount" value="10"> 10</label>'
    . '<label><input type="radio" name="amount" value="25" checked> 25</label>'
    . '<label><input type="radio" name="amount" value="custom"> Other</label></fieldset>'
    . '<div id="custom-wrap" hidden><label for="custom">Your amount</label><input id="custom" name="customAmount" type="text" inputmode="decimal" data-blocks-engine-capture-native-control-state="' . $state . '"></div>'
    . '%s<button type="submit">Continue</button></form>'
    . '<script>document.querySelectorAll("[name=amount]").forEach(r=>r.addEventListener("change",()=>{document.getElementById("custom-wrap").hidden=r.value!=="custom"||!r.checked}))</script>';
// An uncaptioned captured-unrendered input under the same kind of wrapper keeps the decoy reading.
$decoy = '<div hidden><input name="decoy" data-blocks-engine-capture-native-control-state="' . $state . '"></div>';

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<main>' . sprintf($page, $decoy) . '</main>'),
))->toArray();
$findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)));
$controls = array_column($findings[0]['controls'] ?? array(), null, 'name');

$failures = array();
if ( ! isset($controls['customAmount']) ) {
    $failures[] = 'conditionally revealed field must stay in the authored control inventory';
} elseif ( 'Your amount' !== ($controls['customAmount']['label'] ?? null) || 'none' !== ($controls['customAmount']['hidden']['value'] ?? null) ) {
    $failures[] = 'revealed field must keep its caption and report its hidden-at-rest wrapper';
}
if ( isset($controls['decoy']) ) {
    $failures[] = 'uncaptioned unrendered input under a hidden wrapper must remain excluded';
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Conditionally revealed captured field: passed\n";

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
    'mixed choices and submit' => array('post', $select . '<input type="checkbox" name="enabled"><button>Apply</button>', 'unknown', true),
    'choice with explicit submit button' => array('post', $select . '<button type="submit">Send</button>', 'unknown', true),
    'choice with submit input' => array('post', $select . '<input type="submit" value="Send">', 'unknown', true),
    'choice with image input' => array('post', $select . '<input type="image" src="send.png" alt="Send">', 'unknown', true),
    'choice with hidden submit' => array('post', $select . '<button hidden>Send</button>', 'unknown', true),
    'radios with submit and disabled button' => array('post', '<label><input type="radio" name="choice" value="A" checked>A</label><label><input type="radio" name="choice" value="B">B</label><input type="submit" value="Send"><input type="button" value="Result" disabled>', 'choice_submission', false),
    'radios with hidden submit' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><button hidden>Send</button>', 'choice_submission', false),
    'checkbox group with submit' => array('post', '<input type="checkbox" name="topics[]" value="A"><input type="checkbox" name="topics[]" value="B"><button>Send</button>', 'choice_submission', false),
    'radios and select with submit' => array('post', $select . '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><button>Send</button>', 'unknown', true),
    'unrelated checkboxes with submit' => array('post', '<input type="checkbox" name="terms"><input type="checkbox" name="updates"><button>Send</button>', 'unknown', true),
    'case-sensitive radio names' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="CHOICE" value="B"><button>Send</button>', 'unknown', true),
    'mixed radio and checkbox group' => array('post', '<input type="radio" name="choice" value="A"><input type="checkbox" name="choice" value="B"><button>Send</button>', 'unknown', true),
    'radios with donation image' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><input type="image" src="donate.png" alt="Donate">', 'donation', true),
    'radios with hidden donation image' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><input type="image" hidden src="donate.png" alt="Donate">', 'donation', true),
    'radios with payment image' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><input type="image" src="pay.png" alt="PayPal - The safer, easier way to pay online!">', 'unknown', true),
    'radios with neutral image' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><input type="image" src="send.png" alt="Send">', 'choice_submission', false),
    'radios with buy button' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><button>Buy now</button>', 'unknown', true),
    'radios with register button' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><button>Register</button>', 'unknown', true),
    'radios with pay button' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><button>Pay now</button>', 'unknown', true),
    'radios with label handler' => array('post', '<label><input type="radio" name="choice" value="A">A<img src="a.png" onerror="void 0"></label><input type="radio" name="choice" value="B"><button>Send</button>', 'unknown', true),
    'radios with script link' => array('post', '<input type="radio" name="choice" value="A"><label><input type="radio" name="choice" value="B"><a href="javascript:void 0">B</a></label><button>Send</button>', 'unknown', true),
    'radios with mail link' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><a href="mailto:help@example.org">Help</a><button>Send</button>', 'unknown', true),
    'radios with extracted inline script' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><script>document.querySelector("form").addEventListener("submit", function (event) { event.preventDefault(); });</script><button>Send</button>', 'unknown', true),
    'radios with extracted external script' => array('post', '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><script src="vote.js"></script><button>Send</button>', 'unknown', true),
    'radios with embedded frame' => array('post', '<label><input type="radio" name="choice" value="A">A<iframe srcdoc="&lt;p&gt;A&lt;/p&gt;"></iframe></label><input type="radio" name="choice" value="B"><button>Send</button>', 'unknown', true),
    'radios with embedded object' => array('post', '<label><input type="radio" name="choice" value="A">A<object data="a.svg"></object></label><input type="radio" name="choice" value="B"><button>Send</button>', 'unknown', true),
    'radios with hidden product' => array('post', '<input type="hidden" name="product_id" value="9"><input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><button>Send</button>', 'unknown', true),
    'choice with custom radio widget' => array('post', '<button type="button" role="radio"><input type="radio" name="choice" value="A">A</button>', 'unknown', true),
    'choice with type=button' => array('post', $select . '<button type="button">Apply</button>', 'preference', false),
    'choice with reset button' => array('post', $select . '<button type="reset">Reset</button>', 'preference', false),
    'choice with button input' => array('post', $select . '<input type="button" value="Apply">', 'preference', false),
    'choice with reset input' => array('post', $select . '<input type="reset" value="Reset">', 'preference', false),
    'checkbox with submit' => array('post', '<input type="checkbox" name="enabled"><button>Send</button>', 'unknown', true),
    'GET with submit' => array('get', $select . '<button>Apply</button>', 'filter_sort', false),
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
    if ( in_array($intent, array( 'preference', 'choice_submission' ), true) ) {
        $islands = array_values(array_filter($result['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => '/settings/update' === ($island['form']['action'] ?? '')));
        $assert(1 === count($islands), $label . ' one bounded island');
        $island = $islands[0] ?? array();
        $assert('form_requires_runtime' === ($island['preservation_reason'] ?? '') && !empty($island['readable_blocks']), $label . ' runtime retains readable blocks');
        $assert(str_contains($island['source_snippet'] ?? '', 'name="return_to"') && str_contains($island['source_snippet'] ?? '', $controls), $label . ' hidden and selected source state retained');
        $assert(str_contains($result['serialized_blocks'] ?? '', 'wp:group'), $label . ' readable group emitted');
        $alone = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><head><title>Preferences</title></head><body><main>' . $form . '</main></body></html>')))->toArray();
        $assert($intent === ($island['form_intent'] ?? ''), $label . ' island names its form intent');
        $diagnostics = array_values(array_filter($alone['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'preserved_runtime_island' === ($diagnostic['code'] ?? '') && 'form' === ($diagnostic['kind'] ?? '')));
        $assert($intent === ($diagnostics[0]['form_intent'] ?? ''), $label . ' diagnostic names its form intent');
        $assert(! str_contains($alone['serialized_blocks'] ?? '', 'wp:html'), $label . ' keeps every control editable');
    }
}
foreach ( array( 'www.paypal.com' => 'unknown', 'paypal.com.example.org' => 'choice_submission' ) as $host => $intent ) {
    foreach ( array( '<input type="image" src="send.png" alt="Send">', '<input type="submit" value="Send">' ) as $submit ) {
        $dom = new DOMDocument();
        $dom->loadHTML('<form method="post" action="https://' . $host . '/cgi-bin/webscr"><input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B">' . $submit . '</form>');
        $assert($intent === FormRuntimeRequirementAnalyzer::formIntent($dom->getElementsByTagName('form')->item(0)), $host . ' payment destination intent for ' . $submit);
    }
}
$choices = '<input type="radio" name="choice" value="A"><input type="radio" name="choice" value="B"><button>Send</button>';
$destinationChoices = '<label><input type="radio" name="choice" value="A">A</label><label><input type="radio" name="choice" value="B">B</label>';
foreach ( array(
    'PayPal override' => '<form method="post" action="/step">' . $destinationChoices . '<button formaction="https://www.paypal.com/cgi-bin/webscr">Continue</button></form>',
    'PayPal image override' => '<form method="post" action="/step">' . $destinationChoices . '<input type="image" src="send.png" alt="Continue" formaction="https://www.paypal.com/cgi-bin/webscr"></form>',
    'PayPal dotted host' => '<form method="post" action="https://www.paypal.com./cgi-bin/webscr">' . $destinationChoices . '<button>Continue</button></form>',
    'mail submission' => '<form method="post" action="mailto:answers@example.org">' . $destinationChoices . '<button>Send</button></form>',
    'mail override' => '<form method="post" action="/step">' . $destinationChoices . '<button formaction="mailto:answers@example.org">Send</button></form>',
) as $label => $form ) {
    $dom = new DOMDocument();
    $dom->loadHTML($form);
    $assert('unknown' === FormRuntimeRequirementAnalyzer::formIntent($dom->getElementsByTagName('form')->item(0)), $label . ' is not a choice submission');
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><head><title>Question</title></head><body><main>' . $form . '</main></body></html>')))->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '')));
    $assert(1 === count($findings), $label . ' retains form fallback');
    $assert(str_contains($result['serialized_blocks'], 'wp:html'), $label . ' retains source representation');
    $assert(! in_array('choice_submission', array_column($result['source_reports']['runtime_islands'] ?? array(), 'form_intent'), true), $label . ' is not counted as an editable choice submission');
}
foreach ( array( 'form handler' => '<form required onsubmit="void 0">', 'script action' => '<form required action="javascript:void 0">' ) as $label => $open ) {
    $sourceForm = '<!doctype html><html><head><title>Question</title></head><body><main>' . $open . $choices . '</form></main></body></html>';
    $dom = new DOMDocument();
    $dom->loadHTML($open . $choices . '</form>');
    $assert('unknown' === FormRuntimeRequirementAnalyzer::formIntent($dom->getElementsByTagName('form')->item(0)), $label . ' keeps unknown intent');
    $sourceResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $sourceForm)))->toArray();
    $assert(1 === count($sourceResult['fallbacks'] ?? array()), $label . ' retains unsupported fallback');
}
$eventForm = '<!doctype html><html><head><title>Question</title></head><body><main><form><h3>Keep this question</h3><label><input type="radio" name="question" value="A" onclick="void 0">Yes</label><label><input type="radio" name="question" value="B">No</label><button>Send</button></form></main></body></html>';
$eventResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $eventForm)))->toArray();
$assert(str_contains($eventResult['serialized_blocks'], 'Keep this question'), 'event-bearing form retains question');
$assert(2 === substr_count($eventResult['serialized_blocks'], 'type="radio"'), 'event-bearing form retains radios');
$assert(1 === count($eventResult['fallbacks']), 'event-bearing form retains unsupported fallback');
$repeatedForms = '<!doctype html><html><head><title>Questions</title></head><body><main>' . str_repeat('<form class="survey"><input type="radio" name="question" value="A"><input type="radio" name="question" value="B"><button>Send</button></form>', 2) . '</main></body></html>';
$repeatedResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $repeatedForms)))->toArray();
$assert(2 === count($repeatedResult['source_reports']['runtime_islands'] ?? array()), 'identical forms retain distinct runtime records');
$plainForm = '<form class="survey" method="post" action="/vote">' . $choices . '</form>';
$scriptedForm = '<form class="survey" method="post" action="/vote">' . $choices . '<script src="vote.js"></script></form>';
foreach ( array($plainForm . $scriptedForm, $scriptedForm . $plainForm) as $forms ) {
    $mixedResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><head><title>Questions</title></head><body><main>' . $forms . '</main></body></html>')))->toArray();
    $formIslands = array_values(array_filter($mixedResult['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'form' === ($island['kind'] ?? '')));
    $assert(1 === count(array_filter($formIslands, static fn (array $island): bool => 'choice_submission' === ($island['form_intent'] ?? ''))), 'source behavior stays attached to its own form');
    $formFallbacks = array_values(array_filter($mixedResult['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '')));
    $assert(1 === count($formFallbacks), 'only the scripted peer retains a form fallback');
}
if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Choice-only preference form: passed\n";

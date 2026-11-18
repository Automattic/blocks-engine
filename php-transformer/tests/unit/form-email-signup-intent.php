<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ( ! $condition ) $failures[] = 'FAIL: ' . $label;
};
$compile = static function (string $body): array {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Signup status form</title><style>.signup{display:flex;gap:8px}</style></head><body><main><h1>Updates</h1>' . $body
        . '<script>document.querySelector(\'form\').addEventListener(\'submit\',function(e){e.preventDefault();document.querySelector(\'[aria-live]\').textContent=\'Thanks\';});</script></main></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};
$forms = static fn (array $result): array => array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '')));

// Reduced fixture from #6547: an action-less email signup followed by a live status paragraph.
$result = $compile('<section><div><form class="signup"><label for="em">Email address</label><input id="em" name="email" type="email" required><button type="submit">Join the list</button></form><p class="signup-status" aria-live="polite"></p></div></section>');
$finding = $forms($result)[0] ?? array();
$assert('newsletter' === ($finding['form_intent'] ?? ''), 'email-only signup with a sibling status is a newsletter signup');
$assert('form_provider' === ($finding['materialization_target']['provider_role'] ?? ''), 'signup maps to the form provider path');
$assert('client_script_execution' !== ($finding['runtime_requirement'] ?? ''), 'signup does not require the source tool runtime');
$emailControls = array_filter($finding['controls'] ?? array(), static fn (array $control): bool => 'email' === ($control['type'] ?? '') && 'email' === ($control['name'] ?? ''));
$assert(1 === count($emailControls), 'email control is kept in the provider control inventory');

// The lone email entry decides intent whatever the submit label's language.
$finding = $forms($compile('<div><form><input type="email" name="adresse" required><label><input type="checkbox" required> Einverstanden</label><button type="submit">Eintragen</button></form><p role="status"></p></div>'))[0] ?? array();
$assert('newsletter' === ($finding['form_intent'] ?? ''), 'email plus consent checkbox is a signup');

// Other free-entry fields make it a broader lead form, still off the tool path.
$finding = $forms($compile('<div><form><input name="name"><input type="email" name="email"><button type="submit">Send</button></form><p aria-live="polite"></p></div>'))[0] ?? array();
$assert('unknown' === ($finding['form_intent'] ?? ''), 'email beside another free-entry field is not narrowed to a signup');

// A local calculator without an email field remains a client-side tool.
$finding = $forms($compile('<div><form><input type="number" name="cash"><input type="number" name="gold"><button type="submit">Calculate</button></form><p role="status" aria-live="polite"></p></div>'))[0] ?? array();
$assert('client_side_tool' === ($finding['form_intent'] ?? ''), 'calculator with a status stays a client-side tool');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "form email signup intent passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) $failures[] = $message;
};
$compile = static fn (string $body): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Control groups</title></head><body><main>' . $body . '</main></body></html>'),
))->toArray();
$findings = static fn (array $result, string $code): array => array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => $code === ($finding['diagnostic_code'] ?? '')));
$controls = '<button type="button" aria-label="Decrease quantity"></button><input type="number" name="quantity" value="1"><button type="button" aria-label="Increase quantity"></button>';
$stepper = '<qty-stepper>' . $controls . '</qty-stepper>';
$reducedForm = static fn (string $group): string => '<buy-form><form method="post" action="/cart/add"><input type="hidden" name="id" value="101">' . $group . '<button type="submit">Add to cart</button></form></buy-form>';
// A neutral controls wrapper exercises form composition, which can record a
// child finding before the owning form fallback is built.
$form = static fn (string $group): string => '<buy-form><form method="post" action="/cart/add"><input type="hidden" name="id" value="101"><div>' . $group . '<button type="submit">Add to cart</button></div></form></buy-form>';
$reduced = $compile($reducedForm($stepper));
$assert(1 === count($findings($reduced, 'html_form_fallback')), 'exact reduced markup retains the form fallback');
$assert(array() === $findings($reduced, 'html_unsupported_element'), 'exact reduced markup has no duplicate');

$result = $compile($form($stepper));
$forms = $findings($result, 'html_form_fallback');
$assert(1 === count($forms), 'enclosing form still has its fallback');
$assert(array() === $findings($result, 'html_unsupported_element'), 'form-owned stepper is not reported twice');
$assert(1 === count($result['fallbacks'] ?? array()), 'neutral fixture reports only the form fallback');
$owned = array_values(array_filter($forms[0]['controls'] ?? array(), static fn (array $control): bool => str_contains($control['selector'] ?? '', 'qty-stepper')));
$assert(3 === count($owned), 'form fallback still lists all three stepper controls');
$assert(array('button', 'number', 'button') === array_column($owned, 'type'), 'stepper control types survive');
$assert(array('Decrease quantity', 'Increase quantity') === array_column(array_filter($owned, static fn (array $control): bool => 'button' === ($control['type'] ?? '')), 'label'), 'stepper accessible labels survive');
$assert(str_contains($result['serialized_blocks'] ?? '', 'Add to cart'), 'form output retains the submit control');
$assert(str_contains($result['serialized_blocks'] ?? '', '<!-- wp:'), 'form still emits blocks');

$outside = $compile($stepper);
$assert(1 === count($findings($outside, 'html_unsupported_element')), 'standalone stepper remains unsupported');
$assert(array() === $findings($outside, 'html_form_fallback'), 'standalone stepper invents no form');
$mixed = $compile($form($stepper) . $stepper);
$assert(1 === count($findings($mixed, 'html_unsupported_element')), 'a different stepper outside the form is not suppressed');
$assert(1 === count($findings($mixed, 'html_form_fallback')), 'mixed page retains the form fallback');

// Only control-only structure is owned by the form, not unrelated content.
foreach (array('<canvas></canvas>') as $extra) {
    $other = $compile($form('<qty-stepper>' . $controls . $extra . '</qty-stepper>'));
    $assert(1 === count($findings($other, 'html_unsupported_element')), 'non-control content retains its unsupported finding');
}

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $message): string => 'FAIL: ' . $message, $failures)) . "\n");
    exit(1);
}
echo "Form control group de-duplication tests passed\n";

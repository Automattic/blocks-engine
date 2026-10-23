<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ( ! $condition ) $failures[] = 'FAIL: ' . $label;
};

// Neutral page from #3342: neither a product grid nor vendor classes, so each
// form elicits its intent from source semantics alone — a purchase form and a
// filter/sort form with only select controls.
$compile = static function (): array {
    $html = '<!doctype html><html><head><title>Shop</title></head><body><main>'
        . '<form method="post" action="/cart/add"><input type="hidden" name="id" value="4242"><input type="number" name="quantity" value="1" min="1" step="1"><button type="submit" name="add">Add to cart</button></form>'
        . '<form method="get" action="/collections/all"><select name="sort_by" onchange="this.form.submit()"><option value="title">Title</option><option value="price">Price</option></select><select name="filter_availability"><option value="in-stock">In stock</option><option value="all">All</option></select></form>'
        . '</main></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};
$result = $compile();

$expectedIntents = array(
    '/cart/add'        => 'commerce_add_to_cart',
    '/collections/all' => 'filter_sort',
);

$rows = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? '')));
$diagnostics = array_values(array_filter($result['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'html_form_fallback' === ($diagnostic['code'] ?? '')));

$assert(2 === count($rows), 'both forms retain their fallback rows');
$assert(2 === count($diagnostics), 'both forms retain their html_form_fallback diagnostics');

$rowsByIdentity = array_column($rows, null, 'fallback_identity');
$assert(2 === count($rowsByIdentity), 'each form fallback row carries a distinct fallback_identity');

foreach ( $rows as $index => $row ) {
    $identity = (string) ($row['fallback_identity'] ?? '');
    $assert('' !== $identity, 'fallback row ' . $index . ' carries a fallback_identity');
    $action = (string) ($row['form']['action'] ?? '');
    $assert(isset($expectedIntents[$action]), 'fallback row ' . $index . ' is a known fixture form');
    $assert($expectedIntents[$action] === ($row['form_intent'] ?? null), 'fallback row for ' . $action . ' elicits ' . $expectedIntents[$action]);
}

// The regression: every html_form_fallback diagnostic must carry the same
// form_intent as its fallback row, joined on fallback_identity, so consumers
// can route the diagnostic by intent instead of reading intent `unknown`.
foreach ( $diagnostics as $index => $diagnostic ) {
    $identity = (string) ($diagnostic['fallback_identity'] ?? '');
    $assert('' !== $identity && isset($rowsByIdentity[$identity]), 'diagnostic ' . $index . ' joins its fallback row on fallback_identity');
    if ( ! isset($rowsByIdentity[$identity]) ) {
        continue;
    }
    $row = $rowsByIdentity[$identity];
    $action = (string) ($row['form']['action'] ?? '');
    $expected = $expectedIntents[$action] ?? '';
    $intent = $diagnostic['form_intent'] ?? null;
    $assert($expected === $intent, 'diagnostic for ' . $action . ' carries form_intent ' . $expected . ' like its fallback row');
}

// The contract type-checks form_intent as a well-known string field, so a
// producer emitting a non-string intent fails validation instead of drifting.
$rejected = false;
try {
    ConversionFindingContract::assertFinding(array('code' => 'html_form_fallback', 'form_intent' => array('commerce_add_to_cart')));
} catch ( InvalidArgumentException ) {
    $rejected = true;
}
$assert($rejected, 'contract validates form_intent as a string field');
ConversionFindingContract::assertFindings($diagnostics, 'emitted form fallback diagnostics');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Form fallback diagnostic intent: passed\n";

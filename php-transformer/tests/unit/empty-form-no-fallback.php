<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$cases = array(
    'bare empty form' => array('<form></form>', false),
    'bare whitespace form' => array("<form> \n\t </form>", false),
    'nested empty form' => array('<aside><div><form method="get" class="filters" action="/shop"></form></div></aside>', false),
    'nested whitespace form' => array("<aside><div><form method=\"get\" class=\"filters\" action=\"/shop\"> \n\t </form></div></aside>", false),
    'text input form' => array('<form><input type="text" name="message"></form>', true),
);

foreach ( $cases as $label => [$fragment, $hasControls] ) {
    $html = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>All products</h1>'
        . $fragment . '<p>No products defined.</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = (new WordPressSitePlan())->fromResult($result);
    $findings = array_filter($plan['quality']['fallbacks'], static fn(array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null));
    if ( $hasControls ) {
        $assert(1 === count($findings), $label . ': keeps html_form_fallback');
        continue;
    }

    $assert(0 === $plan['quality']['metrics']['fallback_count'], $label . ': fallback_count is zero');
    $assert(array() === $findings, $label . ': no html_form_fallback');
    $assert(array() === ($result['source_reports']['runtime_islands'] ?? array()), $label . ': no runtime island');
    $assert(! str_contains($plan['pages'][0]['canonical_block_markup'], '<form'), $label . ': no form block');
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Empty form no-fallback tests: all cases passed\n";

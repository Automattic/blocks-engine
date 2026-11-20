<?php
declare(strict_types=1);

/**
 * Responsive custom properties stay linear in the media conditions.
 *
 * conditionsChanging() resolves a value once per condition, and each
 * condition is its own scope. Computing every ancestor's custom properties
 * again for each scope made a theme that declares many variables on a wrapper
 * cost conditions x variables per value. An ancestor no condition changes
 * shares the result already computed for it. The declaration count is
 * deterministic, unlike a timing, so it holds on a loaded machine.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormCustomPropertyResolver;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( ! $condition ) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 1500)));
    }
};

// A field in eight nested wrappers that declare one variable per
// breakpoint, and the form's gap redefined at each breakpoint.
$run = static function (int $breakpoints): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8"?><html><body>' . str_repeat('<div class="wrap">', 8) . '<form class="form"><label class="field">Name</label></form>' . str_repeat('</div>', 8) . '</body></html>');
    $field = null;
    foreach ( $document->getElementsByTagName('label') as $element ) {
        $field = $element;
    }
    $css = '.form{--gap:4px}.wrap{';
    for ( $i = 0; $i < $breakpoints; ++$i ) {
        $css .= '--tone-' . $i . ':rgb(' . $i . ',0,0);';
    }
    $css .= '}';
    for ( $i = 0; $i < $breakpoints; ++$i ) {
        $css .= '@media (min-width:' . (2000 + $i) . 'px){.form{--gap:' . ($i + 5) . 'px}.missing-' . $i . '{--tone:#222}}';
    }
    $rules = (new CssRuleAnalyzer())->analyze(array(), $css, array('--*'), 1 << 20, 4096, 4096, 8)['rules'];
    $resolver = new FormCustomPropertyResolver(new CssSelectorMatchCache());
    $before = FormCustomPropertyResolver::computedDeclarations();
    $conditions = $resolver->conditionsChanging('var(--gap)', $field, $rules);
    $again = $resolver->conditionsChanging('var(--gap)', $field, $rules);

    return array(
        'conditions' => count($conditions),
        'again' => $again === $conditions,
        'base' => $resolver->resolve('var(--gap)', $field, null, $rules),
        'last' => $breakpoints > 0 ? $resolver->resolve('var(--gap)', $field, end($conditions) ?: null, $rules) : null,
        'computed' => FormCustomPropertyResolver::computedDeclarations() - $before,
    );
};

$results = array();
foreach ( array( 16, 32, 64 ) as $breakpoints ) {
    $result = $run($breakpoints);
    $results[$breakpoints] = $result;
    $assert($breakpoints === $result['conditions'], 'Every breakpoint that redefines the gap is reported (' . $breakpoints . ').', json_encode($result));
    $assert($result['again'], 'Asking again reports the same conditions (' . $breakpoints . ').');
    $assert('4px' === $result['base'], 'The unconditional gap resolves (' . $breakpoints . ').', json_encode($result));
    $assert(($breakpoints + 4) . 'px' === $result['last'], 'The last breakpoint resolves its own gap (' . $breakpoints . ').', json_encode($result));
}

// Doubling the breakpoints doubles both the conditions and the variables.
// Computing every variable per condition would quadruple the declarations.
foreach ( array( array( 16, 32 ), array( 32, 64 ) ) as [ $small, $large ] ) {
    $ratio = $results[$large]['computed'] / max(1, $results[$small]['computed']);
    $assert($ratio < 2.5, 'Doubling the breakpoints at most about doubles the computed declarations (' . $small . ' -> ' . $large . ').', json_encode(array_column($results, 'computed')) . ' ratio ' . round($ratio, 2));
}

echo "form-custom-property-condition-scaling: ok\n";

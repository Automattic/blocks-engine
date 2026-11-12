<?php
declare(strict_types=1);

/** Multiple associated labels must not turn a choice's visible text into helper copy. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
foreach ( array(
    'element-wrapped' => '<div><p>I agree to prepayment.</p></div>',
    'plain-text' => ' I agree to prepayment.',
) as $variant => $copy ) {
    $html = '<main><form method="post"><label for="n">Name</label><input type="text" id="n" name="n">'
        . '<label for="c1">Opt-In</label><label for="c1"><input type="checkbox" id="c1" name="c1" value="true">' . $copy . '</label>'
        . '<input type="submit" value="Send"></form></main>';
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    // The public transformer reports provider-materializable forms as findings;
    // the choice label uses the same metadata slot as a single wrapped checkbox.
    $fallback = current(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null)));
    $controls = is_array($fallback) ? array_column($fallback['controls'] ?? array(), null, 'name') : array();
    $control = $controls['c1'] ?? array();
    if ( 'I agree to prepayment.' !== ($control['label'] ?? null)
        || isset($control['description']) || isset($control['_unresolved_description_candidates'])
        || isset($fallback['form']['unrepresented_context']) ) {
        fwrite(STDERR, 'FAIL ' . $variant . ': ' . json_encode($control) . PHP_EOL);
        ++$failures;
    }
}
if ( 0 < $failures ) {
    exit(1);
}
echo "form control multiple labels: passed\n";

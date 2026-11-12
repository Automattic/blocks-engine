<?php
declare(strict_types=1);

/** Field validation feedback must not become helper text or lost form copy. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$cases = array(
    'one alert' => array('<span role="alert">This field is required</span>', null),
    'two alerts' => array('<span role="alert" aria-live="polite">This field is required</span><span role="alert">Please enter a valid email</span>', null),
    'nested status' => array('<div role="status"><p><span>This field is required</span></p></div>', null),
    'aria-live paragraph' => array('<div><p aria-live="polite">This field is required</p></div>', null),
    'plain help span' => array('<span>Use your work email</span>', 'Use your work email'),
    'help beside feedback' => array('<span>Use your work email</span><span role="alert">This field is required</span>', 'Use your work email'),
    'referenced help' => array('<p id="help">Use your work email</p><span role="status">This field is required</span>', 'Use your work email'),
);
foreach ( $cases as $case => [$copy, $description] ) {
    $html = '<main><form method="post"><div><label for="e">Email</label>'
        . '<input type="email" id="e" name="e" aria-describedby="help" required>' . $copy
        . '</div><div><input type="submit" value="Send"></div></form></main>';
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $fallback = current(array_filter($result['fallbacks'] ?? array(), static fn (array $item): bool => 'html_form_fallback' === ($item['diagnostic_code'] ?? null)));
    $controls = array_column($fallback['controls'] ?? array(), null, 'name');
    $bad = ! isset($controls['e']) || $description !== ($controls['e']['description'] ?? null);
    $walk = static function (array $item) use (&$walk, &$bad): void {
        if ( 'form_field_context_unrepresented' === ($item['code'] ?? $item['diagnostic_code'] ?? null)
            || ! empty($item['unrepresented_context'])
            || ! empty($item['_unresolved_description_candidates']) ) {
            $bad = true;
        }
        foreach ( $item as $value ) {
            if ( is_array($value) ) {
                $walk($value);
            }
        }
    };
    $walk($result);
    if ( $bad ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $case . ' - ' . json_encode($result) . PHP_EOL);
    }
}
if ( 0 < $failures ) {
    exit(1);
}
echo "form validation live region passed\n";

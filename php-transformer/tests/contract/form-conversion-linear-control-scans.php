<?php
declare(strict_types=1);

/**
 * Form conversion stays linear in the number of controls (#6707).
 *
 * Per-control decisions (is this button the form's sole action, does the form
 * collect only choices) ask questions about the whole form. Each form answers
 * them once, so the elements the authored-control scan visits grow with the
 * form, not with controls times form descendants. The count is deterministic,
 * unlike a timing, so it holds on a loaded machine.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( ! $condition ) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 1500)));
    }
};

// The survey from the issue: two radios per fieldset, each fieldset in six
// nested wrappers, and the form in ten.
$survey = static function (int $controls): string {
    $fields = '';
    for ( $i = 0; $i < intdiv($controls, 2); ++$i ) {
        $fields .= str_repeat('<div class="field">', 6)
            . '<fieldset><legend>Question ' . $i . '</legend>'
            . '<label><input type="radio" name="q' . $i . '" value="a"> Yes</label>'
            . '<label><input type="radio" name="q' . $i . '" value="b"> No</label></fieldset>'
            . str_repeat('</div>', 6) . "\n";
    }

    return '<!doctype html><html><head><title>Survey</title></head><body>' . str_repeat('<div class="wrap">', 10)
        . '<form action="/submit" method="post">' . $fields . '<button type="submit">Send</button></form>'
        . str_repeat('</div>', 10) . '</body></html>';
};

$visits = array();
foreach ( array( 40, 80, 160 ) as $controls ) {
    $before = FormControlClassifier::controlScanVisits();
    $result = ( new HtmlTransformer() )->transform($survey($controls))->toArray();
    $visits[$controls] = FormControlClassifier::controlScanVisits() - $before;

    $markup = (string) ( $result['serialized_blocks'] ?? '' );
    $assert($controls === preg_match_all('/<input\b[^>]*type="radio"/', $markup), 'Every radio converts (' . $controls . ' controls).', $markup);
    $assert(1 === preg_match_all('/<button\b[^>]*type="submit"/', $markup), 'The sole submit stays a native submit (' . $controls . ' controls).', $markup);
}

// Doubling the controls doubles the form. A quadratic scan would quadruple
// the visits; allow slack for per-label and per-fieldset scans.
foreach ( array( array( 40, 80 ), array( 80, 160 ) ) as [ $small, $large ] ) {
    $ratio = $visits[$large] / max(1, $visits[$small]);
    $assert($ratio < 2.5, 'Doubling the controls at most about doubles the control-scan visits (' . $small . ' -> ' . $large . ').', json_encode($visits) . ' ratio ' . round($ratio, 2));
}

echo "form-conversion-linear-control-scans: ok\n";

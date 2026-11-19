<?php
declare(strict_types=1);

/**
 * Interaction candidates read a form's action and method, not its full
 * control metadata (#6731).
 *
 * Building a form's metadata resolves every control's context, typography
 * and hidden state through selector matching. The interaction diagnostic
 * needs only where the form submits, so collecting it must not build that
 * metadata. Native GET forms convert without it, so a page of them builds none.
 * The count is deterministic, unlike a timing, so it holds on a loaded machine.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( ! $condition ) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 1500)));
    }
};

$forms = array(
    array( '/search', 'GET' ),
    array( '/archive', 'get' ),
    array( '/directory?lang=en', 'Get' ),
);
$body = '';
foreach ( $forms as $index => [ $action, $method ] ) {
    $body .= '<form action="' . $action . '" method="' . $method . '">'
        . '<div class="field"><label for="f' . $index . '">Field ' . $index . '</label><input id="f' . $index . '" name="f' . $index . '"></div>'
        . '<button>Go</button></form>';
}

$before = FormControlMetadataBuilder::formMetadataBuilds();
$result = ( new HtmlTransformer() )->transform('<!doctype html><html><body>' . $body . '</body></html>')->toArray();
$builds = FormControlMetadataBuilder::formMetadataBuilds() - $before;

$candidates = array_values(array_filter($result['source_reports']['interaction_candidates'] ?? array(), static fn (array $row): bool => 'form' === ($row['kind'] ?? '')));
$assert(0 === $builds, 'Collecting interaction candidates builds no form metadata.', 'builds ' . $builds);
$assert(count($forms) === count($candidates), 'Every form is an interaction candidate.', json_encode($candidates) ?: '');
foreach ( $forms as $index => [ $action, $method ] ) {
    $candidate = $candidates[$index];
    $assert('form:nth-of-type(' . ($index + 1) . ')' === ($candidate['selector'] ?? ''), 'Form candidates keep source order.', json_encode($candidate) ?: '');
    $assert($action === ($candidate['target'] ?? null), 'The candidate targets the form action.', json_encode($candidate) ?: '');
    $assert(array( 'form_element', strtolower($method) ) === ($candidate['evidence'] ?? null), 'The candidate names the lower-cased method.', json_encode($candidate) ?: '');
}

echo "interaction-candidates-skip-form-metadata: ok\n";

<?php
declare(strict_types=1);

/**
 * A form dispatch builds each form's readable block and layout graph once
 * (#6752).
 *
 * The readable block, its fallback finding and the control inventory both
 * enumerate read the same form. They share one inventory and one layout
 * graph, a form that falls through to its event-allowing build reuses the
 * build it already has, and label lookups read one label index instead of
 * scanning every document label per control. The counts are deterministic,
 * unlike a timing, so they hold on a loaded machine.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormBlockBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( ! $condition ) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 1500)));
    }
};

// A data-entry form (readable block plus finding) and a button-only form
// (a readable build that refuses form events, then one that allows them).
$forms = array(
    'data entry' => array(
        '<form action="/contact" method="post"><fieldset class="group"><legend>Contact</legend>'
            . '<div class="field"><label class="label" for="name">Name</label><input class="input" id="name" name="name"></div>'
            . '<div class="field"><label class="label" for="message">Message</label><textarea class="input" id="message" name="message"></textarea></div>'
            . '<div class="field"><input type="checkbox" id="news" name="news"><label class="label" for="news">Newsletter</label></div>'
            . '</fieldset><button type="submit">Send</button></form>',
        1,
    ),
    'button only' => array( '<form action="/vote" method="post"><button type="submit" name="vote" value="up">Up</button><button type="submit" name="vote" value="down">Down</button></form>', 0 ),
);
foreach ( $forms as $name => [ $form, $findings ] ) {
    $graphs = FormLayoutGraphBuilder::builds();
    $readable = ReadableFormBlockBuilder::builds();
    $result = ( new HtmlTransformer() )->transform('<!doctype html><html><head><style>.field{display:flex;gap:4px}.label{font-weight:600}.input{padding:8px}.group{border:0}</style></head><body>' . $form . '</body></html>')->toArray();
    $graphs = FormLayoutGraphBuilder::builds() - $graphs;
    $readable = ReadableFormBlockBuilder::builds() - $readable;

    $assert(1 === $readable, $name . ': the form\'s readable block is built once.', 'builds ' . $readable);
    $assert(1 === $graphs, $name . ': the form\'s layout graph is built once.', 'builds ' . $graphs);
    $formFindings = array_values(array_filter($result['diagnostics'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['code'] ?? '')));
    $assert($findings === count($formFindings), $name . ': the form reports its finding as before.', json_encode($result['diagnostics'] ?? array()) ?: '');
}

// Each explicit label lookup reads the label index, so the labels scanned
// grow with the form, not with controls times labels.
$survey = static function (int $controls): string {
    $fields = '';
    for ( $i = 0; $i < $controls; ++$i ) {
        $fields .= '<div class="field"><label class="label" for="q' . $i . '">Question ' . $i . '</label><input class="input" id="q' . $i . '" name="q' . $i . '"></div>';
    }

    return '<!doctype html><html><head><style>.field{display:flex}.label{font-weight:600}</style></head><body>'
        . '<form action="/submit" method="post">' . $fields . '<button type="submit">Send</button></form></body></html>';
};
$visits = array();
foreach ( array( 40, 80, 160 ) as $controls ) {
    $before = SourceDom::labelScanVisits();
    $result = ( new HtmlTransformer() )->transform($survey($controls))->toArray();
    $visits[$controls] = SourceDom::labelScanVisits() - $before;

    $markup = (string) ( $result['serialized_blocks'] ?? '' );
    $assert($controls === substr_count($markup, '>Question '), 'Every labelled control converts (' . $controls . ' controls).', $markup);
}
foreach ( array( array( 40, 80 ), array( 80, 160 ) ) as [ $small, $large ] ) {
    $ratio = $visits[$large] / max(1, $visits[$small]);
    $assert($ratio < 2.5, 'Doubling the labelled controls at most about doubles the label scans (' . $small . ' -> ' . $large . ').', json_encode($visits) . ' ratio ' . round($ratio, 2));
}

echo "form-dispatch-builds-once: ok\n";

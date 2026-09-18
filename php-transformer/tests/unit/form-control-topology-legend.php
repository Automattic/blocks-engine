<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$formFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    if ( $form instanceof DOMElement ) {
        return $form;
    }
    throw new RuntimeException('No form parsed');
};

$fieldsetNode = static function (string $html) use ($formFrom): array {
    foreach ( (new FormControlTopologyBuilder())->build($formFrom($html))['nodes'] as $node ) {
        if ( 'fieldset' === ($node['tag'] ?? '') ) {
            return $node;
        }
    }

    return array();
};

$radioGroup = static fn (string $legend): string => '<form><div class="wixui-radio-button-group"><fieldset role="radiogroup">'
    . $legend
    . '<div><label><input type="radio" name="topic" value="a"><span>A</span></label>'
    . '<label><input type="radio" name="topic" value="b"><span>B</span></label></div></fieldset></div>'
    . '<button type="submit">Send</button></form>';

// A captioned group carries its caption. Reporting `labelled_group` without the
// caption leaves a consumer nothing to name the group with.
$plain = $fieldsetNode($radioGroup('<legend>Subject</legend>'));
$assert('labelled_group' === ($plain['fieldset_semantics'] ?? ''), 'captioned-fieldset-is-labelled');
$assert('Subject' === ($plain['legend'] ?? ''), 'captioned-fieldset-carries-caption');

// Site builders nest the caption inside presentational elements, so the caption
// is the legend's text, not its first child.
$nested = $fieldsetNode($radioGroup('<legend><div data-testid="groupLabel"><span>Meine Frage dreht sich um…</span></div></legend>'));
$assert('Meine Frage dreht sich um…' === ($nested['legend'] ?? ''), 'nested-caption-reads-as-text');

$wrapped = $fieldsetNode($radioGroup("<legend>\n  What was\tyour  treatment?\n</legend>"));
$assert('What was your treatment?' === ($wrapped['legend'] ?? ''), 'caption-collapses-to-one-line');

// An uncaptioned fieldset has no caption to report, and neither does an empty one.
$uncaptioned = $fieldsetNode($radioGroup(''));
$assert('plain_group' === ($uncaptioned['fieldset_semantics'] ?? ''), 'uncaptioned-fieldset-is-plain');
$assert(! array_key_exists('legend', $uncaptioned), 'uncaptioned-fieldset-reports-no-caption');

$blank = $fieldsetNode($radioGroup('<legend>   </legend>'));
$assert('labelled_group' === ($blank['fieldset_semantics'] ?? ''), 'blank-caption-still-reads-as-labelled');
$assert(! array_key_exists('legend', $blank), 'blank-caption-is-not-reported');

// A caption too long to transport is dropped whole. Half a sentence names a
// group worse than declining to name it.
$oversized = $fieldsetNode($radioGroup('<legend>' . str_repeat('x', 201) . '</legend>'));
$assert(! array_key_exists('legend', $oversized), 'oversized-caption-is-dropped-not-truncated');

$atLimit = $fieldsetNode($radioGroup('<legend>' . str_repeat('x', 200) . '</legend>'));
$assert(str_repeat('x', 200) === ($atLimit['legend'] ?? ''), 'caption-at-the-bound-is-carried');

// Semantics that supersede `labelled_group` report no caption: a consumer keys
// group projection off the labelled semantics, so the two must agree.
$disabled = $fieldsetNode('<form><fieldset disabled><legend>Subject</legend><label><input type="radio" name="topic"></label></fieldset></form>');
$assert('disabled_group' === ($disabled['fieldset_semantics'] ?? ''), 'disabled-fieldset-keeps-disabled-semantics');
$assert(! array_key_exists('legend', $disabled), 'disabled-fieldset-reports-no-caption');

$attributed = $fieldsetNode('<form><fieldset name="topic-group"><legend>Subject</legend><label><input type="radio" name="topic"></label></fieldset></form>');
$assert('attributed_group' === ($attributed['fieldset_semantics'] ?? ''), 'attributed-fieldset-keeps-attributed-semantics');
$assert(! array_key_exists('legend', $attributed), 'attributed-fieldset-reports-no-caption');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form control topology legend tests: ' . $assertions . " passed\n";

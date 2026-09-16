<?php
declare(strict_types=1);

/**
 * A source puts copy inside its own form: an introduction above the fields, a
 * "required field" note. That copy is content a reader sees, but it is not a
 * control, so nothing in the control manifest carries it and a materialized
 * form silently loses it.
 *
 * Record it on the form entity, positioned against the controls it sits
 * around, so the consumer can place it back.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

/** Pull the first form metadata that declares in-form context. */
$formContext = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $found = array();
    $walk = static function ($node) use (&$walk, &$found): void {
        if ( ! is_array($node) || array() !== $found ) {
            return;
        }
        if ( isset($node['context_before']) || isset($node['context_after']) || isset($node['interleaved_context']) ) {
            $found = $node;
            return;
        }
        foreach ( $node as $child ) {
            $walk($child);
        }
    };
    $walk($result);

    return $found;
};

// The captured Weebly contact form.
$weebly = $formContext(
    '<main><form method="post"><ul class="formlist">'
    . '<h2 class="wsite-content-title">Please leave a message and I will get back to you soon!</h2>'
    . '<label class="wsite-form-label wsite-form-fields-required-label"><span>*</span> Indicates required field</label>'
    . '<li><label>Email</label><input type="email" name="email"></li>'
    . '</ul><input type="submit" value="Submit"></form></main>'
);
$before = $weebly['context_before'] ?? array();
$assert( 2 === count($before), 'both pieces of in-form copy are recorded', json_encode($weebly) );
$assert(
    'heading' === ( $before[0]['type'] ?? '' )
        && 2 === ( $before[0]['level'] ?? 0 )
        && 'Please leave a message and I will get back to you soon!' === ( $before[0]['text'] ?? '' ),
    'the form heading keeps its level and text',
    json_encode($before)
);
$assert(
    'paragraph' === ( $before[1]['type'] ?? '' ) && '* Indicates required field' === ( $before[1]['text'] ?? '' ),
    'the required-field note is recorded as a paragraph',
    json_encode($before)
);
$assert( empty($weebly['interleaved_context']), 'copy ahead of every control is not interleaved', json_encode($weebly) );

// Copy after the last control is recorded separately.
$trailing = $formContext(
    '<main><form method="post"><input type="email" name="email"><input type="submit" value="Go">'
    . '<p class="form-note">We reply within a day.</p></form></main>'
);
$assert(
    1 === count($trailing['context_after'] ?? array())
        && 'We reply within a day.' === ( $trailing['context_after'][0]['text'] ?? '' ),
    'copy after the controls is recorded as trailing context',
    json_encode($trailing)
);

// Copy between controls cannot be placed by position alone, so it is flagged.
$between = $formContext(
    '<main><form method="post"><input type="text" name="a">'
    . '<h3>Second section</h3><input type="text" name="b"><input type="submit" value="Go"></form></main>'
);
$assert( ! empty($between['interleaved_context']), 'copy between controls is flagged as interleaved', json_encode($between) );

// An ordinary field label belongs to its field and must not be duplicated.
$plainLabel = $formContext(
    '<main><form method="post"><label for="e">Email</label><input id="e" type="email" name="email">'
    . '<input type="submit" value="Go"></form></main>'
);
$assert(
    array() === ( $plainLabel['context_before'] ?? array() ) && array() === ( $plainLabel['context_after'] ?? array() ),
    'a plain field label is not lifted into form context',
    json_encode($plainLabel)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "form entity in-form context FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form entity in-form context passed: {$passes} assertions\n";

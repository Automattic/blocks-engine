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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

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

// A paragraph that only wraps a field (its label and control) or the submit
// has no copy of its own. Its text is the label or the button text, which the
// control manifest already carries, so it must not come back as a paragraph.
$fieldParagraphs = $formContext(
    '<main><form method="post">'
    . '<p>Join our list.</p>'
    . '<p><label for="e" class="screen-reader-text">Type your email</label><input id="e" type="email" name="email"></p>'
    . '<p><label>Your name<br><span><input type="text" name="name"></span></label></p>'
    . '<p><button type="submit">Join</button></p></form></main>'
);
$assert(
    array( 'Join our list.' ) === array_column($fieldParagraphs['context_before'] ?? array(), 'text')
        && array() === ( $fieldParagraphs['context_after'] ?? array() )
        && array() === ( $fieldParagraphs['unrepresented_context'] ?? array() )
        && empty($fieldParagraphs['interleaved_context']),
    'a paragraph that only wraps a field or the submit is not lifted into form context',
    json_encode($fieldParagraphs)
);

// A form title authored as a plain paragraph — no note-like class — before
// the first control is copy the reader sees, so it is recorded, while the
// field's own label stays with its control.
$plainTitle = $formContext(
    '<main><form method="post">'
    . '<div><p class="form-header"><span><span>Stay Connected with Us</span></span></p></div>'
    . '<label for="email">Email<span aria-hidden="true">*</span></label>'
    . '<input id="email" type="email" name="email" required>'
    . '<input type="submit" value="Subscribe"></form></main>'
);
$plainBefore = $plainTitle['context_before'] ?? array();
$assert(
    1 === count($plainBefore)
        && 'paragraph' === ( $plainBefore[0]['type'] ?? '' )
        && 'Stay Connected with Us' === ( $plainBefore[0]['text'] ?? '' ),
    'a plain <p> form title before the controls is recorded',
    json_encode($plainTitle)
);
$assert(
    ! in_array('Email', array_column($plainBefore, 'text'), true),
    'the field label is not duplicated into form context',
    json_encode($plainBefore)
);

// A context item carries the classes its author rules address, so a consumer
// reproducing it as a block can re-apply the authored presentation.
$styledHeading = $formContext(
    '<main><form method="post"><h2 class="form-title serif">Contact us</h2>'
    . '<input type="email" name="email"><input type="submit" value="Go"></form></main>'
);
$heading = $styledHeading['context_before'][0] ?? array();
$assert(
    'heading' === ( $heading['type'] ?? '' )
        && 2 === ( $heading['level'] ?? 0 )
        && 'form-title serif' === ( $heading['class'] ?? '' ),
    'a context heading carries the author classes',
    json_encode($styledHeading)
);

// The walker visits descendants too, so nested containers must not repeat
// copy that an outer record already carries.
$nested = $formContext(
    '<main><form method="post"><p class="intro">Members: <label for="e">Email address</label> below</p>'
    . '<input id="e" type="email" name="email"><input type="submit" value="Go"></form></main>'
);
$nestedBefore = $nested['context_before'] ?? array();
$assert(
    1 === count($nestedBefore)
        && 'Members: Email address below' === ( $nestedBefore[0]['text'] ?? '' ),
    'nested containers record the copy once',
    json_encode($nested)
);

// A source styles its in-form copy through custom properties set on
// ancestors of the form, so the class alone cannot reproduce it; a context
// heading carries the resolved typography instead.
$resolvedHeading = $formContext(
    '<style>h2{font-size:var(--h-size,28px);color:var(--h-color,#212121);font-family:var(--h-family,unset)}</style>'
    . '<main><div style="--h-size:38px;--h-color:#275f49;--h-family:Georgia"><form method="post">'
    . '<h2 class="form-title">Contact us</h2>'
    . '<input type="email" name="email"><input type="submit" value="Go"></form></div></main>'
);
$typography = $resolvedHeading['context_before'][0] ?? array();
$assert(
    'Contact us' === ( $typography['text'] ?? '' )
        && '38px' === ( $typography['styles']['font_size'] ?? null )
        && '#275f49' === ( $typography['styles']['color'] ?? null )
        && 'Georgia' === ( $typography['styles']['font_family'] ?? null ),
    'a context heading carries its typography resolved through the form ancestors',
    json_encode($resolvedHeading)
);

// Provider forms commonly put a visually hidden submission token before their
// visible intro. It must not turn that intro into interleaved context.
$hiddenProviderControl = $formContext(
    '<main><h2>Contact Us</h2><form aria-live="polite">'
    . '<input type="text" name="_app_id" style="display:none">'
    . '<h4>Drop us a line!</h4><input type="text" name="name">'
    . '<button type="submit">Send</button></form></main>'
);
$hiddenBefore = $hiddenProviderControl['context_before'] ?? array();
$assert(
    1 === count($hiddenBefore)
        && 'heading' === ( $hiddenBefore[0]['type'] ?? '' )
        && 4 === ( $hiddenBefore[0]['level'] ?? 0 )
        && 'Drop us a line!' === ( $hiddenBefore[0]['text'] ?? '' )
        && empty($hiddenProviderControl['interleaved_context']),
    'hidden provider controls do not hide the form introduction',
    json_encode($hiddenProviderControl)
);
// A plain paragraph title whose inner span carries the declarations reads its
// typography through that sole text carrier.
$carrierTitle = $formContext(
    '<style>.t{font-size:var(--p-size,10px);color:var(--p-color,black)}</style>'
    . '<main><div style="--p-size:28px;--p-color:#f3f2ed"><form method="post">'
    . '<p class="form-header"><span class="t">Stay Connected with Us</span></p>'
    . '<input type="email" name="email"><input type="submit" value="Go"></form></div></main>'
);
$paragraph = $carrierTitle['context_before'][0] ?? array();
$assert(
    'Stay Connected with Us' === ( $paragraph['text'] ?? '' )
        && '28px' === ( $paragraph['styles']['font_size'] ?? null )
        && '#f3f2ed' === ( $paragraph['styles']['color'] ?? null ),
    'a plain title reads its typography through its text carrier',
    json_encode($carrierTitle)
);

// A form builder keeps its own status copy ("Thanks for submitting!") in the
// form and hides it until a submission succeeds. That copy is not something a
// reader sees, so the context item says it is hidden and by which property;
// copy the reader does see carries no such fact (#2560).
$statusCopy = $formContext(
    '<style>#msg{visibility:hidden !important}#err{display:none}.shown{visibility:hidden}.shown p{visibility:visible}</style>'
    . '<main><form method="post"><input type="email" name="email"><input type="submit" value="Send">'
    . '<div id="msg" class="rich"><p class="font_5">Thanks for submitting!</p></div>'
    . '<div id="err"><p>Something went wrong.</p></div>'
    . '<div class="shown"><p>We reply within a day.</p></div>'
    . '<p class="note">Your details stay private.</p></form></main>'
);
$statusItems = array_column($statusCopy['context_after'] ?? array(), null, 'text');
$assert(
    'visibility' === ( $statusItems['Thanks for submitting!']['hidden']['property'] ?? null )
        && 'hidden' === ( $statusItems['Thanks for submitting!']['hidden']['value'] ?? null )
        && '#msg' === ( $statusItems['Thanks for submitting!']['hidden']['selector'] ?? null ),
    'copy inside a visibility-hidden box records that it is hidden',
    json_encode($statusCopy)
);
$assert(
    'display' === ( $statusItems['Something went wrong.']['hidden']['property'] ?? null )
        && 'none' === ( $statusItems['Something went wrong.']['hidden']['value'] ?? null ),
    'copy inside a display-none box records that it is hidden',
    json_encode($statusCopy)
);
$assert(
    isset($statusItems['We reply within a day.'], $statusItems['Your details stay private.'])
        && ! isset($statusItems['We reply within a day.']['hidden'])
        && ! isset($statusItems['Your details stay private.']['hidden']),
    'visible copy, including copy that re-shows itself inside a hidden box, carries no hidden fact',
    json_encode($statusCopy)
);
$responsiveCopy = $formContext(
    '<style>.wide-only{display:none}@media (min-width:768px){.wide-only{display:block}}</style>'
    . '<main><form method="post"><input type="email" name="email"><input type="submit" value="Send">'
    . '<p class="wide-only">Call us on weekdays.</p></form></main>'
);
$assert(
    'Call us on weekdays.' === ( $responsiveCopy['context_after'][0]['text'] ?? null ) && ! isset($responsiveCopy['context_after'][0]['hidden']),
    'copy a media query shows is not reported as hidden',
    json_encode($responsiveCopy)
);

// `media="all"` gates nothing, including when a capture declares it as the
// authored media of a per-device stylesheet whose serialized `media="not all"`
// is only its inactive activation state. Status copy hidden that way in every
// device document is still hidden in every condition.
foreach ( array(
    'plain all' => '<style media="all">#msg{visibility:hidden !important}</style>',
    'declared device sheets' => '<style data-dla-device-style="desktop" data-dla-source-media="all" media="not all">:where([data-dla-device-document="desktop"]) #msg{visibility:hidden !important}</style>'
        . '<style data-dla-device-style="mobile" data-dla-source-media="all" media="not all">:where([data-dla-device-document="mobile"]) #msg{visibility:hidden !important}</style>',
) as $case => $sheets ) {
    $allMedia = $formContext(
        $sheets . '<div data-dla-device-document="desktop" data-dla-document-scope=""><main><form method="post"><input type="email" name="email"><input type="submit" value="Send">'
        . '<div id="msg"><p>Thanks for submitting!</p></div></form></main></div>'
    );
    $assert(
        'hidden' === ( $allMedia['context_after'][0]['hidden']['value'] ?? null ),
        'copy hidden by a media="all" stylesheet is hidden in every condition (' . $case . ')',
        json_encode($allMedia)
    );
}

$unrelatedCss = '';
for ($index = 0; $index < 8300; ++$index) {
    $unrelatedCss .= '.unrelated-' . $index . '{display:block;padding:0;font-size:12px}';
}
$formCss = $unrelatedCss . '.scope{--title-size:30px;--title-family:Georgia;--field-size:22px}'
    . '@media(min-width:981px){.scope h2{font-size:var(--title-size);font-family:var(--title-family);line-height:1.5}.scope input{font-size:var(--field-size);font-family:var(--title-family)}}';
$document = new DOMDocument();
$document->loadHTML('<div class="scope"><form><h2>Contact the owner</h2><input type="email" name="email"><button type="submit">Send</button></form></div>');
$form = $document->getElementsByTagName('form')->item(0);
$sheets = array(array('content' => $formCss, 'source_path' => 'styles/owner.css', 'source_hash' => hash('sha256', $formCss)));
$graph = (new FormLayoutGraphBuilder())->build($form, $sheets);
$contextNode = null;
foreach ($graph['nodes'] as $node) {
    if (($node['source']['tag'] ?? '') === 'h2') $contextNode = $node;
}
$assert(!$graph['truncated'], 'unrelated author rules do not exhaust the form-specific graph', json_encode($graph['diagnostics']));
$assert(is_array($contextNode) && !($contextNode['presentation']['truncated'] ?? true), 'context presentation analyzes the form and its inheritance scope');
$contextVariant = $contextNode['presentation']['variants'][0] ?? array();
$assert(($contextVariant['condition']['query'] ?? '') === '(min-width:981px)'
    && ($contextVariant['styles']['font_size'] ?? '') === '30px'
    && ($contextVariant['styles']['font_family'] ?? '') === 'Georgia'
    && ($contextVariant['styles']['line_height'] ?? '') === '1.5',
    'late responsive context typography retains source custom properties', json_encode($contextVariant));
$presentation = (new FormPresentationGraphBuilder())->build($form, $sheets);
$controlVariant = array_values(array_filter($presentation['variants'], static fn(array $variant): bool => ($variant['index'] ?? null) === 0 && ($variant['role'] ?? '') === 'control'))[0] ?? array();
$assert(!$presentation['truncated'] && ($controlVariant['style_patch']['font_size'] ?? '') === '22px'
    && ($controlVariant['style_patch']['font_family'] ?? '') === 'Georgia',
    'late control typography uses the same bounded form-specific cascade', json_encode($presentation['diagnostics']));

if ( 0 < $failures ) {
    fwrite(STDERR, "form entity in-form context FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form entity in-form context passed: {$passes} assertions\n";

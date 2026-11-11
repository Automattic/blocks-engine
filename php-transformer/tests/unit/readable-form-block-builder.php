<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\RuntimeDomTargetsFixture;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeIslandRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormBlockBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredTextareaBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\PatternTreeFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;

$session = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession(new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime(), new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription(new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier(), new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer()));
$session->installGeneratedBlockRegistry(new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry('ssi-fixture'));

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

$createBlock = new SourceBlockCreatorFixture(static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
    'blockName' => $name,
    'attrs' => $attrs,
    'innerBlocks' => $innerBlocks,
));
$presentationAttributes = static fn (DOMElement $element): array => array( 'className' => 'presented-' . strtolower($element->tagName) );
$presentation = new ElementPresentationResolverFixture($presentationAttributes);
$runtimeDomTargets = new RuntimeDomTargetsFixture(static fn (DOMElement $element): bool => $element->hasAttribute('data-runtime'));
$recorded = array();
$echoStart = count($session->transformationEvidenceState()->formControlEchoTexts());
$metadataBuilder = new FormControlMetadataBuilder();
$runtimeRecorder = new FormRuntimeIslandRecorder(
    $metadataBuilder,
    static function (DOMElement $element, string $kind, string $reason, string $capability, array $metadata) use (&$recorded): void {
        $recorded[] = compact('kind', 'reason', 'capability', 'metadata');
    },
    $session
);
$authoredRegistry = $session->requireGeneratedBlockRegistry();
$authoredConverter = new AuthoredFormControlBlockConverter(
    $metadataBuilder,
    static fn (DOMElement $element): array => $element->hasAttribute('data-styled') ? array( 'display' => 'block' ) : array(),
    $presentation,
    $createBlock,
    $session,
    new Runtime(),
    static fn (string $id): string => $id,
    static fn (DOMElement $element): DOMElement => $element
);
$controlConverter = new ReadableFormControlBlockConverter(
    $metadataBuilder,
    $authoredConverter,
    $runtimeRecorder,
    new Runtime(),
    $runtimeDomTargets,
    $presentation,
    $createBlock,
    $session
);
$layoutShellBlockForElements = static function (array $elements, array $innerBlocks, DOMElement $sourceElement): array {
    return array(
        'blockName' => 'custom/layout-shell',
        'attrs' => array(
            'wrappers' => array_map(static function (DOMElement $element): array {
                $attributes = array();
                if ( $element->hasAttribute('class') ) {
                    $attributes['class'] = $element->getAttribute('class');
                }
                return array(
                    'tagName' => strtolower($element->tagName),
                    'attributes' => $attributes,
                );
            }, $elements),
        ),
        'innerBlocks' => $innerBlocks,
    );
};
$rowCss = '.fields-row{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem}';
$compilation = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation();
$ownership = (new ReflectionProperty($compilation, 'authorLayoutOwnership'))->getValue($compilation);
$session->installAuthorStyleAnalysis(new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis('', $rowCss, array(array('path' => 'style.css', 'source_path' => 'style.css', 'content' => $rowCss, 'source_hash' => hash('sha256', $rowCss), 'media' => '')), $formFrom('<form></form>')));
$session->sourceStyleResolutionState()->setFormLayoutCss($rowCss);
$builder = new ReadableFormBlockBuilder(
    $metadataBuilder,
    $controlConverter,
    $runtimeRecorder,
    $runtimeDomTargets,
    $presentationAttributes,
    $ownership,
    $presentation,
    $createBlock,
    $layoutShellBlockForElements,
    static fn (array $elements): array => array(
        'blockName' => 'test/layout-shell',
        'attrs' => array(
            'wrappers' => array_map(static function (DOMElement $element): array {
                return array(
                    'tagName' => strtolower($element->tagName),
                    'attributes' => array('class' => $element->getAttribute('class')),
                );
            }, $elements),
        ),
        'innerBlocks' => array(),
        'innerHTML' => '',
        'innerContent' => array('<div>', '</div>'),
    ),
    $session,
    // The production flow converter declines standalone controls; stray copy
    // converts as ordinary flow content.
    new PatternTreeFixture(element: static function (DOMElement $element, array &$fallbacks): ?array {
        if ( in_array(strtolower($element->tagName), array( 'input', 'select', 'textarea', 'button' ), true) ) {
            return null;
        }
        return array(
            'blockName' => 'core/paragraph',
            'attrs' => array( 'content' => trim($element->textContent) ),
            'innerBlocks' => array(),
        );
    })
);

$assert(null === $builder->build($formFrom('<form></form>')), 'empty-form-declines-block');
$assert(null === $builder->build($formFrom('<form><script>submit()</script><input></form>')), 'scripted-form-declines-block');

$eventForm = $formFrom('<form onsubmit="handle()"><input aria-label="Email"></form>');
$assert(null === $builder->build($eventForm), 'eventful-form-declines-by-default');
$assert('core/group' === ($builder->build($eventForm, true)['blockName'] ?? ''), 'eventful-form-builds-when-allowed');

$assert(null === $builder->build($formFrom('<form><input onchange="handle()"></form>')), 'eventful-control-declines-form');
$assert(null === $builder->build($formFrom('<form><input type="hidden" value="secret"></form>')), 'unreadable-control-declines-form');

$echoStart = count($session->transformationEvidenceState()->formControlEchoTexts());
$plain = $builder->build($formFrom('<form><input aria-label="Email" value="a&amp;b" required></form>'));
$assert('core/group' === ($plain['blockName'] ?? ''), 'plain-form-builds-group');
$assert('presented-form' === ($plain['attrs']['className'] ?? ''), 'form-presentation-is-retained');
$assert('core/paragraph' === ($plain['innerBlocks'][0]['blockName'] ?? ''), 'plain-control-remains-direct-child');
$assert('Email: a&amp;b (required)' === ($plain['innerBlocks'][0]['attrs']['content'] ?? ''), 'plain-control-summary-is-retained');
$assert(array( 'Email: a&b (required)' ) === array_slice($session->transformationEvidenceState()->formControlEchoTexts(), $echoStart), 'plain-control-registers-echo');

$submit = $builder->build($formFrom('<form><button type="submit" class="send">Join &amp; Go</button></form>'));
$submitButton = $submit['innerBlocks'][0] ?? array();
$assert($authoredRegistry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME) === ($submitButton['blockName'] ?? ''), 'submit-control-uses-authored-button');
$assert('submit' === ($submitButton['attrs']['type'] ?? '') && 'Join & Go' === ($submitButton['attrs']['text'] ?? ''), 'submit-type-and-text-are-retained');
$assert('send' === ($submitButton['attrs']['className'] ?? ''), 'submit-source-class-is-retained');
$assert('<button type="submit" class="send">Join &amp; Go</button>' === ($submitButton['innerHTML'] ?? ''), 'submit-emits-native-button-markup');

$combined = $builder->build($formFrom('<form><input aria-label="Email"><button type="submit">Join</button></form>'));
$assert('core/paragraph' === ($combined['innerBlocks'][0]['blockName'] ?? '') && $authoredRegistry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME) === ($combined['innerBlocks'][1]['blockName'] ?? ''), 'submit-buttons-follow-fields');

$inputSubmit = $builder->build($formFrom('<form><input type="submit" value="Send" class="go"></form>'));
$inputSubmitBlock = $inputSubmit['innerBlocks'][0] ?? array();
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($inputSubmitBlock['blockName'] ?? ''), 'input-submit-uses-authored-input');
$assert('submit' === ($inputSubmitBlock['attrs']['type'] ?? '') && 'Send' === ($inputSubmitBlock['attrs']['value'] ?? ''), 'input-submit-keeps-type-and-value');

$styled = $builder->build($formFrom('<form><label for="email">Email</label><input data-styled id="email" name="email"></form>'));
$assert('form' === ($styled['attrs']['tagName'] ?? ''), 'degraded-form-keeps-the-form-element');
$styledInput = $styled['innerBlocks'][0] ?? array();
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($styledInput['blockName'] ?? ''), 'authored-input-remains-the-field-without-a-synthetic-layout-box');
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($styledInput['blockName'] ?? ''), 'associated-label-rides-on-the-authored-input');
$assert('Email' === ($styledInput['attrs']['label'] ?? ''), 'associated-label-text-survives-as-a-label-element');

// A label the source associates by position alone — no `for`, no `id` — is the
// dominant authored pattern and must survive the degraded form just as well.
$positional = $builder->build($formFrom('<form><div><label class="field-label">Full name *</label><input data-styled type="text" required></div><div><label>Details *</label><textarea data-styled rows="5" placeholder="Tell us more" required></textarea></div></form>'));
$positionalInput = $positional['innerBlocks'][0] ?? array();
$assert('Full name *' === ($positionalInput['attrs']['label'] ?? ''), 'field-wrapper-label-reaches-the-authored-input');
$assert('field-label' === ($positionalInput['attrs']['labelClassName'] ?? ''), 'field-wrapper-label-keeps-its-authored-class');
$positionalTextarea = $positional['innerBlocks'][1] ?? array();
$assert($authoredRegistry->blockName(AuthoredTextareaBlockGenerator::LOCAL_NAME) === ($positionalTextarea['blockName'] ?? ''), 'styled-textarea-keeps-an-editable-control');
$assert('Details *' === ($positionalTextarea['attrs']['label'] ?? '') && '5' === ($positionalTextarea['attrs']['rows'] ?? '') && 'Tell us more' === ($positionalTextarea['attrs']['placeholder'] ?? ''), 'textarea-label-rows-and-placeholder-survive');

$sharedWrapper = $builder->build($formFrom('<form><div><label>Ambiguous</label><input data-styled type="text"><input data-styled type="tel"></div></form>'));
$sharedShell = $sharedWrapper['innerBlocks'][0] ?? array();
$assert('custom/layout-shell' === ($sharedShell['blockName'] ?? ''), 'a-wrapper-shared-by-two-controls-stays-a-layout-shell');
$assert('' === ($sharedShell['innerBlocks'][0]['attrs']['label'] ?? ''), 'a-wrapper-shared-by-two-controls-claims-no-label');

$rowGrouped = $builder->build($formFrom('<form class="stack"><div class="fields-row"><div><label class="field-label">Name *</label><input data-styled type="text" required></div><div><label class="field-label">Phone *</label><input data-styled type="tel" required></div></div><div><label class="field-label">Email *</label><input data-styled type="email" required></div></form>'));
$rowShell = $rowGrouped['innerBlocks'][0] ?? array();
$assert('custom/layout-shell' === ($rowShell['blockName'] ?? ''), 'shared-row-wrapper-is-a-layout-shell');
$assert('fields-row' === ($rowShell['attrs']['wrappers'][0]['attributes']['class'] ?? ''), 'shared-row-wrapper-keeps-its-source-class');
$assert(2 === count($rowShell['innerBlocks'] ?? array()), 'shared-row-wrapper-keeps-both-row-controls');
$assert('Name *' === ($rowShell['innerBlocks'][0]['attrs']['label'] ?? ''), 'row-name-control-stays-inside-the-shared-wrapper');
$assert('Phone *' === ($rowShell['innerBlocks'][1]['attrs']['label'] ?? ''), 'row-phone-control-stays-inside-the-shared-wrapper');
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($rowGrouped['innerBlocks'][1]['blockName'] ?? ''), 'standalone-control-stays-a-direct-form-child');
$rowGraph = $builder->layoutGraph() ?? array();
$rowNodes = array_column($rowGraph['nodes'] ?? array(), null, 'id');
$assert('generic/computed-layout-graph/v3' === ($rowGraph['schema'] ?? null), 'degrade-path-consumes-layout-graph');
$assert('grid' === ($rowNodes['wrapper-0']['layout']['display'] ?? null), 'row-display-comes-from-the-layout-graph');
$assert('1fr 1fr' === ($rowNodes['wrapper-0']['layout']['columns'] ?? null), 'row-columns-come-from-the-layout-graph');
$assert('1.5rem' === ($rowNodes['wrapper-0']['layout']['gap'] ?? null), 'row-gap-comes-from-the-layout-graph');

$recorded = array();
$runtimeForm = $builder->build($formFrom('<form><input data-runtime name="email"></form>'));
$assert('core/html' === ($runtimeForm['innerBlocks'][0]['blockName'] ?? ''), 'runtime-control-is-preserved');
$assert(array() !== $recorded && 'runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'runtime-control-records-island');

// A wrapping label that owns its control (the default Framer field shape)
// frames it outermost first: the walk shells the shared row, the field box
// ends at the caption label, and the wrapper between the caption level and
// the control rides separately as the control's own box — so no frame of a
// control ever stands below its own descendant (`row > lab > wrap`, never
// `row > wrap > lab`).
$framer = $builder->build($formFrom('<form><div class="row"><label class="lab"><div class="cap"><p>Nom</p></div><div class="wrap"><input name="nom"></div></label><label class="lab"><div class="cap"><p>Mail</p></div><div class="wrap"><input name="mail"></div></label></div></form>'));
$wrapperClasses = static fn (array $block): array => array_map(
    static fn (array $wrapper): string => $wrapper['attributes']['class'] ?? '',
    $block['attrs']['wrappers'] ?? array()
);
$frames = array();
$boxes = array();
$frameWalk = static function (array $blocks, array $classes) use (&$frameWalk, &$frames, &$boxes, $wrapperClasses): void {
    foreach ( $blocks as $block ) {
        $own = $wrapperClasses($block);
        if ( isset($block['form_control_index']) ) {
            $boxes[ $block['form_control_index'] ] = array(
                'field' => $wrapperClasses($block['_form_field_box'] ?? array()),
                'control' => $wrapperClasses($block['_form_control_box'] ?? array()),
            );
            $frames[ $block['form_control_index'] ] = array_merge(
                $classes,
                $own,
                $boxes[ $block['form_control_index'] ]['field'],
                $boxes[ $block['form_control_index'] ]['control']
            );
        }
        $frameWalk($block['innerBlocks'] ?? array(), array_merge($classes, $own));
    }
};
$frameWalk($framer['innerBlocks'] ?? array(), array());
$assert(array( 'row', 'lab', 'wrap' ) === ($frames[0] ?? null), 'row-field-frames-keep-source-order');
$assert(array( 'row', 'lab', 'wrap' ) === ($frames[1] ?? null), 'second-row-field-frames-keep-source-order');
$assert(array( 'field' => array( 'lab' ), 'control' => array( 'wrap' ) ) === ($boxes[0] ?? null), 'caption-frame-bounds-the-field-box-and-the-wrapper-is-the-control-box');
$assert(array( 'field' => array( 'lab' ), 'control' => array( 'wrap' ) ) === ($boxes[1] ?? null), 'second-caption-frame-bounds-the-field-box-and-the-wrapper-is-the-control-box');

// A field whose control carries no inner wrapper keeps its whole box: the
// label is the caption level and frames the field, and no control box exists.
$flat = $builder->build($formFrom('<form><label class="lab"><span class="cap">Nom</span><input name="nom"></label></form>'));
$flatBoxes = array();
$boxWalk = static function (array $blocks) use (&$boxWalk, &$flatBoxes, $wrapperClasses): void {
    foreach ( $blocks as $block ) {
        if ( isset($block['form_control_index']) ) {
            $flatBoxes[ $block['form_control_index'] ] = array(
                'field' => $wrapperClasses($block['_form_field_box'] ?? array()),
                'control' => $wrapperClasses($block['_form_control_box'] ?? array()),
            );
        }
        $boxWalk($block['innerBlocks'] ?? array());
    }
};
$boxWalk($flat['innerBlocks'] ?? array());
$assert(array( 'field' => array( 'lab' ), 'control' => array() ) === ($flatBoxes[0] ?? null), 'a-field-without-an-inner-control-wrapper-keeps-its-field-box');

// A caption beside its control (the associated-label shape) bounds the field
// box at the exclusive frame holding both; the control row between that
// frame and the control is the control's own box.
$split = $builder->build($formFrom('<form><div class="field"><div class="cap"><label for="mail">Mail</label></div><div class="wrap"><input id="mail" name="mail"></div></div></form>'));
$splitBoxes = array();
$splitWalk = static function (array $blocks) use (&$splitWalk, &$splitBoxes, $wrapperClasses): void {
    foreach ( $blocks as $block ) {
        if ( isset($block['form_control_index']) ) {
            $splitBoxes[ $block['form_control_index'] ] = array(
                'field' => $wrapperClasses($block['_form_field_box'] ?? array()),
                'control' => $wrapperClasses($block['_form_control_box'] ?? array()),
            );
        }
        $splitWalk($block['innerBlocks'] ?? array());
    }
};
$splitWalk($split['innerBlocks'] ?? array());
$assert(array( 'field' => array( 'field' ), 'control' => array( 'wrap' ) ) === ($splitBoxes[0] ?? null), 'an-associated-caption-bounds-the-field-box-at-the-frame-holding-both');

// https://github.com/Automattic/blocks-engine/issues/4959
$reducedPage = <<<'HTML'
<!doctype html><html><head><title>Contact</title></head><body><main><h1>Contact</h1>
<form action="/submit" method="POST">
<label for="email">Email</label><input id="email" type="text" name="email">
<label for="msg">Comment</label><textarea id="msg" name="msg"></textarea>
<div><input type="submit" value="Submit" style="position:absolute;top:0;left:-9999px;width:1px;height:1px">
<a class="button"><span>Submit</span></a></div>
</form></main></body></html>
HTML;
$reducedForm = $formFrom($reducedPage);
$indexed = array();
$indexWalk = static function (array $blocks) use (&$indexWalk, &$indexed): void {
    foreach ( $blocks as $block ) {
        if ( isset($block['form_control_index']) ) {
            $indexed[$block['form_control_index']][] = $block;
        }
        $indexWalk($block['innerBlocks'] ?? array());
    }
};
$indexWalk(array($builder->build($reducedForm) ?? array()));
$declared = \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier::controlElements($reducedForm);
$assert(count($declared) === count($indexed), 'reduced-page-covers-all-declared-controls');
foreach ( $declared as $index => $control ) {
    $assert(1 === count($indexed[$index] ?? array()), 'reduced-page-control-index-exactly-once-' . $index);
}
$presentedSubmit = $indexed[2][0] ?? array();
$assert('button' === ($presentedSubmit['attrs']['className'] ?? ''), 'submit-uses-visible-presenter-class');
$assert('Submit' === ($presentedSubmit['attrs']['text'] ?? '') && 'submit' === ($presentedSubmit['attrs']['type'] ?? ''), 'presented-submit-retains-label-and-native-type');
$assert(! str_contains($presentedSubmit['innerHTML'] ?? '', '-9999px'), 'presented-submit-is-not-offscreen');

// Exercise the production flow converter and published finding as well as
// the builder fixture: the omitted native input must not reappear as copy.
$compiled = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $reducedPage),
))->toArray();
$finding = null;
$findForm = static function (array $value) use (&$findForm, &$finding): void {
    if ( 'html_form_fallback' === ($value['diagnostic_code'] ?? null) ) {
        $finding = $value;
    }
    foreach ( $value as $child ) {
        if ( is_array($child) ) {
            $findForm($child);
        }
    }
};
$findForm($compiled);
$indexed = array();
$indexWalk($finding['readable_blocks'] ?? array());
$assert(3 === count($finding['controls'] ?? array()), 'published-finding-declares-all-three-controls');
$assert(array(0, 1, 2) === array_keys($indexed), 'published-readable-tree-covers-declared-indices');
foreach ( $indexed as $index => $blocks ) {
    $assert(1 === count($blocks), 'published-control-index-exactly-once-' . $index);
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Readable form block builder tests: ' . $assertions . " passed\n";

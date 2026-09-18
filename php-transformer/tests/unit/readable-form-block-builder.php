<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

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
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;

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
$eventMetadata = static fn (DOMElement $element): array => $element->hasAttribute('data-event') ? array( 'submit' => true ) : array();
$isRuntimeDomTarget = static fn (DOMElement $element): bool => $element->hasAttribute('data-runtime');
$recorded = array();
$echoes = array();
$metadataBuilder = new FormControlMetadataBuilder(static fn (DOMElement $element): string => strtolower($element->tagName));
$runtimeRecorder = new FormRuntimeIslandRecorder(
    $metadataBuilder,
    static function (DOMElement $element, string $kind, string $reason, string $capability, array $metadata) use (&$recorded): void {
        $recorded[] = compact('kind', 'reason', 'capability', 'metadata');
    },
    $eventMetadata,
    static fn (DOMElement $element): array => array()
);
$authoredRegistry = new GeneratedBlockRegistry('ssi-fixture');
$authoredConverter = new AuthoredFormControlBlockConverter(
    $metadataBuilder,
    static fn (DOMElement $element): array => $element->hasAttribute('data-styled') ? array( 'display' => 'block' ) : array(),
    $presentationAttributes,
    $createBlock,
    static fn (): GeneratedBlockRegistry => $authoredRegistry,
    static function (string $text) use (&$echoes): void {
        $echoes[] = $text;
    },
    new Runtime(),
    static fn (string $id): string => $id
);
$controlConverter = new ReadableFormControlBlockConverter(
    $metadataBuilder,
    $authoredConverter,
    $runtimeRecorder,
    new Runtime(),
    $eventMetadata,
    $isRuntimeDomTarget,
    static fn (DOMElement $element): array => array( 'blockName' => 'core/html', 'attrs' => array( 'content' => 'preserved' ) ),
    $presentationAttributes,
    $createBlock,
    static function (string $text) use (&$echoes): void {
        $echoes[] = $text;
    }
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
$builder = new ReadableFormBlockBuilder(
    $metadataBuilder,
    $controlConverter,
    $runtimeRecorder,
    $eventMetadata,
    $isRuntimeDomTarget,
    $presentationAttributes,
    $createBlock,
    static fn (string $localName): string => $authoredRegistry->blockName($localName),
    $layoutShellBlockForElements,
    static fn (): array => array(
        array(
            'path' => 'style.css',
            'source_path' => 'style.css',
            'content' => $rowCss,
            'source_hash' => hash('sha256', $rowCss),
            'media' => '',
        ),
    ),
    static fn (): string => $rowCss
);

$assert(null === $builder->build($formFrom('<form></form>')), 'empty-form-declines-block');
$assert(null === $builder->build($formFrom('<form><script>submit()</script><input></form>')), 'scripted-form-declines-block');

$eventForm = $formFrom('<form data-event><input aria-label="Email"></form>');
$assert(null === $builder->build($eventForm), 'eventful-form-declines-by-default');
$assert('core/group' === ($builder->build($eventForm, true)['blockName'] ?? ''), 'eventful-form-builds-when-allowed');

$assert(null === $builder->build($formFrom('<form><input data-event></form>')), 'eventful-control-declines-form');
$assert(null === $builder->build($formFrom('<form><input type="hidden" value="secret"></form>')), 'unreadable-control-declines-form');

$echoes = array();
$plain = $builder->build($formFrom('<form><input aria-label="Email" value="a&amp;b" required></form>'));
$assert('core/group' === ($plain['blockName'] ?? ''), 'plain-form-builds-group');
$assert('presented-form' === ($plain['attrs']['className'] ?? ''), 'form-presentation-is-retained');
$assert('core/paragraph' === ($plain['innerBlocks'][0]['blockName'] ?? ''), 'plain-control-remains-direct-child');
$assert('Email: a&amp;b (required)' === ($plain['innerBlocks'][0]['attrs']['content'] ?? ''), 'plain-control-summary-is-retained');
$assert(array( 'Email: a&b (required)' ) === $echoes, 'plain-control-registers-echo');

$submit = $builder->build($formFrom('<form><button type="submit" class="send">Join &amp; Go</button></form>'));
$submitButton = $submit['innerBlocks'][0] ?? array();
$assert($authoredRegistry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME) === ($submitButton['blockName'] ?? ''), 'submit-control-uses-authored-button');
$assert('submit' === ($submitButton['attrs']['type'] ?? '') && 'Join & Go' === ($submitButton['attrs']['text'] ?? ''), 'submit-type-and-text-are-retained');
$assert('send' === ($submitButton['attrs']['className'] ?? ''), 'submit-source-class-is-retained');
$assert('<button type="submit" class="send">Join &amp; Go</button>' === ($submitButton['innerHTML'] ?? ''), 'submit-emits-native-button-markup');

$combined = $builder->build($formFrom('<form><input aria-label="Email"><button type="submit">Join</button></form>'));
$assert('core/paragraph' === ($combined['innerBlocks'][0]['blockName'] ?? '') && $authoredRegistry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME) === ($combined['innerBlocks'][1]['blockName'] ?? ''), 'submit-buttons-follow-fields');

$inputSubmit = $builder->build($formFrom('<form><input type="submit" value="Send" class="go"></form>'));
$inputSubmitBlock = $inputSubmit['innerBlocks'][0]['innerBlocks'][0] ?? array();
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($inputSubmitBlock['blockName'] ?? ''), 'input-submit-uses-authored-input');
$assert('submit' === ($inputSubmitBlock['attrs']['type'] ?? '') && 'Send' === ($inputSubmitBlock['attrs']['value'] ?? ''), 'input-submit-keeps-type-and-value');

$styled = $builder->build($formFrom('<form><label for="email">Email</label><input data-styled id="email" name="email"></form>'));
$assert('form' === ($styled['attrs']['tagName'] ?? ''), 'degraded-form-keeps-the-form-element');
$fieldGroup = $styled['innerBlocks'][0] ?? array();
$assert('core/group' === ($fieldGroup['blockName'] ?? ''), 'authored-input-builds-field-group');
$styledInput = $fieldGroup['innerBlocks'][0] ?? array();
$assert(1 === count($fieldGroup['innerBlocks'] ?? array()) && $authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($styledInput['blockName'] ?? ''), 'associated-label-rides-on-the-authored-input');
$assert('Email' === ($styledInput['attrs']['label'] ?? ''), 'associated-label-text-survives-as-a-label-element');

// A label the source associates by position alone — no `for`, no `id` — is the
// dominant authored pattern and must survive the degraded form just as well.
$positional = $builder->build($formFrom('<form><div><label class="field-label">Full name *</label><input data-styled type="text" required></div><div><label>Details *</label><textarea data-styled rows="5" placeholder="Tell us more" required></textarea></div></form>'));
$positionalInput = $positional['innerBlocks'][0]['innerBlocks'][0] ?? array();
$assert('Full name *' === ($positionalInput['attrs']['label'] ?? ''), 'field-wrapper-label-reaches-the-authored-input');
$assert('field-label' === ($positionalInput['attrs']['labelClassName'] ?? ''), 'field-wrapper-label-keeps-its-authored-class');
$positionalTextarea = $positional['innerBlocks'][1] ?? array();
$assert($authoredRegistry->blockName(AuthoredTextareaBlockGenerator::LOCAL_NAME) === ($positionalTextarea['blockName'] ?? ''), 'styled-textarea-keeps-an-editable-control');
$assert('Details *' === ($positionalTextarea['attrs']['label'] ?? '') && '5' === ($positionalTextarea['attrs']['rows'] ?? '') && 'Tell us more' === ($positionalTextarea['attrs']['placeholder'] ?? ''), 'textarea-label-rows-and-placeholder-survive');

$sharedWrapper = $builder->build($formFrom('<form><div><label>Ambiguous</label><input data-styled type="text"><input data-styled type="tel"></div></form>'));
$sharedShell = $sharedWrapper['innerBlocks'][0] ?? array();
$assert('custom/layout-shell' === ($sharedShell['blockName'] ?? ''), 'a-wrapper-shared-by-two-controls-stays-a-layout-shell');
$assert('' === ($sharedShell['innerBlocks'][0]['innerBlocks'][0]['attrs']['label'] ?? ''), 'a-wrapper-shared-by-two-controls-claims-no-label');

$rowGrouped = $builder->build($formFrom('<form class="stack"><div class="fields-row"><div><label class="field-label">Name *</label><input data-styled type="text" required></div><div><label class="field-label">Phone *</label><input data-styled type="tel" required></div></div><div><label class="field-label">Email *</label><input data-styled type="email" required></div></form>'));
$rowShell = $rowGrouped['innerBlocks'][0] ?? array();
$assert('custom/layout-shell' === ($rowShell['blockName'] ?? ''), 'shared-row-wrapper-is-a-layout-shell');
$assert('fields-row' === ($rowShell['attrs']['wrappers'][0]['attributes']['class'] ?? ''), 'shared-row-wrapper-keeps-its-source-class');
$assert(2 === count($rowShell['innerBlocks'] ?? array()), 'shared-row-wrapper-keeps-both-row-controls');
$assert('Name *' === ($rowShell['innerBlocks'][0]['innerBlocks'][0]['attrs']['label'] ?? ''), 'row-name-control-stays-inside-the-shared-wrapper');
$assert('Phone *' === ($rowShell['innerBlocks'][1]['innerBlocks'][0]['attrs']['label'] ?? ''), 'row-phone-control-stays-inside-the-shared-wrapper');
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($rowGrouped['innerBlocks'][1]['innerBlocks'][0]['blockName'] ?? ''), 'standalone-control-stays-a-direct-form-child');
$rowGraph = $builder->layoutGraph() ?? array();
$rowNodes = array_column($rowGraph['nodes'] ?? array(), null, 'id');
$assert('generic/computed-layout-graph/v2' === ($rowGraph['schema'] ?? null), 'degrade-path-consumes-layout-graph');
$assert('grid' === ($rowNodes['wrapper-0']['layout']['display'] ?? null), 'row-display-comes-from-the-layout-graph');
$assert('1fr 1fr' === ($rowNodes['wrapper-0']['layout']['columns'] ?? null), 'row-columns-come-from-the-layout-graph');
$assert('1.5rem' === ($rowNodes['wrapper-0']['layout']['gap'] ?? null), 'row-gap-comes-from-the-layout-graph');

$recorded = array();
$runtimeForm = $builder->build($formFrom('<form><input data-runtime name="email"></form>'));
$assert('core/html' === ($runtimeForm['innerBlocks'][0]['blockName'] ?? ''), 'runtime-control-is-preserved');
$assert(array() !== $recorded && 'runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'runtime-control-records-island');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Readable form block builder tests: ' . $assertions . " passed\n";

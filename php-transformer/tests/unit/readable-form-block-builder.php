<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeIslandRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormBlockBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
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
$authoredConverter = new AuthoredFormControlBlockConverter(
    $metadataBuilder,
    static fn (DOMElement $element): array => $element->hasAttribute('data-styled') ? array( 'display' => 'block' ) : array(),
    $presentationAttributes,
    $createBlock,
    static function (string $identity, array $definition): void {
    },
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
$builder = new ReadableFormBlockBuilder(
    $metadataBuilder,
    $controlConverter,
    $runtimeRecorder,
    new Runtime(),
    $eventMetadata,
    $isRuntimeDomTarget,
    $presentationAttributes,
    $createBlock
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

$submit = $builder->build($formFrom('<form><button type="submit">Join &amp; Go</button></form>'));
$buttons = $submit['innerBlocks'][0] ?? array();
$assert('core/buttons' === ($buttons['blockName'] ?? ''), 'submit-controls-build-buttons-container');
$assert('Join &amp; Go' === ($buttons['innerBlocks'][0]['attrs']['text'] ?? ''), 'submit-text-is-escaped');
$assert('presented-button' === ($buttons['innerBlocks'][0]['attrs']['className'] ?? ''), 'submit-presentation-is-retained');

$combined = $builder->build($formFrom('<form><input aria-label="Email"><button type="submit">Join</button></form>'));
$assert('core/paragraph' === ($combined['innerBlocks'][0]['blockName'] ?? '') && 'core/buttons' === ($combined['innerBlocks'][1]['blockName'] ?? ''), 'submit-buttons-follow-fields');

$styled = $builder->build($formFrom('<form><label for="email">Email</label><input data-styled id="email" name="email"></form>'));
$fieldGroup = $styled['innerBlocks'][0] ?? array();
$assert('core/group' === ($fieldGroup['blockName'] ?? ''), 'authored-input-builds-field-group');
$assert('core/paragraph' === ($fieldGroup['innerBlocks'][0]['blockName'] ?? ''), 'associated-label-precedes-authored-input');
$assert(AuthoredInputBlockGenerator::NAME === ($fieldGroup['innerBlocks'][1]['blockName'] ?? ''), 'authored-input-follows-associated-label');

$recorded = array();
$runtimeForm = $builder->build($formFrom('<form><input data-runtime name="email"></form>'));
$assert('core/html' === ($runtimeForm['innerBlocks'][0]['blockName'] ?? ''), 'runtime-control-is-preserved');
$assert(array() !== $recorded && 'runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'runtime-control-records-island');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Readable form block builder tests: ' . $assertions . " passed\n";

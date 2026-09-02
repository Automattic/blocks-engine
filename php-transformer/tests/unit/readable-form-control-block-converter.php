<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeIslandRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$elementFrom = static function (string $html, string $tagName): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName($tagName)->item(0);
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No ' . $tagName . ' parsed');
};

$createBlock = static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
    'blockName' => $name,
    'attrs' => $attrs,
    'innerBlocks' => $innerBlocks,
);
$presentationAttributes = static fn (DOMElement $element): array => array( 'className' => 'presented' );
$echoes = array();
$recorded = array();
$metadataBuilder = new FormControlMetadataBuilder(static fn (DOMElement $element): string => strtolower($element->tagName));
$runtimeRecorder = new FormRuntimeIslandRecorder(
    $metadataBuilder,
    static function (DOMElement $element, string $kind, string $reason, string $capability, array $metadata) use (&$recorded): void {
        $recorded[] = compact('kind', 'reason', 'capability', 'metadata');
    },
    static fn (DOMElement $element): array => array(),
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
    static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    static fn (string $id): string => $id
);
$converter = new ReadableFormControlBlockConverter(
    $metadataBuilder,
    $authoredConverter,
    $runtimeRecorder,
    new Runtime(),
    static fn (DOMElement $element): array => $element->hasAttribute('data-event') ? array( 'change' => true ) : array(),
    static fn (DOMElement $element): bool => $element->hasAttribute('data-runtime'),
    static fn (DOMElement $element): array => array( 'blockName' => 'core/html', 'attrs' => array( 'content' => 'preserved-' . strtolower($element->tagName) ) ),
    $presentationAttributes,
    $createBlock,
    static function (string $text) use (&$echoes): void {
        $echoes[] = $text;
    }
);

$plainLabel = $converter->convert($elementFrom('<label>Terms &amp; Conditions</label>', 'label'));
$assert('core/paragraph' === ($plainLabel['blockName'] ?? ''), 'plain-label-becomes-paragraph');
$assert('Terms &amp; Conditions' === ($plainLabel['attrs']['content'] ?? ''), 'plain-label-is-escaped');

$echoes = array();
$wrappedInput = $converter->convert($elementFrom('<label>Email<input value="a&amp;b" required></label>', 'label'));
$assert('Email: a&amp;b (required)' === ($wrappedInput['attrs']['content'] ?? ''), 'wrapped-input-becomes-readable-summary');
$assert(array( 'Email: a&b (required)' ) === $echoes, 'wrapped-input-registers-raw-echo');

$multiControl = $converter->convert($elementFrom('<label>Choices<input type="checkbox" value="A"><input type="checkbox" value="B"></label>', 'label'));
$assert('core/group' === ($multiControl['blockName'] ?? ''), 'multi-control-label-becomes-group');
$assert(2 === count($multiControl['innerBlocks'] ?? array()), 'multi-control-label-retains-each-summary');
$assert('presented' === ($multiControl['attrs']['className'] ?? ''), 'multi-control-label-retains-presentation');

$eventLabel = $converter->convert($elementFrom('<label>Email<input data-event></label>', 'label'));
$assert(null === $eventLabel, 'eventful-wrapped-control-declines-readable-block');

$recorded = array();
$runtimeLabel = $converter->convert($elementFrom('<label>Email<input data-runtime name="email"></label>', 'label'));
$assert('core/html' === ($runtimeLabel['blockName'] ?? ''), 'runtime-wrapped-control-preserves-label-markup');
$assert('runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'runtime-wrapped-control-records-island');

$recorded = array();
$search = $converter->convert($elementFrom('<input type="search" aria-label="Find">', 'input'));
$assert('core/html' === ($search['blockName'] ?? ''), 'search-input-is-preserved');
$assert(array() === $recorded, 'search-input-does-not-record-runtime-target');

$runtimeInput = $converter->convert($elementFrom('<input data-runtime name="email">', 'input'));
$assert('core/html' === ($runtimeInput['blockName'] ?? ''), 'runtime-input-is-preserved');
$assert('runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'runtime-input-records-island');

$styledInput = $converter->convert($elementFrom('<input data-styled type="range" value="5">', 'input'));
$assert(AuthoredInputBlockGenerator::NAME === ($styledInput['blockName'] ?? ''), 'styled-input-delegates-to-authored-converter');

$echoes = array();
$range = $converter->convert($elementFrom('<input type="range" aria-label="Volume" value="5" min="0" max="10" step="1" required>', 'input'));
$assert('core/paragraph' === ($range['blockName'] ?? ''), 'unstyled-range-becomes-readable-paragraph');
$assert('Volume: 5 (min 0, max 10, step 1) (required)' === ($range['attrs']['content'] ?? ''), 'range-summary-retains-value-and-bounds');
$assert(array( 'Volume: 5 (min 0, max 10, step 1) (required)' ) === $echoes, 'range-summary-registers-echo');

$echoes = array();
$wrappedSelect = $converter->convert($elementFrom('<label>Plan<select><option>Free</option><option selected>Pro</option></select></label>', 'label'));
$assert('Plan: Free, Pro (selected: Pro)' === ($wrappedSelect['attrs']['content'] ?? ''), 'wrapped-select-summary-retains-options-and-selection');
$assert(array( 'Plan: Free, Pro (selected: Pro)' ) === $echoes, 'wrapped-select-registers-echo');

$assert(null === $converter->convert($elementFrom('<div>Not a control</div>', 'div')), 'non-control-is-declined');
$assert(null === $converter->convert($elementFrom('<input data-event name="email">', 'input')), 'eventful-control-is-declined');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Readable form control block converter tests: ' . $assertions . " passed\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;

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

$registered = array();
$echoes = array();
$metadataBuilder = new FormControlMetadataBuilder(static fn (DOMElement $element): string => strtolower($element->tagName));
$converter = new AuthoredFormControlBlockConverter(
    $metadataBuilder,
    static fn (DOMElement $element): array => $element->hasAttribute('data-styled') ? array( 'display' => 'block' ) : array(),
    static fn (DOMElement $element): array => array( 'className' => 'presented' ),
    static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
        'blockName' => $name,
        'attrs' => $attrs,
        'innerBlocks' => $innerBlocks,
    ),
    static function (string $identity, array $definition) use (&$registered): void {
        $registered[$identity] = $definition;
    },
    static function (string $text) use (&$echoes): void {
        $echoes[] = $text;
    },
    static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    static fn (string $id): string => 'safe-' . $id
);

$plainInput = $elementFrom('<input name="email">', 'input');
$assert(null === $converter->input($plainInput), 'unstyled-input-declines-authored-block');
$assert(array() === $registered, 'unstyled-input-registers-no-generated-block');

$styledInput = $elementFrom('<input data-styled type="range" id="volume" value="4" min="1" max="9" step="2" required>', 'input');
$inputBlock = $converter->input($styledInput);
$assert(AuthoredInputBlockGenerator::NAME === ($inputBlock['blockName'] ?? ''), 'styled-input-uses-authored-input-block');
$assert('range' === ($inputBlock['attrs']['type'] ?? '') && true === ($inputBlock['attrs']['required'] ?? false), 'styled-input-retains-type-and-boolean-attributes');
$assert('<input type="range" id="volume" value="4" min="1" max="9" step="2" required>' === ($inputBlock['innerHTML'] ?? ''), 'styled-input-emits-native-markup');
$assert(isset($registered[AuthoredInputBlockGenerator::class]), 'styled-input-registers-generated-definition');

$echoes = array();
$plainSelect = $elementFrom('<select aria-label="Plan"><option value="basic">Basic</option><option selected>Pro &amp; Plus</option></select>', 'select');
$selectFallback = $converter->select($plainSelect);
$assert('core/group' === ($selectFallback['blockName'] ?? ''), 'unstyled-select-uses-readable-group');
$assert('presented' === ($selectFallback['attrs']['className'] ?? ''), 'unstyled-select-retains-presentation-attributes');
$assert('Plan' === ($selectFallback['innerBlocks'][0]['attrs']['content'] ?? ''), 'unstyled-select-emits-readable-label');
$assert('Pro &amp; Plus (selected)' === ($selectFallback['innerBlocks'][1]['innerBlocks'][1]['attrs']['content'] ?? ''), 'unstyled-select-marks-and-escapes-selected-option');
$assert(array( 'Plan', 'Basic', 'Pro & Plus (selected)' ) === $echoes, 'unstyled-select-registers-round-trip-echoes');

$echoes = array();
$styledSelect = $elementFrom('<select data-styled id="plan" name="plan"><option value="basic">Basic</option><option value="pro" selected>Pro</option></select>', 'select');
$selectBlock = $converter->select($styledSelect);
$authoredSelect = $selectBlock['innerBlocks'][0] ?? array();
$assert('safe-plan' === ($selectBlock['attrs']['anchor'] ?? ''), 'styled-select-retains-wrapper-anchor-contract');
$assert(AuthoredSelectBlockGenerator::NAME === ($authoredSelect['blockName'] ?? ''), 'styled-select-uses-authored-select-block');
$assert('Pro (selected)' === ($authoredSelect['attrs']['selectedSummary'] ?? ''), 'styled-select-retains-selected-summary');
$assert(str_contains((string) ($authoredSelect['innerHTML'] ?? ''), '<option value="pro" selected>Pro</option>'), 'styled-select-emits-native-option-markup');
$assert(isset($registered[AuthoredSelectBlockGenerator::class]), 'styled-select-registers-generated-definition');
$assert(array( 'plan' ) === $echoes, 'styled-select-registers-only-label-echo');

$echoes = array();
$emptySelect = $elementFrom('<select></select>', 'select');
$assert(null === $converter->select($emptySelect), 'select-without-options-declines-block');
$assert(array( 'Select option' ) === $echoes, 'select-without-options-still-registers-label-echo');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Authored form control block converter tests: ' . $assertions . " passed\n";

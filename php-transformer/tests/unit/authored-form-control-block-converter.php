<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredButtonBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredSelectBlockGenerator;
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

$elementFrom = static function (string $html, string $tagName): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName($tagName)->item(0);
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No ' . $tagName . ' parsed');
};

$registry = new GeneratedBlockRegistry('ssi-fixture');
$echoes = array();
$metadataBuilder = new FormControlMetadataBuilder(static fn (DOMElement $element): string => strtolower($element->tagName));
$converter = new AuthoredFormControlBlockConverter(
    $metadataBuilder,
    static fn (DOMElement $element): array => $element->hasAttribute('data-styled') ? array( 'display' => 'block' ) : array(),
    static fn (DOMElement $element): array => array( 'className' => 'presented' ),
    new SourceBlockCreatorFixture(static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
        'blockName' => $name,
        'attrs' => $attrs,
        'innerBlocks' => $innerBlocks,
    )),
    static fn (): GeneratedBlockRegistry => $registry,
    static function (string $text) use (&$echoes): void {
        $echoes[] = $text;
    },
    new Runtime(),
    static fn (string $id): string => 'safe-' . $id
);

$plainInput = $elementFrom('<input name="email">', 'input');
$assert(null === $converter->input($plainInput), 'unstyled-input-declines-authored-block');
$assert(array() === $registry->definitions(), 'unstyled-input-registers-no-generated-block');

$styledInput = $elementFrom('<input data-styled type="range" id="volume" value="4" min="1" max="9" step="2" required>', 'input');
$inputBlock = $converter->input($styledInput);
$assert($registry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($inputBlock['blockName'] ?? ''), 'styled-input-uses-authored-input-block');
$assert('range' === ($inputBlock['attrs']['type'] ?? '') && true === ($inputBlock['attrs']['required'] ?? false), 'styled-input-retains-type-and-boolean-attributes');
$assert('<input type="range" id="volume" value="4" min="1" max="9" step="2" required>' === ($inputBlock['innerHTML'] ?? ''), 'styled-input-emits-native-markup');
$assert($registry->has(AuthoredInputBlockGenerator::class), 'styled-input-registers-generated-definition');

$runtimeInput = $elementFrom('<label class="search">Search docs<input data-styled data-search data-wp-on--click="actions.search" type="search"></label>', 'input');
$runtimeLabel = $runtimeInput->parentNode;
$runtimeInputBlock = $converter->input($runtimeInput, $runtimeLabel instanceof DOMElement ? $runtimeLabel : null, true);
$assert(array( 'data-search' => '', 'data-styled' => '' ) === ($runtimeInputBlock['attrs']['dataAttributes'] ?? array()), 'runtime-input-retains-safe-data-attributes-only');
$assert('<label class="search">Search docs<input type="search" data-search="" data-styled=""></label>' === ($runtimeInputBlock['innerHTML'] ?? ''), 'runtime-input-retains-native-label-shell');

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
$assert($registry->blockName(AuthoredSelectBlockGenerator::LOCAL_NAME) === ($authoredSelect['blockName'] ?? ''), 'styled-select-uses-authored-select-block');
$assert('Pro (selected)' === ($authoredSelect['attrs']['selectedSummary'] ?? ''), 'styled-select-retains-selected-summary');
$assert(str_contains((string) ($authoredSelect['innerHTML'] ?? ''), '<option value="pro" selected>Pro</option>'), 'styled-select-emits-native-option-markup');
$assert($registry->has(AuthoredSelectBlockGenerator::class), 'styled-select-registers-generated-definition');
$assert(array( 'plan' ) === $echoes, 'styled-select-registers-only-label-echo');

$echoes = array();
$requiredSelect = $elementFrom('<label class="block">Assistance required<select data-styled class="mt-1.5 w-full" required><option value="" selected disabled>Select a program</option><option value="food">Food &amp; housing</option></select></label>', 'select');
$requiredLabel = $requiredSelect->parentNode instanceof DOMElement ? $requiredSelect->parentNode : null;
$requiredBlock = $converter->select($requiredSelect, false, $requiredLabel);
$authoredRequired = $requiredBlock['innerBlocks'][0] ?? array();
$assert(true === ($authoredRequired['attrs']['required'] ?? false), 'required-select-retains-required-attribute');
$assert('Assistance required' === ($authoredRequired['attrs']['label'] ?? ''), 'required-select-retains-wrapping-label');
$assert(
    str_contains((string) ($authoredRequired['innerHTML'] ?? ''), '<select class="mt-1.5 w-full" required>')
        && str_contains((string) ($authoredRequired['innerHTML'] ?? ''), '<option value="" selected disabled>Select a program</option>')
        && str_contains((string) ($authoredRequired['innerHTML'] ?? ''), '<option value="food">Food &amp; housing</option>'),
    'required-select-emits-required-placeholder-and-escaped-option-markup'
);

$echoes = array();
$emptySelect = $elementFrom('<select></select>', 'select');
$assert(null === $converter->select($emptySelect), 'select-without-options-declines-block');
$assert(array( 'Select option' ) === $echoes, 'select-without-options-still-registers-label-echo');

$plainButton = $elementFrom('<button type="submit">Send</button>', 'button');
$assert(null === $converter->button($plainButton), 'unstyled-button-declines-authored-block');
$forcedButton = $converter->button($plainButton, true);
$assert($registry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME) === ($forcedButton['blockName'] ?? ''), 'force-native-button-uses-authored-button-block');
$assert('<button type="submit">Send</button>' === ($forcedButton['innerHTML'] ?? ''), 'force-native-button-emits-native-markup');

$styledButton = $elementFrom('<button data-styled type="submit" class="send" style="letter-spacing:0.1em">Join &amp; Go</button>', 'button');
$buttonBlock = $converter->button($styledButton);
$assert($registry->blockName(AuthoredButtonBlockGenerator::LOCAL_NAME) === ($buttonBlock['blockName'] ?? ''), 'styled-button-uses-authored-button-block');
$assert('submit' === ($buttonBlock['attrs']['type'] ?? '') && 'send' === ($buttonBlock['attrs']['className'] ?? '') && 'letter-spacing:0.1em' === ($buttonBlock['attrs']['style'] ?? ''), 'styled-button-retains-type-class-and-inline-style');
$assert('<button type="submit" class="send" style="letter-spacing:0.1em">Join &amp; Go</button>' === ($buttonBlock['innerHTML'] ?? ''), 'styled-button-emits-native-markup');
$assert($registry->has(AuthoredButtonBlockGenerator::class), 'styled-button-registers-generated-definition');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Authored form control block converter tests: ' . $assertions . " passed\n";

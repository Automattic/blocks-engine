<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\RuntimeDomTargetsFixture;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\AuthoredFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeIslandRecorder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ReadableFormControlBlockConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
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

$elementFrom = static function (string $html, string $tagName): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName($tagName)->item(0);
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No ' . $tagName . ' parsed');
};

$createBlock = new SourceBlockCreatorFixture(static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
    'blockName' => $name,
    'attrs' => $attrs,
    'innerBlocks' => $innerBlocks,
));
$presentation = new ElementPresentationResolverFixture(static fn (DOMElement $element): array => array( 'className' => 'presented' ));
$echoStart = count($session->transformationEvidenceState()->formControlEchoTexts());
$recorded = array();
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
    static fn (DOMElement $element): DOMElement => $element
);
$converter = new ReadableFormControlBlockConverter(
    $metadataBuilder,
    $authoredConverter,
    $runtimeRecorder,
    new Runtime(),
    new RuntimeDomTargetsFixture(static fn (DOMElement $element): bool => $element->hasAttribute('data-runtime')),
    $presentation,
    $createBlock,
    $session
);

$plainLabel = $converter->convert($elementFrom('<label>Terms &amp; Conditions</label>', 'label'));
$assert('core/paragraph' === ($plainLabel['blockName'] ?? ''), 'plain-label-becomes-paragraph');
$assert('Terms &amp; Conditions' === ($plainLabel['attrs']['content'] ?? ''), 'plain-label-is-escaped');

$echoStart = count($session->transformationEvidenceState()->formControlEchoTexts());
$wrappedInput = $converter->convert($elementFrom('<label>Email<input value="a&amp;b" required></label>', 'label'));
$assert('Email: a&amp;b (required)' === ($wrappedInput['attrs']['content'] ?? ''), 'wrapped-input-becomes-readable-summary');
$assert(array( 'Email: a&b (required)' ) === array_slice($session->transformationEvidenceState()->formControlEchoTexts(), $echoStart), 'wrapped-input-registers-raw-echo');

$multiControl = $converter->convert($elementFrom('<label>Choices<input type="checkbox" value="A"><input type="checkbox" value="B"></label>', 'label'));
$assert('core/group' === ($multiControl['blockName'] ?? ''), 'multi-control-label-becomes-group');
$assert(2 === count($multiControl['innerBlocks'] ?? array()), 'multi-control-label-retains-each-summary');
$assert('presented' === ($multiControl['attrs']['className'] ?? ''), 'multi-control-label-retains-presentation');

$eventLabel = $converter->convert($elementFrom('<label>Email<input onchange="handle()"></label>', 'label'));
$assert(null === $eventLabel, 'eventful-wrapped-control-declines-readable-block');

$recorded = array();
$runtimeLabel = $converter->convert($elementFrom('<label>Email<input data-runtime name="email"></label>', 'label'));
$assert('core/html' === ($runtimeLabel['blockName'] ?? ''), 'runtime-wrapped-control-preserves-label-markup');
$assert('runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'runtime-wrapped-control-records-island');

$recorded = array();
$styledRuntimeLabel = $converter->convert($elementFrom('<label class="search">Search docs<input data-styled data-runtime data-search type="search"></label>', 'label'));
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($styledRuntimeLabel['blockName'] ?? ''), 'styled-runtime-wrapped-control-uses-authored-input');
$assert('Search docs' === ($styledRuntimeLabel['attrs']['label'] ?? ''), 'styled-runtime-wrapped-control-retains-label');
$assert(array( 'data-runtime' => '', 'data-search' => '', 'data-styled' => '' ) === ($styledRuntimeLabel['attrs']['dataAttributes'] ?? array()), 'styled-runtime-wrapped-control-retains-data-selectors');
$assert('runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'styled-runtime-wrapped-control-records-island');

$recorded = array();
$search = $converter->convert($elementFrom('<input type="search" aria-label="Find">', 'input'));
$assert('core/html' === ($search['blockName'] ?? ''), 'search-input-is-preserved');
$assert(array() === $recorded, 'search-input-does-not-record-runtime-target');

$runtimeInput = $converter->convert($elementFrom('<input data-runtime name="email">', 'input'));
$assert('core/html' === ($runtimeInput['blockName'] ?? ''), 'runtime-input-is-preserved');
$assert('runtime_dom_target' === ($recorded[0]['reason'] ?? ''), 'runtime-input-records-island');

$styledInput = $converter->convert($elementFrom('<input data-styled type="range" value="5">', 'input'));
$assert($authoredRegistry->blockName(AuthoredInputBlockGenerator::LOCAL_NAME) === ($styledInput['blockName'] ?? ''), 'styled-input-delegates-to-authored-converter');

$echoStart = count($session->transformationEvidenceState()->formControlEchoTexts());
$range = $converter->convert($elementFrom('<input type="range" aria-label="Volume" value="5" min="0" max="10" step="1" required>', 'input'));
$assert('core/paragraph' === ($range['blockName'] ?? ''), 'unstyled-range-becomes-readable-paragraph');
$assert('Volume: 5 (min 0, max 10, step 1) (required)' === ($range['attrs']['content'] ?? ''), 'range-summary-retains-value-and-bounds');
$assert(array( 'Volume: 5 (min 0, max 10, step 1) (required)' ) === array_slice($session->transformationEvidenceState()->formControlEchoTexts(), $echoStart), 'range-summary-registers-echo');

$echoStart = count($session->transformationEvidenceState()->formControlEchoTexts());
$wrappedSelect = $converter->convert($elementFrom('<label>Plan<select><option>Free</option><option selected>Pro</option></select></label>', 'label'));
$assert('Plan: Free, Pro (selected: Pro)' === ($wrappedSelect['attrs']['content'] ?? ''), 'wrapped-select-summary-retains-options-and-selection');
$assert(array( 'Plan: Free, Pro (selected: Pro)' ) === array_slice($session->transformationEvidenceState()->formControlEchoTexts(), $echoStart), 'wrapped-select-registers-echo');

$legend = $converter->convert($elementFrom('<fieldset><legend>Your details</legend></fieldset>', 'legend'));
$assert('core/paragraph' === ($legend['blockName'] ?? ''), 'group-caption-becomes-paragraph');
$assert('Your details' === ($legend['attrs']['content'] ?? ''), 'group-caption-retains-its-text');

$assert(null === $converter->convert($elementFrom('<div>Not a control</div>', 'div')), 'non-control-is-declined');
$assert(null === $converter->convert($elementFrom('<input onchange="handle()" name="email">', 'input')), 'eventful-control-is-declined');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Readable form control block converter tests: ' . $assertions . " passed\n";

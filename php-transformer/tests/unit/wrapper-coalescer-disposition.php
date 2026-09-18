<?php
declare(strict_types=1);

/**
 * WrapperCoalescer, exercised without an HtmlCompilation (#1935).
 *
 * coalescedSingleGroupWrapper() used to gate on one multi-term boolean
 * expression that returned a bare `null` on disqualification. Its
 * replacement, coalescingDisposition(), names each disqualification reason.
 * This file asserts specific reasons directly — the "individually testable"
 * half of the fix — the way runtime-island-analyzer.php and
 * element-conversion-prelude.php already test their collaborators without a
 * full document transform.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PseudoFormAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperCoalescer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$elementFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No element parsed');
};

/**
 * @param array<int,string> $runtimeDomSelectors
 * @param array<string, Closure> $overrides
 */
$makeCoalescer = static function (array $runtimeDomSelectors = array(), array $overrides = array()) use ($elementFrom): WrapperCoalescer {
    $runtime = new Runtime();
    $session = new HtmlTransformerSession($runtime, static fn (DOMElement $element): array => array());

    $selectorState = new RuntimeSelectorState(array_fill_keys($runtimeDomSelectors, true), array_fill_keys($runtimeDomSelectors, true), array());
    $session->installRuntimeSelectorState($selectorState);

    $sourceDocument = new DOMDocument();
    $sourceDocument->loadHTML('<?xml encoding="utf-8" ?><body></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $sourceBody = $sourceDocument->getElementsByTagName('body')->item(0);
    if ( ! $sourceBody instanceof DOMElement ) {
        throw new RuntimeException('No source body parsed');
    }
    $session->installAuthorStyleAnalysis(new AuthorStyleAnalysis('', '', array(), $sourceBody));
    $session->installLayoutGeometryState(new LayoutGeometryState());

    $sourceElementClassifier = new SourceElementClassifier();
    $metadataBuilder = new FormControlMetadataBuilder(static fn (DOMElement $e): string => strtolower($e->tagName));
    $pseudoFormAnalyzer = new PseudoFormAnalyzer($metadataBuilder, static fn (DOMElement $e): string => strtolower($e->tagName));
    $runtimeIslands = new RuntimeIslandAnalyzer(new RuntimeIslandContext(
        $session,
        $sourceElementClassifier,
        static function (DOMElement $element): array {
            $out = array();
            foreach ( $element->getElementsByTagName('*') as $node ) {
                if ( $node instanceof DOMElement ) {
                    $out[] = $node;
                }
            }
            return $out;
        },
        static fn (DOMElement $element): array => array(),
        static fn (string $html): ?DOMElement => null,
        static fn (DOMElement $element): bool => false
    ), $pseudoFormAnalyzer);

    $styleResolver = new StyleResolver(
        new StyleResolutionContext(
            $session,
            static fn (DOMElement $element): int => 0,
            static fn (string $value): string => $value,
            static fn (string $selector): array => array(),
            static fn (string $className): string => $className,
            static fn (string $url): string => $url,
            static fn (DOMElement $element): bool => false
        ),
        new HtmlTransformerAnalysisCache()
    );

    $createBlock = new SourceBlockCreatorFixture(static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array_filter(array(
        'blockName' => $name,
        'attrs' => $attrs,
        'innerBlocks' => $innerBlocks,
    ), static fn (mixed $v): bool => array() !== $v));

    // Registered so `_source_provenance_id => 1` resolves to a digest that
    // matches `WrapperCoalescer`'s real `SourceDom::safeFallbackHtml()` call
    // against the `<p>Copy</p>` child every test wrapper below uses, letting
    // `sameSourceGroupChainLeaf()`'s real digest walk find the DOM child.
    $session->transformationProvenanceState()->registerSource(array('source_digest' => hash('sha256', SourceDom::safeFallbackHtml($elementFrom('<p>Copy</p>')))), false);

    $defaults = array(
        'structureSignals' => static fn (DOMElement $e): array => array(),
        'soleElementChild' => static fn (DOMElement $e): ?DOMElement => $e->firstElementChild,
        'isImageOnlyAnchor' => static fn (DOMElement $e): bool => false,
    );
    $c = array_merge($defaults, $overrides);

    return new WrapperCoalescer(
        $sourceElementClassifier,
        $runtimeIslands,
        $styleResolver,
        $createBlock,
        $session,
        $c['structureSignals'],
        $c['soleElementChild'],
        $c['isImageOnlyAnchor']
    );
};

// A plain single-child div wrapper with no identity of its own coalesces into
// its child, and the reason names the successful path rather than a bare
// non-null return.
$plain = $makeCoalescer();
$wrapper = $elementFrom('<div><p>Copy</p></div>');
$childBlock = array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), '_source_provenance_id' => 1);
$disposition = $plain->coalescingDisposition($wrapper, $childBlock);
$assert($disposition->isCoalesce(), 'plain-wrapper-coalesces');
$assert('single_child_wrapper_absorbed' === $disposition->reason, 'plain-wrapper-reason-names-success', $disposition->reason);
$built = $plain->coalescedSingleGroupWrapper($wrapper, array('blockName' => 'core/group', 'attrs' => array('className' => 'child'), 'innerBlocks' => array(), '_source_provenance_id' => 1));
$assert('core/group' === ($built['blockName'] ?? null), 'plain-wrapper-builds-child-block');

// Each of the following holds every other check permissive and flips exactly
// one authored signal, so the resulting disposition names that signal.
$span = $elementFrom('<span><p>Copy</p></span>');
$reason = $makeCoalescer()->coalescingDisposition($span, array('blockName' => 'core/group'))->reason;
$assert('not_a_div_element' === $reason, 'non-div-wrapper-names-tag-reason', $reason);

$unsupportedChild = $makeCoalescer()->coalescingDisposition($elementFrom('<div><p>Copy</p></div>'), array('blockName' => 'core/paragraph'))->reason;
$assert('unsupported_child_block_name' === $unsupportedChild, 'unsupported-child-block-names-reason', $unsupportedChild);

$runtimeTargetWrapper = $elementFrom('<div class="mount"><p>Copy</p></div>');
$runtimeReason = $makeCoalescer(array('.mount'))->coalescingDisposition($runtimeTargetWrapper, array('blockName' => 'core/group'))->reason;
$assert('runtime_dom_target' === $runtimeReason, 'runtime-dom-target-names-reason', $runtimeReason);

$idWrapper = $elementFrom('<div id="hero"><p>Copy</p></div>');
$idReason = $makeCoalescer()->coalescingDisposition($idWrapper, array('blockName' => 'core/group'))->reason;
$assert('has_id_attribute' === $idReason, 'id-attribute-names-reason', $idReason);

$roleWrapper = $elementFrom('<div role="region"><p>Copy</p></div>');
$roleReason = $makeCoalescer()->coalescingDisposition($roleWrapper, array('blockName' => 'core/group'))->reason;
$assert('has_role_attribute' === $roleReason, 'role-attribute-names-reason', $roleReason);

$interactiveWrapper = $elementFrom('<div tabindex="0"><p>Copy</p></div>');
$interactiveReason = $makeCoalescer()->coalescingDisposition($interactiveWrapper, array('blockName' => 'core/group'))->reason;
$assert('has_interactive_attributes' === $interactiveReason, 'interactive-attribute-names-reason', $interactiveReason);

$dataWrapper = $elementFrom('<div data-state="open"><p>Copy</p></div>');
$dataReason = $makeCoalescer()->coalescingDisposition($dataWrapper, array('blockName' => 'core/group'))->reason;
$assert('has_data_attributes_without_proof' === $dataReason, 'data-attribute-names-reason', $dataReason);

$structureSignalReason = $makeCoalescer(array(), array(
    'structureSignals' => static fn (DOMElement $e): array => array('card_like' => true),
))->coalescingDisposition($elementFrom('<div class="card"><p>Copy</p></div>'), array('blockName' => 'core/group'))->reason;
$assert('has_structure_signals_without_proof' === $structureSignalReason, 'structure-signal-names-reason', $structureSignalReason);

// No `_source_provenance_id` and not a `core/image` child, so
// `matchingSourceChild()` never has a leaf to find — no override needed.
$missingChildReason = $makeCoalescer()->coalescingDisposition($elementFrom('<div><p>Copy</p></div>'), array('blockName' => 'core/group'))->reason;
$assert('missing_matching_source_child' === $missingChildReason, 'missing-source-child-names-reason', $missingChildReason);

$motionTokenReason = $makeCoalescer()->coalescingDisposition($elementFrom('<div class="slider"><p>Copy</p></div>'), array('blockName' => 'core/group'))->reason;
$assert('has_motion_structure_token' === $motionTokenReason, 'motion-structure-token-names-reason', $motionTokenReason);

if ( array() !== $failures ) {
    foreach ( $failures as $failure ) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    fwrite(STDERR, count($failures) . " wrapper coalescer disposition test(s) failed\n");
    exit(1);
}

echo 'Wrapper coalescer disposition tests: ' . $assertions . " passed\n";

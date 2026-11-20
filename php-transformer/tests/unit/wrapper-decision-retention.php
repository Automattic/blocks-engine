<?php
declare(strict_types=1);

/**
 * WrapperDecision (#7471) owns the inert-scaffolding drop table and the
 * wrapper / empty-visual retention tables that InertScaffoldingSuppressor
 * and EmptyVisualRetentionPolicy used to decide locally. This pins the
 * inventory (names, stages, order) and that a drop names its guard.
 * Output parity is pinned by inert-capture-scaffolding.php and the empty
 * visual tests, which run through the full transform.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\InertScaffoldingSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PseudoFormAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\WrapperDecision;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

// Inventory: every table's guards belong to a known stage, in a pinned order.
$tables = WrapperDecision::retentionGuardStages();
$assert(array( 'inert_scaffolding', 'wrapper_retention', 'empty_visual_retention' ) === array_keys($tables), 'retention-tables');
$assert(8 === count($tables['inert_scaffolding']), 'inert-scaffolding-guard-count', (string) count($tables['inert_scaffolding']));
$assert(9 === count($tables['wrapper_retention']), 'wrapper-retention-guard-count', (string) count($tables['wrapper_retention']));
$assert(10 === count($tables['empty_visual_retention']), 'empty-visual-retention-step-count', (string) count($tables['empty_visual_retention']));
foreach ( $tables as $table => $guards ) {
    $assert(array() === array_diff(array_unique(array_values($guards)), WrapperDecision::STAGES), $table . '-guards-belong-to-known-stages');
}

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><div id="root"></div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$body = $document->getElementsByTagName('body')->item(0);
if ( ! $body instanceof DOMElement ) {
    throw new RuntimeException('No body parsed');
}
$elementFrom = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><body><main>' . $html . '</main></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $doc->getElementsByTagName('main')->item(0)?->firstElementChild;
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No element parsed');
};

$description = new SourceElementDescription(new SourceElementClassifier(), new CommerceStructureRecognizer());
$session = new HtmlTransformerSession(new Runtime(), $description);
$session->installRuntimeSelectorState(new RuntimeSelectorState(array(), array(), array()));
$session->installAuthorStyleAnalysis(new AuthorStyleAnalysis('', '', array(), $body));
$session->installLayoutGeometryState(new LayoutGeometryState());
$classifier = new SourceElementClassifier();
$runtimeIslands = new RuntimeIslandAnalyzer($session, $classifier, new PseudoFormAnalyzer(new FormControlMetadataBuilder()));
$styleResolver = new StyleResolver(
    $session,
    new StyleResolutionContext(
        static fn (string $selector): array => array(),
        static fn (string $className): string => $className,
        static fn (string $url): string => $url
    ),
    new HtmlTransformerAnalysisCache()
);
$suppressor = new InertScaffoldingSuppressor(
    $styleResolver,
    $runtimeIslands,
    $classifier,
    static fn (DOMElement $element): ?DOMElement => $element->firstElementChild,
    static fn (DOMElement $element): bool => false
);

$cases = array(
    array('<p ' . CaptureEvidence::DEVICE_UNAVAILABLE . '="1">Notice</p>', 'capture_device_unavailable_notice'),
    array('<dialog></dialog>', 'closed_empty_dialog'),
    array('<div></div>', 'inert_empty_container'),
    array('<div><p>Copy</p></div>', null),
    array('<dialog open></dialog>', null),
);
foreach ( $cases as [$html, $expected] ) {
    $element = $elementFrom($html);
    $disposition = WrapperDecision::inertScaffolding($suppressor, $element, strtolower($element->tagName));
    $fallbacks = array();
    $handled = $suppressor->convert($element, strtolower($element->tagName), $fallbacks)->handled;
    if ( null === $expected ) {
        $assert(null === $disposition && ! $handled, 'not-scaffolding: ' . $html, (string) $disposition?->reason);
        continue;
    }
    $assert(null !== $disposition && $disposition->isDrop() && $expected === $disposition->reason, 'drops-' . $expected, (string) $disposition?->reason);
    $assert(($tables['inert_scaffolding'][$expected] ?? null) === $disposition?->stage, 'stage-matches-inventory-' . $expected);
    $assert($handled, 'converter-reads-disposition-' . $expected);
}

if ( array() !== $failures ) {
    foreach ( $failures as $failure ) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    fwrite(STDERR, count($failures) . " wrapper decision retention test(s) failed\n");
    exit(1);
}

echo 'Wrapper decision retention tests: ' . $assertions . " passed\n";

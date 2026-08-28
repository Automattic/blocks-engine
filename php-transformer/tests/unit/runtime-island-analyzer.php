<?php
declare(strict_types=1);

/**
 * RuntimeIslandAnalyzer, exercised without an HtmlTransformer.
 *
 * While this was `RuntimeIslandTrait` no such test was possible: every method
 * resolved against the transformer's `$this`, so asserting "an id matching a
 * behavioral runtime selector is a DOM target, but the same id matching only a
 * presentational animation selector is not" required transforming a document
 * with the right script evidence and stylesheet attached.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeDomState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$elementFrom = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $node) {
        if ($node instanceof DOMElement) {
            return $node;
        }
    }
    throw new RuntimeException('No element parsed');
};

$descendants = static function (DOMElement $element): array {
    $out = array();
    foreach ($element->getElementsByTagName('*') as $node) {
        if ($node instanceof DOMElement) {
            $out[] = $node;
        }
    }
    return $out;
};

/**
 * @param array<int,string> $domSelectors
 * @param array<int,string> $presentationalSelectors
 */
$makeAnalyzer = static function (array $domSelectors = array(), array $presentationalSelectors = array(), array $overrides = array()) use ($descendants): RuntimeIslandAnalyzer {
    // Behavioral evidence mirrors the DOM selectors unless a selector is
    // declared presentational-only, which is exactly the distinction
    // isPresentationalRuntimeSelector() exists to make.
    $behavioral = array();
    foreach ($domSelectors as $selector) {
        if ( ! in_array($selector, $presentationalSelectors, true) ) {
            $behavioral[$selector] = true;
        }
    }
    $selectors = new RuntimeSelectorState(
        array_fill_keys($domSelectors, true),
        $behavioral,
        array()
    );

    $defaults = array(
        'fallbackEmitter'  => static fn () => throw new RuntimeException('fallbackEmitter not expected in this test'),
        'runtimeDom'       => static fn (): RuntimeDomState => new RuntimeDomState(),
        'runtimeSelectors' => static fn (): RuntimeSelectorState => $selectors,
        'attr'             => static fn (DOMElement $e, string $n): string => $e->getAttribute($n),
        'descendants'      => $descendants,
        'islandSelector'   => static fn (DOMElement $e): string => strtolower($e->tagName),
        'eventMetadata'    => static fn (DOMElement $e): array => array(),
        'requiredScripts'  => static fn (DOMElement $e): array => array(),
        'preservedRoot'    => static fn (string $h): ?DOMElement => null,
        'formHasEntry'     => static fn (DOMElement $e): bool => false,
        'hasFormAncestor'  => static fn (DOMElement $e): bool => false,
        'hasWorkspace'     => static fn (DOMElement $e): bool => false,
        'isPseudoForm'     => static fn (DOMElement $e): bool => false,
        'isFormControl'    => static fn (DOMElement $e): bool => in_array(strtolower($e->tagName), array('input', 'select', 'textarea', 'button'), true),
        'isInline'         => static fn (string $t): bool => in_array($t, array('span', 'em', 'strong', 'a'), true),
        'isPresentational' => static fn (string $s): bool => in_array($s, $presentationalSelectors, true),
        'dedupe'           => static fn (array $rows): array => array_values($rows),
    );
    $c = array_merge($defaults, $overrides);

    return new RuntimeIslandAnalyzer(new RuntimeIslandContext(
        $c['fallbackEmitter'],
        $c['runtimeDom'],
        $c['runtimeSelectors'],
        $c['attr'],
        $c['descendants'],
        $c['islandSelector'],
        $c['eventMetadata'],
        $c['requiredScripts'],
        $c['preservedRoot'],
        $c['formHasEntry'],
        $c['hasFormAncestor'],
        $c['hasWorkspace'],
        $c['isPseudoForm'],
        $c['isFormControl'],
        $c['isInline'],
        $c['isPresentational'],
        $c['dedupe']
    ));
};

// An id or class named by a runtime selector is a DOM target.
$byId = $makeAnalyzer(array('#mount'));
$assert($byId->isRuntimeDomTarget($elementFrom('<div id="mount"></div>')), 'id-selector-is-runtime-target');
$assert(! $byId->isRuntimeDomTarget($elementFrom('<div id="other"></div>')), 'unlisted-id-is-not-runtime-target');

$byClass = $makeAnalyzer(array('.widget'));
$assert($byClass->isRuntimeDomTarget($elementFrom('<div class="a widget b"></div>')), 'class-selector-is-runtime-target');

// A selector that is only presentational animation evidence does not make the
// element a runtime target. This is the distinction that keeps CSS-animated
// decoration out of runtime-island preservation.
$presentational = $makeAnalyzer(array('#mount'), array('#mount'));
$assert(! $presentational->isRuntimeDomTarget($elementFrom('<div id="mount"></div>')), 'presentational-only-selector-is-not-runtime-target');

// Bounded selector grammar: shapes the analyzer will accept as runtime evidence.
$grammar = $makeAnalyzer();
foreach (array('#app', '.panel', 'div.panel', '[data-widget]', 'div[data-widget]', 'canvas', 'svg', 'button') as $ok) {
    $assert($grammar->isBoundedRuntimeSelector($ok), 'bounded-selector-' . $ok);
}
foreach (array('div > span', '*', 'div:hover', '', 'div p') as $bad) {
    $assert(! $grammar->isBoundedRuntimeSelector($bad), 'unbounded-selector-rejected-' . ($bad === '' ? 'empty' : $bad));
}

// Attribute-selector matching drives data-attribute preservation, but never for
// canvas, form, script, or form controls.
$dataAttr = $makeAnalyzer(array('[data-widget]'));
$assert($dataAttr->shouldPreserveDataAttributeRuntimeTarget($elementFrom('<div data-widget="1"></div>')), 'data-attribute-target-preserved');
$assert(! $dataAttr->shouldPreserveDataAttributeRuntimeTarget($elementFrom('<canvas data-widget="1"></canvas>')), 'canvas-excluded-from-data-attribute-preservation');
$assert(! $dataAttr->shouldPreserveDataAttributeRuntimeTarget($elementFrom('<form data-widget="1"></form>')), 'form-excluded-from-data-attribute-preservation');
$assert(! $dataAttr->shouldPreserveDataAttributeRuntimeTarget($elementFrom('<input data-widget="1">')), 'form-control-excluded-from-data-attribute-preservation');

// Bounded attribute capture is truncated and keeps data-* alongside identity.
$attrs = $makeAnalyzer()->boundedRuntimeTargetAttributes($elementFrom('<div id="i" class="c" role="tab" data-k="' . str_repeat('x', 300) . '"></div>'));
$assert('i' === ($attrs['id'] ?? ''), 'bounded-attributes-keep-id');
$assert('tab' === ($attrs['role'] ?? ''), 'bounded-attributes-keep-role');
$assert(160 === strlen($attrs['data-k'] ?? ''), 'bounded-attributes-truncate-to-160');

// An app-shell root needs both multiple runtime targets and a root token; a
// generic wrapper with targets but no token is not a shell.
$shell = $makeAnalyzer(array('.target'));
$assert(
    ! $shell->shouldPreserveRuntimeAppShell($elementFrom('<div class="wrapper"><span class="target"></span><span class="target"></span></div>')),
    'targets-without-app-root-token-are-not-a-shell'
);
$assert(
    $shell->shouldPreserveRuntimeAppShell($elementFrom('<div id="workspace"><span class="target"></span><span class="target"></span></div>')),
    'app-root-token-with-targets-is-a-shell'
);
$assert(
    ! $shell->shouldPreserveRuntimeAppShell($elementFrom('<div id="workspace"><span class="target"></span></div>')),
    'single-target-is-not-a-shell'
);

// A global shell landmark is never treated as a runtime app shell.
$assert(
    ! $shell->shouldPreserveRuntimeAppShell($elementFrom('<header id="workspace"><span class="target"></span><span class="target"></span></header>')),
    'global-shell-landmark-is-not-a-runtime-app-shell'
);

// Native retention is limited to semantic wrappers with no interactive descendants.
$retain = $makeAnalyzer();
$assert($retain->canRetainRuntimeDomContractNatively($elementFrom('<section><p>text</p></section>'), 'core/group'), 'semantic-wrapper-retains-natively');
$assert(! $retain->canRetainRuntimeDomContractNatively($elementFrom('<div><p>text</p></div>'), 'core/group'), 'generic-div-does-not-retain-natively');
$assert(! $retain->canRetainRuntimeDomContractNatively($elementFrom('<section><button>go</button></section>'), 'core/group'), 'interactive-descendant-blocks-native-retention');
$assert(! $retain->canRetainRuntimeDomContractNatively($elementFrom('<section><p>t</p></section>'), 'core/image'), 'unsupported-block-name-does-not-retain');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Runtime island analyzer tests: ' . $assertions . " passed\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormFallbackFindingBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormFallbackFindingContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormSuccessPanelMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PseudoFormAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
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

$selector = static fn (DOMElement $element): string => '#' . ($element->getAttribute('id') ?: strtolower($element->tagName));
$metadataBuilder = new FormControlMetadataBuilder(
    $selector,
    static fn (DOMElement $element): array => 'button' === strtolower($element->tagName)
        ? array( 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '11px', 'bottom' => '11px' ) ) ) )
        : array()
);
$successBuilder = new FormSuccessPanelMetadataBuilder(
    $selector,
    static fn (DOMElement $element): array => array( 'html' => '<aside>Thanks</aside>', 'bytes' => 21, 'truncated' => false ),
    static fn (DOMElement $element): string => $element->textContent ?? ''
);
$pseudoAnalyzer = new PseudoFormAnalyzer($metadataBuilder, $selector);
$bindingCalls = array();
$sourceDocument = new DOMDocument();
$sourceDocument->loadHTML('<?xml encoding="utf-8" ?><body></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$sourceBody = $sourceDocument->getElementsByTagName('body')->item(0);
if ( ! $sourceBody instanceof DOMElement ) {
    throw new RuntimeException('No source body parsed');
}
$session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $element): array => array());
$session->installAuthorStyleAnalysis(new AuthorStyleAnalysis('', '', array(), $sourceBody));
$session->transformationProvenanceState()->installFallback(array( 'provenance' => 'fixture' ));
$context = new FormFallbackFindingContext(
    $session,
    static fn (DOMElement $element): array => array( 'html' => '<safe-form>', 'bytes' => 11, 'truncated' => true ),
    static fn (DOMElement $element): array => array( '#existing-runtime' ),
    static fn (DOMElement $element): array => array( 'source' => 'fixture' ),
    static fn (DOMElement $element): array => array( 'kind' => 'interactive' ),
    static function (array $block, string $role, array $selectors, ?DOMElement $anchorElement = null) use (&$bindingCalls, $selector): array {
        $anchor = null !== $anchorElement ? $selector($anchorElement) : null;
        $bindingCalls[] = compact('block', 'role', 'selectors', 'anchor');
        return array_filter(
            array( 'role' => $role, 'selectors' => $selectors, 'blockName' => $block['blockName'] ?? '', 'anchor' => $anchor ),
            static fn (mixed $value): bool => null !== $value
        );
    }
);
$builder = new FormFallbackFindingBuilder($context, $metadataBuilder, $successBuilder, $pseudoAnalyzer);

$readable = array( 'blockName' => 'core/group' );
$form = $elementFrom('<form id="signup" action="/join"><label>Email<input name="email" required></label><button type="submit">Join</button></form>');
$finding = $builder->build($form, $readable);
$assert('html_form_fallback' === ($finding['diagnostic_code'] ?? ''), 'diagnostic-code');
$assert('form_requires_runtime' === ($finding['reason'] ?? ''), 'runtime-reason');
$assert('form' === ($finding['tag'] ?? ''), 'real-form-tag');
$assert('form:nth-of-type(1)' === ($finding['selector'] ?? ''), 'element-selector');
$assert('/join' === ($finding['form']['action'] ?? ''), 'form-metadata');
$assert(2 === ($finding['control_count'] ?? 0), 'control-count');
$assert(2 === count($finding['controls'] ?? array()), 'controls-metadata');
$assert('11px' === ($finding['controls'][1]['presentation']['style']['spacing']['padding']['top'] ?? ''), 'submit-presentation-metadata');
$classRichForm = $elementFrom('<form><input name="email"><button class="button base size hover wrap provider upgrade responsive typography width extra retained eleven twelve thirteen fourteen fifteen discarded overflow" type="submit">Join</button></form>');
$classRichFinding = $builder->build($classRichForm, $readable);
$assert('button base size hover wrap provider upgrade responsive typography width extra retained eleven twelve thirteen fourteen' === ($classRichFinding['controls'][1]['class'] ?? ''), 'submit-presentation-retains-bounded-functional-classes');
$assert(array( $readable ) === ($finding['readable_blocks'] ?? array()), 'readable-blocks');
$assert('form' === ($finding['binding']['role'] ?? ''), 'readable-block-defaults-to-binding');
$assert(array( '#existing-runtime' ) === ($finding['binding']['selectors'] ?? array()), 'default-binding-retains-runtime-selectors');
// A real `<form>` is replaced by the block it binds, so it never needs a
// separate source-element anchor. See #700 for real-form scope.
$assert(! isset($finding['binding']['anchor']), 'real-form-binding-has-no-source-element-anchor');
$assert('<safe-form>' === ($finding['html'] ?? ''), 'bounded-html');
$assert(11 === ($finding['html_bytes'] ?? 0) && true === ($finding['html_truncated'] ?? false), 'bounded-html-metadata');
$assert(array( 'source' => 'fixture' ) === ($finding['context'] ?? array()), 'source-context');
$assert('fixture' === ($finding['provenance'] ?? ''), 'diagnostic-builder');
$assert(! isset($finding['form_boundary']), 'real-form-has-no-pseudo-boundary');

$bindingCalls = array();
$preserved = array( 'blockName' => 'core/html' );
$replacement = $builder->build($form, $readable, $preserved);
$assert('core/html' === ($replacement['binding']['blockName'] ?? ''), 'explicit-binding-is-used');
$assert(array( '#existing-runtime', '#signup' ) === ($replacement['binding']['selectors'] ?? array()), 'replacement-binding-supersedes-form-island');
$assert(! isset($replacement['binding']['anchor']), 'real-form-replacement-binding-has-no-source-element-anchor');

// A div pseudo-form keeps its own converted subtree in the page, so its
// binding anchors on that source element instead of on a block the page
// never emits. See #718.
$pseudo = $elementFrom('<div id="signup-shell"><input name="email"><button>Join</button></div>');
$bindingCalls = array();
$pseudoFinding = $builder->build($pseudo, null);
$assert('div' === ($pseudoFinding['tag'] ?? ''), 'pseudo-form-tag');
$assert(isset($pseudoFinding['form_boundary']), 'pseudo-form-boundary');
$assert(array() === ($pseudoFinding['readable_blocks'] ?? null), 'null-readable-blocks');
$assert('form' === ($pseudoFinding['binding']['role'] ?? ''), 'pseudo-form-retains-binding');
$assert('#signup-shell' === ($pseudoFinding['binding']['anchor'] ?? ''), 'pseudo-form-binding-anchors-on-source-element');
$assert(1 === count($bindingCalls) && array() === ($bindingCalls[0]['block'] ?? null), 'pseudo-form-binding-does-not-synthesize-an-unemitted-block');

$pseudoWithReadable = $builder->build($pseudo, $readable);
$assert('#signup-shell' === ($pseudoWithReadable['binding']['anchor'] ?? ''), 'pseudo-form-binds-source-element-not-the-synthesized-readable-block');
$assert(array( $readable ) === ($pseudoWithReadable['readable_blocks'] ?? array()), 'pseudo-form-readable-output-unchanged');

// An explicit replacement block is emitted by the page, so it still anchors on
// itself even when the source element is not a real `<form>`.
$pseudoReplacement = $builder->build($pseudo, $readable, $preserved);
$assert('core/html' === ($pseudoReplacement['binding']['blockName'] ?? ''), 'pseudo-form-replacement-block-is-used');
$assert(! isset($pseudoReplacement['binding']['anchor']), 'pseudo-form-replacement-binding-has-no-source-element-anchor');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form fallback finding builder tests: ' . $assertions . " passed\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormFallbackFindingBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormFallbackFindingContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormSuccessPanelMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PseudoFormAnalyzer;

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
$context = new FormFallbackFindingContext(
    static fn (): array => array(),
    static fn (): string => '',
    static fn (DOMElement $element): array => array( 'html' => '<safe-form>', 'bytes' => 11, 'truncated' => true ),
    static fn (DOMElement $element): array => array( '#existing-runtime' ),
    static fn (DOMElement $element): array => array( 'source' => 'fixture' ),
    static fn (DOMElement $element): array => array( 'kind' => 'interactive' ),
    static function (array $block, string $role, array $selectors) use (&$bindingCalls): array {
        $bindingCalls[] = compact('block', 'role', 'selectors');
        return array( 'role' => $role, 'selectors' => $selectors, 'blockName' => $block['blockName'] ?? '' );
    },
    static fn (array $finding): array => array_merge(array( 'provenance' => 'fixture' ), $finding)
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

$pseudo = $elementFrom('<div id="signup-shell"><input name="email"><button>Join</button></div>');
$pseudoFinding = $builder->build($pseudo, null);
$assert('div' === ($pseudoFinding['tag'] ?? ''), 'pseudo-form-tag');
$assert(isset($pseudoFinding['form_boundary']), 'pseudo-form-boundary');
$assert(array() === ($pseudoFinding['readable_blocks'] ?? null), 'null-readable-blocks');
$assert(array() === ($pseudoFinding['binding'] ?? null), 'null-binding');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form fallback finding builder tests: ' . $assertions . " passed\n";

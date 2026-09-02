<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormCompositionPlanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

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

$session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $element): array => array());
$provenance = $session->transformationProvenanceState();
$children = array();
$capturedUnsupported = null;
$planner = new FormCompositionPlanner(
    $session,
    static function (DOMElement $form, array &$fallbacks, bool $captureUnsupported) use (&$children, &$capturedUnsupported, $provenance): array {
        $capturedUnsupported = $captureUnsupported;
        $fallbacks[] = array( 'converted' => true );
        $slot = $form->getElementsByTagName('fieldset')->item(0);
        $token = $slot instanceof DOMElement ? $provenance->formControlSlotToken($slot->getNodePath()) : null;

        return 'nested' === $children
            ? array( array( 'blockName' => 'core/group', 'innerBlocks' => array( array( 'blockName' => 'core/html', '_binding_token' => $token ) ) ) )
            : $children;
    },
    static fn (DOMElement $element): array => array( 'className' => 'presented-' . strtolower($element->tagName) ),
    static fn (string $name, array $attrs, array $innerBlocks, ?DOMElement $sourceElement): array => array(
        'blockName' => $name,
        'attrs' => $attrs,
        'innerBlocks' => $innerBlocks,
        'sourceTag' => $sourceElement?->tagName,
    ),
    static function (DOMElement $container, DOMElement $element): bool {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node === $container ) {
                return true;
            }
        }

        return false;
    }
);

$fallbacks = array();
$assert(null === $planner->compose($formFrom('<form></form>'), $fallbacks), 'empty-form-has-no-composition');
$assert(null === $planner->compose($formFrom('<form><input type="hidden"></form>'), $fallbacks), 'hidden-control-has-no-composition');
$assert(null === $planner->compose($formFrom('<form><fieldset>Heading<input></fieldset></form>'), $fallbacks), 'text-bearing-slot-is-rejected');
$assert(null === $planner->compose($formFrom('<form><div><input></div><div><input></div></form>'), $fallbacks), 'split-controls-have-no-slot');

$children = array();
$fallbacks = array();
$assert(null === $planner->compose($formFrom('<form><fieldset><input></fieldset></form>'), $fallbacks), 'empty-conversion-declines-composition');
$assert(true === $capturedUnsupported, 'composition-captures-unsupported-children');
$assert(array( array( 'converted' => true ) ) === $fallbacks, 'conversion-can-append-fallbacks');

$children = array( array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ) );
$assert(null === $planner->compose($formFrom('<form><fieldset><input></fieldset></form>'), $fallbacks), 'missing-binding-token-declines-composition');

$children = 'nested';
$form = $formFrom('<form><p>Introduction</p><fieldset><label>Email<input></label></fieldset></form>');
$composition = $planner->compose($form, $fallbacks);
$assert('core/group' === ($composition['block']['blockName'] ?? ''), 'composition-builds-group');
$assert('presented-form' === ($composition['block']['attrs']['className'] ?? ''), 'form-presentation-is-retained');
$assert('form' === ($composition['block']['sourceTag'] ?? ''), 'form-remains-group-source');
$assert('core/html' === ($composition['slot']['blockName'] ?? ''), 'nested-binding-block-is-returned');
$fieldset = $form->getElementsByTagName('fieldset')->item(0);
$assert($fieldset instanceof DOMElement && null === $provenance->formControlSlotToken($fieldset->getNodePath()), 'binding-slot-token-is-released');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form composition planner tests: ' . $assertions . " passed\n";

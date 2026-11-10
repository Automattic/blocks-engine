<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeIslandRecorder;

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

$recorded = array();
$session = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession(new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime(), new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription(new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier(), new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer()));
$session->fallbackEmitter()->configure(array(), array( array( 'src' => '/form.js' ) ), new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState(array(), array(), array()));
$metadataBuilder = new FormControlMetadataBuilder(static function (DOMElement $element): string {
    $id = $element->getAttribute('id');
    return '' !== $id ? '#' . $id : strtolower($element->tagName);
});
$recorder = new FormRuntimeIslandRecorder(
    $metadataBuilder,
    static function (DOMElement $element, string $kind, string $reason, string $capability, array $metadata) use (&$recorded): void {
        $recorded[] = compact('element', 'kind', 'reason', 'capability', 'metadata');
    },
    $session
);

$form = $elementFrom('<form id="signup" action="/join" onsubmit="handle()" data-script><input name="email" required><select name="plan"><option>Free</option></select></form>', 'form');
$readableBlock = array( 'blockName' => 'core/group' );
$recorder->recordForm($form, $readableBlock);
$formRecord = $recorded[0] ?? array();
$formMetadata = $formRecord['metadata'] ?? array();
$assert('form' === ($formRecord['kind'] ?? ''), 'form-kind');
$assert('form_requires_runtime' === ($formRecord['reason'] ?? ''), 'form-reason');
$assert('server_or_client_form_handler' === ($formRecord['capability'] ?? ''), 'form-capability');
$assert('signup' === ($formMetadata['form']['id'] ?? '') && '/join' === ($formMetadata['form']['action'] ?? ''), 'form-metadata');
$assert(2 === ($formMetadata['control_count'] ?? 0) && 2 === count($formMetadata['controls'] ?? array()), 'form-control-count');
$assert('email' === ($formMetadata['controls'][0]['name'] ?? '') && true === ($formMetadata['controls'][0]['required'] ?? false), 'form-control-metadata');
$assert(array( array( 'type' => 'submit', 'attribute' => 'onsubmit' ) ) === ($formMetadata['events'] ?? array()), 'form-events');
$assert(array( $readableBlock ) === ($formMetadata['readable_blocks'] ?? array()), 'form-readable-block');
$assert(array( array( 'src' => '/form.js' ) ) === ($formMetadata['required_scripts'] ?? array()), 'form-required-scripts');

$popupForm = $elementFrom('<form><label id="date-label" for="date-input">Date</label><input id="date-input" readonly><button type="button" aria-haspopup="dialog" aria-describedby="date-label">Open picker</button></form>', 'form');
$popupControls = $metadataBuilder->controls($popupForm);
$assert('date-label' === ($popupControls[0]['label_id'] ?? ''), 'associated-label-id-metadata');
$assert('dialog' === ($popupControls[1]['aria_haspopup'] ?? '') && 'date-label' === ($popupControls[1]['aria_describedby'] ?? ''), 'popup-control-relationship-metadata');

$recorder->recordForm($form, null);
$assert(array() === ($recorded[1]['metadata']['readable_blocks'] ?? null), 'form-without-readable-block');

$control = $elementFrom('<input id="runtime-email" name="email" onsubmit="handle()" data-script>', 'input');
$recorder->recordControl($control);
$controlRecord = $recorded[2] ?? array();
$controlMetadata = $controlRecord['metadata'] ?? array();
$assert('control' === ($controlRecord['kind'] ?? '') && 'runtime_dom_target' === ($controlRecord['reason'] ?? ''), 'runtime-control-classification');
$assert('client_script_execution' === ($controlRecord['capability'] ?? ''), 'runtime-control-capability');
$assert('#runtime-email' === ($controlMetadata['control']['selector'] ?? '') && 'email' === ($controlMetadata['control']['name'] ?? ''), 'runtime-control-metadata');
$assert(array( array( 'type' => 'submit', 'attribute' => 'onsubmit' ) ) === ($controlMetadata['events'] ?? array()), 'runtime-control-events');
$assert(array( array( 'src' => '/form.js' ) ) === ($controlMetadata['required_scripts'] ?? array()), 'runtime-control-scripts');

$nonControl = $elementFrom('<div onsubmit="handle()"></div>', 'div');
$assert(! $recorder->preserveStandaloneControl($nonControl) && 3 === count($recorded), 'non-control-is-not-preserved');

$standalone = $elementFrom('<textarea name="message" onsubmit="handle()"></textarea>', 'textarea');
$assert($recorder->preserveStandaloneControl($standalone), 'standalone-control-is-preserved');
$standaloneRecord = $recorded[3] ?? array();
$assert('form_control_requires_runtime' === ($standaloneRecord['reason'] ?? ''), 'standalone-control-reason');
$assert('client_form_control_runtime' === ($standaloneRecord['capability'] ?? ''), 'standalone-control-capability');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form runtime island recorder tests: ' . $assertions . " passed\n";

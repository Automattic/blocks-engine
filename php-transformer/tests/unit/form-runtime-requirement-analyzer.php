<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;

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
    $element = $document->getElementsByTagName('form')->item(0);
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No form parsed');
};

$analyzer = new FormRuntimeRequirementAnalyzer(
    static fn (DOMElement $element): array => $element->hasAttribute('data-event') ? array( 'click' => true ) : array(),
    static fn (DOMElement $element): bool => $element->hasAttribute('data-runtime')
);

$assert(! $analyzer->requiresPreservation($elementFrom('<form><input name="email"></form>')), 'plain-form-needs-no-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form><script>submit()</script></form>')), 'script-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form data-event="1"></form>')), 'form-event-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form action="/subscribe"></form>')), 'action-requires-runtime');
$assert(! $analyzer->requiresPreservation($elementFrom('<form action="#"></form>')), 'hash-action-is-inert');
$assert($analyzer->requiresPreservation($elementFrom('<form method="post"></form>')), 'method-without-action-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form target="_blank"></form>')), 'target-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form><button type="submit">Checkout</button></form>')), 'commerce-submit-requires-runtime');
$assert(! $analyzer->requiresPreservation($elementFrom('<form><button type="submit">Send</button></form>')), 'generic-submit-needs-no-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form data-runtime="1"></form>')), 'form-runtime-target-requires-preservation');
$assert($analyzer->requiresPreservation($elementFrom('<form><input data-runtime="1"></form>')), 'control-runtime-target-requires-preservation');
$assert($analyzer->requiresPreservation($elementFrom('<form class="signup js-form"></form>')), 'form-js-class-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form><input class="field js-validate"></form>')), 'control-js-class-requires-runtime');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form runtime requirement analyzer tests: ' . $assertions . " passed\n";

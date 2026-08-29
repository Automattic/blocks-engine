<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormDispatchContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormDispatcher;

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
    $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No form element parsed');
};

$mode = 'readable';
$recorded = array();
$calls = array();
$context = new FormDispatchContext(
    static function (DOMElement $element) use (&$mode, &$calls): ?array {
        $calls[] = 'search';
        return 'search' === $mode ? array( 'blockName' => 'core/search' ) : null;
    },
    static function (DOMElement $element, array &$fallbacks) use (&$mode, &$calls): ?array {
        $calls[] = 'compose';
        if ( 'composition' !== $mode ) {
            return null;
        }
        $fallbacks[] = array( 'from' => 'composition' );
        return array(
            'block' => array( 'blockName' => 'core/group', 'attrs' => array( 'mode' => 'composition' ) ),
            'slot' => array( 'blockName' => 'core/html' ),
        );
    },
    static function (DOMElement $element, ?array $readable, ?array $binding = null) use (&$calls): array {
        $calls[] = 'finding';
        return array(
            'diagnostic_code' => 'html_form_fallback',
            'readable' => $readable['blockName'] ?? null,
            'binding' => $binding['blockName'] ?? null,
        );
    },
    static function (DOMElement $element, ?array $readable) use (&$recorded, &$calls): void {
        $calls[] = 'record';
        $recorded[] = $readable;
    },
    static function (DOMElement $element, bool $allowEvents = false) use (&$mode, &$calls): ?array {
        $calls[] = $allowEvents ? 'readable-events' : 'readable';
        if ( 'unreadable' === $mode || ('submit-only' === $mode && ! $allowEvents) ) {
            return null;
        }
        return array( 'blockName' => 'core/group', 'attrs' => array( 'allowEvents' => $allowEvents ) );
    },
    static function (DOMElement $element) use (&$mode): bool {
        return 'preserve' === $mode;
    },
    static function (DOMElement $element) use (&$calls): array {
        $calls[] = 'preserve';
        return array( 'blockName' => 'core/html' );
    },
    static fn (DOMElement $element): bool => $element->hasAttribute('data-pseudo')
);
$dispatcher = new FormDispatcher($context);
$dataForm = $formFrom('<form><input name="email"></form>');

$calls = array();
$fallbacks = array();
$mode = 'search';
$result = $dispatcher->convert($dataForm, $fallbacks);
$assert('core/search' === ($result['blockName'] ?? ''), 'search-form-short-circuits');
$assert(array( 'search' ) === $calls, 'search-form-skips-other-strategies');
$assert(array() === $fallbacks, 'search-form-has-no-form-fallback');

$calls = array();
$fallbacks = array();
$recorded = array();
$mode = 'composition';
$result = $dispatcher->convert($dataForm, $fallbacks);
$assert('composition' === ($result['attrs']['mode'] ?? ''), 'composition-is-returned');
$assert(2 === count($fallbacks), 'composition-retains-conversion-and-form-findings');
$assert('core/html' === ($fallbacks[1]['binding'] ?? ''), 'composition-slot-binds-finding');
$assert(array( $result ) === $recorded, 'composition-records-runtime-form');

$calls = array();
$fallbacks = array();
$recorded = array();
$mode = 'readable';
$result = $dispatcher->convert($dataForm, $fallbacks);
$assert('core/group' === ($result['blockName'] ?? ''), 'readable-form-is-returned');
$assert(1 === count($fallbacks) && 'core/group' === ($fallbacks[0]['readable'] ?? ''), 'readable-data-form-emits-finding');
$assert(array() === $recorded, 'readable-static-form-needs-no-runtime-record');

$calls = array();
$fallbacks = array();
$recorded = array();
$mode = 'preserve';
$result = $dispatcher->convert($dataForm, $fallbacks);
$assert('core/html' === ($result['blockName'] ?? ''), 'runtime-form-is-preserved');
$assert('core/html' === ($fallbacks[0]['binding'] ?? ''), 'preservation-block-binds-finding');
$assert('core/group' === ($recorded[0]['blockName'] ?? ''), 'runtime-record-retains-readable-form');

$calls = array();
$fallbacks = array();
$recorded = array();
$mode = 'submit-only';
$submitForm = $formFrom('<form><button type="submit">Join</button></form>');
$result = $dispatcher->convert($submitForm, $fallbacks);
$assert(true === ($result['attrs']['allowEvents'] ?? false), 'submit-only-form-retries-with-events');
$assert(array( $result ) === $recorded, 'submit-only-form-records-runtime-contract');
$assert(array() === $fallbacks, 'readable-submit-only-form-needs-no-finding');

$fallbacks = array();
$mode = 'readable';
$pseudo = $formFrom('<div data-pseudo><input><button>Join</button></div>');
$dispatcher->capturePseudoFormFallback($pseudo, $fallbacks);
$assert(1 === count($fallbacks), 'pseudo-form-emits-finding');
$assert('core/group' === ($fallbacks[0]['readable'] ?? ''), 'pseudo-form-finding-carries-readable-block');

$fallbacks = array();
$dispatcher->capturePseudoFormFallback($formFrom('<div><input></div>'), $fallbacks);
$assert(array() === $fallbacks, 'ordinary-container-is-ignored');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form dispatcher tests: ' . $assertions . " passed\n";

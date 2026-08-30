<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DetailsElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\DetailsElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><details><summary>More</summary></details><dialog></dialog><div></div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$details = $document->getElementsByTagName('details')->item(0);
$dialog = $document->getElementsByTagName('dialog')->item(0);
$div = $document->getElementsByTagName('div')->item(0);
if ( ! $details instanceof DOMElement || ! $dialog instanceof DOMElement || ! $div instanceof DOMElement ) {
    throw new RuntimeException('Fixture elements not parsed');
}

$captured = false;
$patternCalls = 0;
$seenPatterns = array();
$patternBlock = array( 'blockName' => 'core/details' );
$converter = new DetailsElementConverter(new DetailsElementContext(
    static function (DOMElement $element) use (&$captured, $dialog): ?DOMElement {
        return $captured ? $dialog : null;
    },
    static function (DOMElement $element, array &$fallbacks): array {
        $fallbacks[] = array( 'captured' => true );
        return array( 'blockName' => 'core/html', 'attrs' => array( 'captured' => $element->tagName ) );
    },
    static function (DOMElement $element, array &$fallbacks, array $patterns) use (&$patternCalls, &$seenPatterns, &$patternBlock): ?array {
        ++$patternCalls;
        $seenPatterns = $patterns;
        $fallbacks[] = array( 'pattern' => true );
        return $patternBlock;
    }
));
$fallbacks = array();

$unhandled = $converter->convert($div, 'div', $fallbacks);
$assert(! $unhandled->handled && 0 === $patternCalls, 'non-details-unhandled');

$recognized = $converter->convert($details, 'details', $fallbacks);
$assert($recognized->handled && 'core/details' === ($recognized->block['blockName'] ?? ''), 'details-pattern-recognized');
$assert(array( DetailsPattern::class ) === $seenPatterns, 'details-pattern-bounded');
$assert(1 === count($fallbacks), 'pattern-fallback-reference-forwarded');

$captured = true;
$projected = $converter->convert($details, 'details', $fallbacks);
$assert('dialog' === ($projected->block['attrs']['captured'] ?? '') && 1 === $patternCalls, 'captured-dialog-precedes-pattern');
$assert(2 === count($fallbacks), 'captured-fallback-reference-forwarded');

$captured = false;
$patternBlock = null;
$declined = $converter->convert($details, 'details', $fallbacks);
$assert($declined->handled && null === $declined->block, 'details-null-remains-handled');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Details element converter tests: ' . $assertions . " passed\n";

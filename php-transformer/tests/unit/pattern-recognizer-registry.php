<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognitionResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;

$failures = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ( $expected === $actual ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$document = new DOMDocument();
$document->loadHTML('<div></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$element = $document->documentElement;
if ( ! $element instanceof DOMElement ) {
    throw new RuntimeException('Fixture did not produce a DOM element.');
}

$context = new PatternContext(
    static fn (DOMElement $source): array => array(),
    static fn (DOMElement $source): string => '',
    static fn (string $name, array $attrs = array(), array $children = array(), ?DOMElement $source = null): array => array( 'blockName' => $name )
);
$first = new class implements PatternRecognizerInterface {
    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        return new PatternRecognitionResult(
            array( 'blockName' => 'first' ),
            array( array( 'type' => 'fallback' ) ),
            array( array( 'source' => 'first' ) ),
            array( 'records_fallback' )
        );
    }
};
$second = new class implements PatternRecognizerInterface {
    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        throw new RuntimeException('Ordered registry invoked a lower-precedence recognizer.');
    }
};

$result = ( new PatternRecognizerRegistry( array( $first, $second ) ) )->firstMatch($element, $context);
$assertSame('first', $result?->block()['blockName'] ?? null, 'First matching recognizer wins.');
$assertSame(array( array( 'type' => 'fallback' ) ), $result?->fallbacks(), 'Result keeps fallbacks with its block.');
$assertSame(array( array( 'source' => 'first' ) ), $result?->provenance(), 'Result keeps provenance with its block.');
$assertSame(array( 'records_fallback' ), $result?->declaredSideEffects(), 'Result declares committed side effects.');

echo "pattern recognizer registry ok\n";
exit(0 === $failures ? 0 : 1);

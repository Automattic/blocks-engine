<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ConversionOutcome;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\OrderedElementConverterRegistry;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$document = new DOMDocument();
$document->loadHTML('<body><div></div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$element = $document->getElementsByTagName('div')->item(0);
if ( ! $element instanceof DOMElement ) {
    throw new RuntimeException('No element parsed');
}

$calls = array();
$converter = static function (string $name, ConversionOutcome $outcome) use (&$calls): ElementConverter {
    return new class($name, $outcome, $calls) implements ElementConverter {
        /** @var array<int, string> */
        private array $calls;

        /** @param array<int, string> $calls */
        public function __construct(
            private readonly string $name,
            private readonly ConversionOutcome $outcome,
            array &$calls
        ) {
            $this->calls =& $calls;
        }

        public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
        {
            $this->calls[] = $this->name;
            $fallbacks[] = array( 'converter' => $this->name );
            return $this->outcome;
        }
    };
};

$fallbacks = array();
$registry = new OrderedElementConverterRegistry(array(
    $converter('first', ConversionOutcome::unhandled()),
    $converter('second', ConversionOutcome::handled(array( 'blockName' => 'core/group' ))),
    $converter('third', ConversionOutcome::handled(array( 'blockName' => 'core/paragraph' ))),
));
$handled = $registry->convert($element, 'div', $fallbacks);
$assert($handled->handled && 'core/group' === ($handled->block['blockName'] ?? ''), 'first-handled-outcome-returned');
$assert(array( 'first', 'second' ) === $calls, 'declaration-order-and-short-circuit');
$assert(2 === count($fallbacks), 'fallback-reference-shared-through-chain');

$calls = array();
$fallbacks = array();
$handledNull = (new OrderedElementConverterRegistry(array(
    $converter('empty', ConversionOutcome::handled(null)),
    $converter('late', ConversionOutcome::handled(array( 'blockName' => 'core/group' ))),
)))->convert($element, 'div', $fallbacks);
$assert($handledNull->handled && null === $handledNull->block, 'handled-null-preserved');
$assert(array( 'empty' ) === $calls, 'handled-null-short-circuits');

$calls = array();
$fallbacks = array();
$unhandled = (new OrderedElementConverterRegistry(array(
    $converter('one', ConversionOutcome::unhandled()),
    $converter('two', ConversionOutcome::unhandled()),
)))->convert($element, 'div', $fallbacks);
$assert(! $unhandled->handled && null === $unhandled->block, 'all-declined-remains-unhandled');
$assert(array( 'one', 'two' ) === $calls, 'all-declined-runs-complete-chain');

$emptyRegistry = (new OrderedElementConverterRegistry(array()))->convert($element, 'div', $fallbacks);
$assert(! $emptyRegistry->handled, 'empty-registry-unhandled');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Ordered element converter registry tests: ' . $assertions . " passed\n";

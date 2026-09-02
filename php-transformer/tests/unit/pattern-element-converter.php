<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ConversionOutcome;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\OrderedElementConverterRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PatternElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PatternElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ParameterTablePattern;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><div class="param-table"></div><table></table></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$div = $document->getElementsByTagName('div')->item(0);
$table = $document->getElementsByTagName('table')->item(0);
if ( ! $div instanceof DOMElement || ! $table instanceof DOMElement ) {
    throw new RuntimeException('Fixture elements not parsed');
}

$mode = 'match';
$seenPatterns = array();
$converter = new PatternElementConverter(
    new PatternElementContext(
        static function (DOMElement $element, array &$fallbacks, array $patterns) use (&$mode, &$seenPatterns): ?array {
            $seenPatterns = $patterns;
            $fallbacks[] = array( 'pattern' => true );
            return 'match' === $mode ? array( 'blockName' => 'core/table' ) : null;
        }
    ),
    array( ParameterTablePattern::class )
);
$fallbacks = array();
$matched = $converter->convert($div, 'div', $fallbacks);
$assert($matched->handled && 'core/table' === ($matched->block['blockName'] ?? ''), 'recognized-pattern-handled');
$assert(array( ParameterTablePattern::class ) === $seenPatterns, 'bounded-pattern-list-forwarded');
$assert(1 === count($fallbacks), 'fallback-reference-forwarded');

$mode = 'decline';
$assert(! $converter->convert($div, 'div', $fallbacks)->handled, 'declined-pattern-unhandled');

$nativeCalls = 0;
$nativeTable = new class($nativeCalls) implements ElementConverter {
    private int $calls;

    public function __construct(int &$calls)
    {
        $this->calls =& $calls;
    }

    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'table' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }
        ++$this->calls;
        return ConversionOutcome::handled(array( 'blockName' => 'core/table', 'attrs' => array( 'native' => true ) ));
    }
};
$mode = 'match';
$registry = new OrderedElementConverterRegistry(array( $nativeTable, $converter ));
$native = $registry->convert($table, 'table', $fallbacks);
$assert(1 === $nativeCalls && true === ($native->block['attrs']['native'] ?? false), 'native-table-precedes-pattern');
$pattern = $registry->convert($div, 'div', $fallbacks);
$assert($pattern->handled && 'core/table' === ($pattern->block['blockName'] ?? ''), 'non-table-reaches-pattern');

try {
    new PatternElementConverter(new PatternElementContext(static fn (): ?array => null), array());
    $assert(false, 'empty-pattern-list-rejected');
} catch (InvalidArgumentException) {
    $assert(true, 'empty-pattern-list-rejected');
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Pattern element converter tests: ' . $assertions . " passed\n";

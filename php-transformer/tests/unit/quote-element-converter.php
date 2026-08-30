<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ConversionOutcome;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\OrderedElementConverterRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\QuoteElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\QuoteElementConverter;

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

$mode = 'quote';
$converter = new QuoteElementConverter(new QuoteElementContext(
    static function (DOMElement $element, array &$fallbacks) use (&$mode): ?array {
        $fallbacks[] = array( 'quote' => true );
        return 'quote' === $mode ? array( 'blockName' => 'core/quote' ) : null;
    }
));
$fallbacks = array();

$unhandled = $converter->convert($elementFrom('<div></div>'), 'div', $fallbacks);
$assert(! $unhandled->handled && array() === $fallbacks, 'non-blockquote-unhandled');
$quote = $converter->convert($elementFrom('<blockquote>Words</blockquote>'), 'blockquote', $fallbacks);
$assert($quote->handled && 'core/quote' === ($quote->block['blockName'] ?? ''), 'blockquote-recognized');
$assert(1 === count($fallbacks), 'fallback-reference-forwarded');

$mode = 'declined';
$declined = $converter->convert($elementFrom('<blockquote></blockquote>'), 'blockquote', $fallbacks);
$assert($declined->handled && null === $declined->block, 'recognized-family-null-remains-handled');

$lateCalls = 0;
$lateConverter = new class($lateCalls) implements ElementConverter {
    private int $calls;

    public function __construct(int &$calls)
    {
        $this->calls =& $calls;
    }

    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        ++$this->calls;
        return 'figure' === $tagName
            ? ConversionOutcome::handled(array( 'blockName' => 'core/image' ))
            : ConversionOutcome::unhandled();
    }
};
$registry = new OrderedElementConverterRegistry(array( $converter, $lateConverter ));
$mode = 'quote';
$registry->convert($elementFrom('<blockquote>Words</blockquote>'), 'blockquote', $fallbacks);
$assert(0 === $lateCalls, 'quote-short-circuits-later-structural-converter');
$figure = $registry->convert($elementFrom('<figure></figure>'), 'figure', $fallbacks);
$assert(1 === $lateCalls && 'core/image' === ($figure->block['blockName'] ?? ''), 'quote-declines-figure-to-next-converter');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Quote element converter tests: ' . $assertions . " passed\n";

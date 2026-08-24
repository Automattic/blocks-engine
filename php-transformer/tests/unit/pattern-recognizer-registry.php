<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognitionResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerInterface;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MathPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PlaceholderMediaPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;

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
$calls = new ArrayObject();
$declined = new class($calls) implements PatternRecognizerInterface {
    public function __construct(private readonly ArrayObject $calls) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $this->calls[] = 'declined';
        return null;
    }
};
$first = new class($calls) implements PatternRecognizerInterface {
    public function __construct(private readonly ArrayObject $calls) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $this->calls[] = 'first';
        return new PatternRecognitionResult(
            array( 'blockName' => 'first' ),
            array( array( 'type' => 'fallback' ) )
        );
    }
};
$second = new class($calls) implements PatternRecognizerInterface {
    public function __construct(private readonly ArrayObject $calls) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $this->calls[] = 'second';
        throw new RuntimeException('Ordered registry invoked a lower-precedence recognizer.');
    }
};

$result = ( new PatternRecognizerRegistry( array( $declined, $first, $second ) ) )->firstMatch($element, $context);
$assertSame('first', $result?->block()['blockName'] ?? null, 'First matching recognizer wins.');
$assertSame(array( array( 'type' => 'fallback' ) ), $result?->fallbacks(), 'Result keeps fallbacks with its block.');
$assertSame(array( 'declined', 'first' ), $calls->getArrayCopy(), 'Declined recognizers contribute no result and dispatch stops after the winner.');

$elementFromHtml = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ( ! $document->documentElement instanceof DOMElement ) {
        throw new RuntimeException('Fixture did not produce a DOM element.');
    }

    return $document->documentElement;
};
$innerHtml = static function (DOMElement $source): string {
    $html = '';
    foreach ( $source->childNodes as $child ) {
        $html .= $source->ownerDocument?->saveHTML($child) ?: '';
    }

    return trim($html);
};
$createBlock = static fn (string $name, array $attrs = array(), array $children = array(), ?DOMElement $source = null): array => array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children );
$directContext = new PatternContext(
    static fn (DOMElement $source, array $excluded = array()): array => array(),
    $innerHtml,
    $createBlock,
    safeFallbackHtml: static fn (DOMElement $source): string => $source->ownerDocument?->saveHTML($source) ?: '',
    escapeHtml: static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
);
$overlap = ( new PatternRecognizerRegistry( array( new MathPattern(), new PlaceholderMediaPattern() ) ) )->firstMatch($elementFromHtml('<div class="placeholder media math" style="aspect-ratio: 16 / 9">$x$</div>'), $directContext);
$assertSame('core/math', $overlap?->block()['blockName'] ?? null, 'Math wins direct ordered-registry competition with placeholder media.');
$assertSame('$x$', $overlap?->block()['attrs']['content'] ?? null, 'Direct ordered-registry winner preserves math content.');

$state = new ArrayObject();
$lower = new class($state) implements PatternRecognizerInterface {
    public function __construct(private readonly ArrayObject $state) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $this->state[] = 'lower';
        return new PatternRecognitionResult(array( 'blockName' => 'lower' ));
    }
};
$declinedSpacerContext = new PatternContext(
    static function (DOMElement $source, array $excluded = array()) use ($state): array {
        $state[] = 'presentation';
        return array();
    },
    $innerHtml,
    $createBlock
);
$declinedSpacer = ( new PatternRecognizerRegistry( array( new SpacerPattern(), $lower ) ) )->firstMatch($elementFromHtml('<div class="spacer" style="height: 24px">Visible content</div>'), $declinedSpacerContext);
$assertSame('lower', $declinedSpacer?->block()['blockName'] ?? null, 'A declined direct spacer leaves lower recognizers available.');
$assertSame(array( 'lower' ), $state->getArrayCopy(), 'A declined direct spacer does not invoke context callbacks or mutate recognizer state.');

$missingSafeFallback = new PatternContext(static fn (DOMElement $source): array => array(), $innerHtml, $createBlock, escapeHtml: static fn (string $text): string => $text);
$missingEscapeHtml = new PatternContext(static fn (DOMElement $source): array => array(), $innerHtml, $createBlock, safeFallbackHtml: static fn (DOMElement $source): string => 'safe');
$assertSame(null, ( new MathPattern() )->recognize($elementFromHtml('<math><mi>x</mi></math>'), $missingSafeFallback), 'Math declines without its safe-fallback context dependency.');
$assertSame(null, ( new MathPattern() )->recognize($elementFromHtml('<math><mi>x</mi></math>'), $missingEscapeHtml), 'Math declines without its HTML-escaping context dependency.');
$assertSame(null, ( new PlaceholderMediaPattern() )->recognize($elementFromHtml('<div class="placeholder media" style="aspect-ratio: 16 / 9">Label</div>'), $missingEscapeHtml), 'Placeholder media declines without its HTML-escaping context dependency.');

echo "pattern recognizer registry ok\n";
exit(0 === $failures ? 0 : 1);

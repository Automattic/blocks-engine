<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonElementConverter;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><button>Go</button><button><img src="x"></button><div></div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$button = $document->getElementsByTagName('button')->item(0);
$imageButton = $document->getElementsByTagName('button')->item(1);
$div = $document->getElementsByTagName('div')->item(0);
if ( ! $button instanceof DOMElement || ! $imageButton instanceof DOMElement || ! $div instanceof DOMElement ) {
    throw new RuntimeException('Fixture elements not parsed');
}

$mode = 'generic';
$genericCalls = 0;
$captureUnsupported = null;
$genericBlock = array( 'blockName' => 'core/buttons' );
$converter = new ButtonElementConverter(new ButtonElementContext(
    new SourceElementClassifier(),
    static function (DOMElement $element) use (&$mode): bool {
        return 'search' === $mode;
    },
    static function (DOMElement $element, array &$fallbacks, bool $capture) use (&$mode, &$captureUnsupported): array {
        $captureUnsupported = $capture;
        $fallbacks[] = array( 'image' => true );
        return 'image' === $mode ? array( array( 'blockName' => 'core/image' ) ) : array();
    },
    new ElementPresentationResolverFixture(static fn (DOMElement $element): array => array( 'className' => 'carrier' )),
    new SourceBlockCreatorFixture(static fn (string $name, array $attributes, array $innerBlocks, DOMElement $element): array => array(
        'blockName' => $name,
        'attrs' => $attributes,
        'innerBlocks' => $innerBlocks,
    )),
    static function (DOMElement $element) use (&$genericCalls, &$genericBlock): ?array {
        ++$genericCalls;
        return $genericBlock;
    }
));
$fallbacks = array();

$unhandled = $converter->convert($div, 'div', $fallbacks);
$assert(! $unhandled->handled && 0 === $genericCalls, 'non-button-unhandled');

$mode = 'search';
$search = $converter->convert($button, 'button', $fallbacks);
$assert($search->handled && null === $search->block, 'replaced-search-control-suppressed');
$assert(0 === $genericCalls, 'search-short-circuits-later-routes');

$mode = 'image';
$image = $converter->convert($imageButton, 'button', $fallbacks);
$assert('core/group' === ($image->block['blockName'] ?? ''), 'image-carrier-becomes-group');
$assert('carrier' === ($image->block['attrs']['className'] ?? '') && 'core/image' === ($image->block['innerBlocks'][0]['blockName'] ?? ''), 'image-carrier-content-preserved');
$assert(true === $captureUnsupported && 1 === count($fallbacks), 'image-children-forward-fallback-state');
$assert(0 === $genericCalls, 'image-carrier-short-circuits-generic-button');

$mode = 'empty-image';
$emptyImage = $converter->convert($imageButton, 'button', $fallbacks);
$assert('core/buttons' === ($emptyImage->block['blockName'] ?? '') && 1 === $genericCalls, 'empty-image-carrier-falls-through');

$mode = 'generic';
$generic = $converter->convert($button, 'button', $fallbacks);
$assert($generic->handled && 'core/buttons' === ($generic->block['blockName'] ?? ''), 'ordinary-button-delegated');

$genericBlock = null;
$null = $converter->convert($button, 'button', $fallbacks);
$assert($null->handled && null === $null->block, 'generic-null-remains-handled');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Button element converter tests: ' . $assertions . " passed\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\InlineContentElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\InlineContentElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

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

$state = (object) array( 'mode' => 'plain' );
$context = new InlineContentElementContext(
    static fn (DOMElement $element, array &$fallbacks, array $patterns): ?array => 'social' === $state->mode ? array( 'blockName' => 'core/social-links' ) : null,
    static fn (DOMElement $element): bool => 'runtime' === $state->mode,
    static fn (DOMElement $element): array => array( 'blockName' => 'core/html' ),
    static fn (DOMElement $element): ?array => 'svg-text' === $state->mode ? array( 'blockName' => 'core/image' ) : null,
    static fn (DOMElement $element): bool => 'positioned' === $state->mode,
    static fn (DOMElement $element, array &$fallbacks): ?array => array( 'blockName' => 'core/group', 'attrs' => array( 'positioned' => true ) ),
    static fn (DOMElement $element): bool => false,
    static fn (DOMElement $element): string => '',
    static fn (string $content): bool => false,
    static fn (DOMElement $element, array &$fallbacks, bool $captureUnsupported): array => array(),
    static fn (string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array => array(
        'blockName' => $name,
        'attrs' => $attributes,
        'innerBlocks' => $innerBlocks,
        'sourceTag' => $sourceElement?->tagName,
    ),
    static fn (DOMElement $element): string => '',
    static fn (DOMElement $element): bool => false,
    static fn (DOMElement $element): array => array(),
    static fn (DOMElement $element): ?string => null,
    static fn (DOMElement $element): string => 'empty' === $state->mode ? '' : $element->ownerDocument?->saveHTML($element) ?? '',
    static fn (DOMElement $element, string $tagName): ?DOMElement => null,
    static fn (DOMElement $element): bool => false,
    static fn (DOMElement $element): bool => false,
    static fn (DOMElement $element): array => array( 'blockName' => 'core/spacer' )
);
$styleResolver = (new ReflectionClass(StyleResolver::class))->newInstanceWithoutConstructor();
$converter = new InlineContentElementConverter($context, $styleResolver, new Runtime());
$fallbacks = array();
$span = $elementFrom('<span>Hello</span>');

$assert($converter->handles('span') && $converter->handles('strong') && ! $converter->handles('div'), 'handles-inline-tags-only');
$unhandled = $converter->convert($elementFrom('<div></div>'), 'div', $fallbacks);
$assert(! $unhandled->handled, 'unhandled-outcome');

$state->mode = 'social';
$assert('core/social-links' === ($converter->convert($span, 'span', $fallbacks)->block['blockName'] ?? ''), 'social-links-first');
$state->mode = 'runtime';
$assert('core/html' === ($converter->convert($span, 'span', $fallbacks)->block['blockName'] ?? ''), 'runtime-target-preserved');
$state->mode = 'svg-text';
$assert('core/image' === ($converter->convert($span, 'span', $fallbacks)->block['blockName'] ?? ''), 'inline-svg-text-group');
$state->mode = 'positioned';
$positioned = $converter->convert($span, 'span', $fallbacks)->block;
$assert('core/group' === ($positioned['blockName'] ?? '') && true === ($positioned['attrs']['positioned'] ?? false), 'positioned-carrier');

$state->mode = 'plain';
$paragraph = $converter->convert($span, 'span', $fallbacks)->block;
$assert('core/paragraph' === ($paragraph['blockName'] ?? '') && str_contains((string) ($paragraph['attrs']['content'] ?? ''), '<span>Hello</span>'), 'visible-inline-paragraph');
$state->mode = 'empty';
$empty = $converter->convert($span, 'span', $fallbacks);
$assert($empty->handled && null === $empty->block, 'empty-inline-handled-without-block');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Inline content element converter tests: ' . $assertions . " passed\n";

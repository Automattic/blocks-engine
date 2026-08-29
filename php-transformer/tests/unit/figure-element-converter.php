<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FigureElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FigureElementConverter;

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

$mode = 'generic';
$calls = array();
$context = new FigureElementContext(
    static function (DOMElement $element, array &$fallbacks) use (&$mode, &$calls): ?array {
        $calls[] = 'gallery';
        return 'gallery' === $mode ? array( 'blockName' => 'core/gallery' ) : null;
    },
    static function (DOMElement $element, array &$fallbacks, array $patterns) use (&$mode, &$calls): ?array {
        $pattern = basename(str_replace('\\', '/', $patterns[0]));
        $calls[] = $pattern;
        if ( 'code' === $mode && 'CodeWindowPattern' === $pattern ) {
            return array( 'blockName' => 'core/code' );
        }
        if ( 'quote' === $mode && 'FigureQuotePattern' === $pattern ) {
            return array( 'blockName' => 'core/quote' );
        }
        return null;
    },
    static function (DOMElement $figure) use (&$mode, &$calls): ?DOMElement {
        $calls[] = 'linked';
        return str_starts_with($mode, 'linked-') ? $figure->getElementsByTagName('a')->item(0) : null;
    },
    static function (DOMElement $element, string $tagName): ?DOMElement {
        $child = $element->getElementsByTagName($tagName)->item(0);
        return $child instanceof DOMElement ? $child : null;
    },
    static fn (DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): array => array(
        'blockName' => 'core/image',
        'attrs' => array( 'source' => 'picture', 'linked' => $link instanceof DOMElement ),
    ),
    static fn (DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): array => array(
        'blockName' => 'core/image',
        'attrs' => array( 'source' => 'image', 'linked' => $link instanceof DOMElement ),
    ),
    static function (DOMElement $figure, string $tagName) use (&$mode, &$calls): ?DOMElement {
        $calls[] = 'media-' . $tagName;
        if ( $mode !== $tagName ) {
            return null;
        }
        $media = $figure->getElementsByTagName($tagName)->item(0);
        return $media instanceof DOMElement ? $media : null;
    },
    static function (DOMElement $figure, array &$fallbacks) use (&$calls): array {
        $calls[] = 'generic';
        return array( 'blockName' => 'core/group' );
    },
    static fn (DOMElement $element): string => $element->ownerDocument?->saveHTML($element) ?: '',
    static fn (string $html): bool => '' !== trim(strip_tags($html)),
    static fn (DOMElement $element): array => array( 'className' => 'caption' ),
    static fn (string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array => array(
        'blockName' => $name,
        'attrs' => $attributes,
        'innerBlocks' => $innerBlocks,
        'sourceTag' => $sourceElement?->tagName,
    )
);
$converter = new FigureElementConverter($context);

$assert($converter->handles('figure') && $converter->handles('figcaption'), 'handles-figure-family');
$assert(! $converter->handles('div'), 'declines-other-tags');
$fallbacks = array();
$unhandled = $converter->convert($elementFrom('<div></div>'), 'div', $fallbacks);
$assert(! $unhandled->handled && null === $unhandled->block, 'unhandled-outcome');

$mode = 'gallery';
$result = $converter->convert($elementFrom('<figure></figure>'), 'figure', $fallbacks);
$assert('core/gallery' === ($result->block['blockName'] ?? ''), 'gallery-wins');

$mode = 'code';
$result = $converter->convert($elementFrom('<figure></figure>'), 'figure', $fallbacks);
$assert('core/code' === ($result->block['blockName'] ?? ''), 'code-window-follows-gallery');

$mode = 'linked-picture';
$result = $converter->convert($elementFrom('<figure><a><picture><img></picture></a></figure>'), 'figure', $fallbacks);
$assert('picture' === ($result->block['attrs']['source'] ?? '') && true === ($result->block['attrs']['linked'] ?? false), 'linked-picture');

$mode = 'linked-image';
$result = $converter->convert($elementFrom('<figure><a><img></a></figure>'), 'figure', $fallbacks);
$assert('image' === ($result->block['attrs']['source'] ?? '') && true === ($result->block['attrs']['linked'] ?? false), 'linked-image');

$mode = 'img';
$result = $converter->convert($elementFrom('<figure><img></figure>'), 'figure', $fallbacks);
$assert('image' === ($result->block['attrs']['source'] ?? '') && false === ($result->block['attrs']['linked'] ?? true), 'direct-image');

$mode = 'picture';
$result = $converter->convert($elementFrom('<figure><picture><img></picture></figure>'), 'figure', $fallbacks);
$assert('picture' === ($result->block['attrs']['source'] ?? '') && false === ($result->block['attrs']['linked'] ?? true), 'direct-picture');

$mode = 'quote';
$result = $converter->convert($elementFrom('<figure><blockquote>Words</blockquote></figure>'), 'figure', $fallbacks);
$assert('core/quote' === ($result->block['blockName'] ?? ''), 'figure-quote');

$mode = 'generic';
$result = $converter->convert($elementFrom('<figure><video></video></figure>'), 'figure', $fallbacks);
$assert('core/group' === ($result->block['blockName'] ?? ''), 'generic-figure-fallback');

$emptyCaption = $converter->convert($elementFrom('<figcaption><span></span></figcaption>'), 'figcaption', $fallbacks);
$assert($emptyCaption->handled && null === $emptyCaption->block, 'empty-caption-handled-without-block');

$caption = $converter->convert($elementFrom('<figcaption>Caption <em>text</em></figcaption>'), 'figcaption', $fallbacks);
$assert('core/paragraph' === ($caption->block['blockName'] ?? ''), 'caption-paragraph');
$assert('caption' === ($caption->block['attrs']['className'] ?? ''), 'caption-presentation');
$assert(str_contains((string) ($caption->block['attrs']['content'] ?? ''), '<em>text</em>'), 'caption-html');
$assert('figcaption' === ($caption->block['sourceTag'] ?? ''), 'caption-source');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Figure element converter tests: ' . $assertions . " passed\n";

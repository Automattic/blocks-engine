<?php
declare(strict_types=1);

/**
 * RichTextElementConverter is exercised without constructing an HtmlTransformer.
 *
 * Heading and paragraph conversion previously lived inside
 * `HtmlTransformer::convertElement()`, so covering a decision like "empty
 * paragraph that is a runtime DOM target becomes a group" required driving a
 * full document through the pipeline with the right stylesheet and script
 * evidence attached. Here the deciding inputs are supplied directly.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RichTextElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RichTextElementConverter;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\RichTextMaterializationFixture;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$makeConverter = static function (array $overrides = array()): RichTextElementConverter {
    $defaults = array(
        'presentationAttributes'            => static fn (DOMElement $e, array $p, array $g): array => array(),
        'createBlock'                       => new SourceBlockCreatorFixture(static fn (string $n, array $a, array $i, ?DOMElement $s): array => array(
            'blockName'   => $n,
            'attrs'       => $a,
            'innerBlocks' => $i,
        )),
        'richTextContent'                   => static fn (DOMElement $e, array $x): string => (string) $e->textContent,
        'headingRichTextContent'            => static fn (string $c): string => $c,
        'richTextWithMaterializedSvgImages' => static fn (DOMElement $e, string $c): ?string => null,
        'requiresHtmlFallback'              => static fn (string $c): bool => false,
        'containsNativeSvgImageObject'      => static fn (string $c): bool => false,
        'htmlPreservationBlock'             => static fn (DOMElement $e): array => array('blockName' => 'core/html'),
        'authoredMarqueeBlock'              => static fn (DOMElement $e): ?array => null,
        'hasEmptyVisualInlineChild'         => static fn (DOMElement $e): bool => false,
        'hasBoxChromeWrapperStyling'        => static fn (DOMElement $e): bool => false,
        'isRuntimeDomTarget'                => static fn (DOMElement $e): bool => false,
        'convertText'                       => static fn (string $t): array => '' === $t ? array() : array(array('blockName' => 'core/paragraph', 'attrs' => array('content' => $t))),
        'convertChildren'                   => static function (DOMElement $e, array &$f, bool $c): array {
            return array();
        },
    );

    $c = array_merge($defaults, $overrides);

    return new RichTextElementConverter(new RichTextElementContext(
        new ElementPresentationResolverFixture($c['presentationAttributes']),
        $c['createBlock'],
        new RichTextMaterializationFixture(array(
            'content' => $c['richTextContent'],
            'headingContent' => $c['headingRichTextContent'],
            'contentWithMaterializedSvgImages' => $c['richTextWithMaterializedSvgImages'],
            'requiresHtmlFallback' => $c['requiresHtmlFallback'],
            'containsNativeSvgImageObject' => $c['containsNativeSvgImageObject'],
        )),
        $c['htmlPreservationBlock'],
        $c['authoredMarqueeBlock'],
        $c['hasEmptyVisualInlineChild'],
        $c['hasBoxChromeWrapperStyling'],
        $c['isRuntimeDomTarget'],
        $c['convertText'],
        new Runtime(),
        $c['convertChildren']
    ));
};

$elementFrom = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $node) {
        if ($node instanceof DOMElement) {
            return $node;
        }
    }
    throw new RuntimeException('No element parsed from: ' . $html);
};

$fallbacks = array();
$converter = $makeConverter();

// Ownership is closed and covers every heading level.
foreach (array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p') as $tag) {
    $assert($converter->handles($tag), 'handles-' . $tag);
}
foreach (array('h7', 'div', 'span', 'address', 'pre', 'hr') as $tag) {
    $assert(! $converter->handles($tag), 'does-not-handle-' . $tag);
}
$assert(! $converter->convert($elementFrom('<div>x</div>'), 'div', $fallbacks)->handled, 'unowned-tag-unhandled');

// Heading level is read from the tag, not guessed.
foreach (array(1, 2, 3, 4, 5, 6) as $level) {
    $block = $converter->convert($elementFrom("<h{$level}>Title</h{$level}>"), "h{$level}", $fallbacks)->block;
    $assert('core/heading' === ($block['blockName'] ?? ''), "h{$level}-is-heading");
    $assert($level === ($block['attrs']['level'] ?? null), "h{$level}-level");
}

// Empty rich text drops out rather than emitting an empty block.
$assert(null === $converter->convert($elementFrom('<h2>   </h2>'), 'h2', $fallbacks)->block, 'empty-heading-drops');

// Content that cannot survive as rich text falls back to core/html.
$fallbackConverter = $makeConverter(array('requiresHtmlFallback' => static fn (string $c): bool => true));
$assert('core/html' === ($fallbackConverter->convert($elementFrom('<h2>x</h2>'), 'h2', $fallbacks)->block['blockName'] ?? ''), 'heading-html-fallback');
$assert('core/html' === ($fallbackConverter->convert($elementFrom('<p>x</p>'), 'p', $fallbacks)->block['blockName'] ?? ''), 'paragraph-html-fallback');

// Heading content passes through the heading-specific normalizer.
$headingNormalized = $makeConverter(array('headingRichTextContent' => static fn (string $c): string => 'NORMALIZED:' . $c));
$assert('NORMALIZED:Title' === ($headingNormalized->convert($elementFrom('<h3>Title</h3>'), 'h3', $fallbacks)->block['attrs']['content'] ?? ''), 'heading-uses-heading-normalizer');

// A paragraph recognized as an authored marquee short-circuits everything else.
$marquee = $makeConverter(array('authoredMarqueeBlock' => static fn (DOMElement $e): ?array => array('blockName' => 'blocks-engine/marquee')));
$assert('blocks-engine/marquee' === ($marquee->convert($elementFrom('<p>scroll</p>'), 'p', $fallbacks)->block['blockName'] ?? ''), 'paragraph-marquee-short-circuits');

// Materialized inline SVG replaces the plain rich-text content. The materialized
// markup strips to an empty string, so it only survives as a paragraph because
// the native-SVG-image predicate claims it — the two travel together.
$svg = $makeConverter(array(
    'richTextWithMaterializedSvgImages' => static fn (DOMElement $e, string $c): ?string => '<img src="x.svg">',
    'containsNativeSvgImageObject'      => static fn (string $c): bool => str_contains($c, '.svg'),
));
$svgBlock = $svg->convert($elementFrom('<p>icon</p>'), 'p', $fallbacks)->block;
$assert('core/paragraph' === ($svgBlock['blockName'] ?? ''), 'materialized-svg-paragraph-survives');
$assert('<img src="x.svg">' === ($svgBlock['attrs']['content'] ?? ''), 'paragraph-uses-materialized-svg-content');

// Without that claim the same materialized markup is treated as empty and the
// converter falls back to the element's own text.
$svgUnclaimed = $makeConverter(array('richTextWithMaterializedSvgImages' => static fn (DOMElement $e, string $c): ?string => '<img src="x.svg">'));
$assert('icon' === ($svgUnclaimed->convert($elementFrom('<p>icon</p>'), 'p', $fallbacks)->block['attrs']['content'] ?? ''), 'unclaimed-svg-markup-falls-back-to-text');

// Box chrome around an empty inline child lowers to a group carrying children.
$chrome = $makeConverter(array(
    'richTextContent'            => static fn (DOMElement $e, array $x): string => '',
    'hasEmptyVisualInlineChild'  => static fn (DOMElement $e): bool => true,
    'hasBoxChromeWrapperStyling' => static fn (DOMElement $e): bool => true,
    'convertChildren'            => static function (DOMElement $e, array &$f, bool $c): array {
        return array(array('blockName' => 'core/spacer'));
    },
));
$chromeBlock = $chrome->convert($elementFrom('<p><span></span></p>'), 'p', $fallbacks)->block;
$assert('core/group' === ($chromeBlock['blockName'] ?? ''), 'box-chrome-paragraph-is-group');
$assert(1 === count($chromeBlock['innerBlocks'] ?? array()), 'box-chrome-group-keeps-children');

// An empty paragraph that scripts address by selector keeps a block in place.
$runtimeTarget = $makeConverter(array(
    'richTextContent'    => static fn (DOMElement $e, array $x): string => '',
    'isRuntimeDomTarget' => static fn (DOMElement $e): bool => true,
));
$assert('core/group' === ($runtimeTarget->convert($elementFrom('<p id="mount"></p>'), 'p', $fallbacks)->block['blockName'] ?? ''), 'empty-runtime-target-paragraph-is-group');

// An empty paragraph with no runtime claim produces nothing.
$emptyParagraph = $makeConverter(array('richTextContent' => static fn (DOMElement $e, array $x): string => ''));
$assert(null === $emptyParagraph->convert($elementFrom('<p></p>'), 'p', $fallbacks)->block, 'empty-paragraph-drops');

// Empty rich text that still carries a native SVG image object stays a paragraph.
$svgOnly = $makeConverter(array(
    'richTextContent'              => static fn (DOMElement $e, array $x): string => '<img src="i.svg">',
    'containsNativeSvgImageObject' => static fn (string $c): bool => true,
));
$assert('core/paragraph' === ($svgOnly->convert($elementFrom('<p><svg></svg></p>'), 'p', $fallbacks)->block['blockName'] ?? ''), 'svg-only-paragraph-survives');

// Plain text paragraph.
$plain = $converter->convert($elementFrom('<p>Hello</p>'), 'p', $fallbacks)->block;
$assert('core/paragraph' === ($plain['blockName'] ?? ''), 'plain-paragraph');
$assert('Hello' === ($plain['attrs']['content'] ?? ''), 'plain-paragraph-content');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Rich text element converter tests: ' . $assertions . " passed\n";

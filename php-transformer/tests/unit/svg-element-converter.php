<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgElementMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\SvgTextRunLowering;
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

$elementFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName('body')->item(0)?->firstElementChild;
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No element parsed');
};

$state = (object) array( 'mode' => 'block' );
$fallbackCaptures = 0;
$context = new SvgElementContext(
    new SourceElementClassifier(),
    static fn (DOMElement $element): bool => 'inert' === $state->mode,
    static fn (DOMElement $element): bool => str_starts_with($state->mode, 'runtime-'),
    static fn (DOMElement $element): string => '<svg viewbox="0 0 1 1"></svg>',
    static fn (string $html): bool => 'runtime-safe' === $state->mode,
    static fn (DOMElement $element): bool => str_starts_with($state->mode, 'drawable-'),
    new ElementPresentationResolverFixture(static fn (DOMElement $element): array => array( 'className' => 'visual-svg' )),
    new SourceBlockCreatorFixture(static fn (string $name, array $attrs, array $innerBlocks, ?DOMElement $source): array => array(
        'blockName' => $name,
        'attrs' => $attrs,
        'innerBlocks' => $innerBlocks,
        'sourceTag' => $source?->tagName,
    )),
    static function (DOMElement $element, array &$fallbacks) use (&$fallbackCaptures): void {
        ++$fallbackCaptures;
        $fallbacks[] = array( 'diagnostic_code' => 'html_inline_svg_fallback' );
    }
);
$materializer = new class($state) implements SvgElementMaterializer {
    public function __construct(private readonly object $state)
    {
    }

    public function inlineSvgBlockFromElement(DOMElement $element): ?array
    {
        return in_array($this->state->mode, array( 'block', 'drawable-block' ), true)
            ? array( 'blockName' => 'core/image', 'attrs' => array( 'source' => 'svg' ) )
            : null;
    }

    public function inlineSvgRichTextImageMarkup(DOMElement $element, bool $includeLink = true): ?string
    {
        return in_array($this->state->mode, array( 'phrasing', 'drawable-phrasing' ), true) ? '<img src="svg">' : null;
    }

    public function svgNeedsPhrasingHost(DOMElement $element): bool
    {
        return in_array($this->state->mode, array( 'phrasing', 'drawable-phrasing' ), true);
    }

    public function ensureInlineSvgBoxStyle(string $html, DOMElement $element): string
    {
        return $html . ':boxed';
    }

    public function restoreSvgCasing(string $html): string
    {
        return str_replace('viewbox', 'viewBox', $html);
    }

    public function isSafeDecorativeSvgElement(DOMElement $element): bool
    {
        return in_array($this->state->mode, array( 'visual', 'decorative-empty', 'drawable-block', 'drawable-phrasing', 'drawable-empty' ), true);
    }
};
$textRun = new SvgTextRunLowering(
    new ElementPresentationResolverFixture(static fn (DOMElement $element): array => array()),
    static fn (string $value, DOMElement $element): string => $value,
    new SourceBlockCreatorFixture(static fn (string $name, array $attrs, array $innerBlocks, ?DOMElement $source): array => array( 'blockName' => $name, 'attrs' => $attrs, 'sourceTag' => $source?->tagName )),
    static function (array $finding): void {}
);
$converter = new SvgElementConverter($context, $materializer, $textRun);
$svg = $elementFrom('<svg><path d="M0 0"></path></svg>');
$fallbacks = array();

$assert($converter->handles('svg') && ! $converter->handles('div'), 'handles-svg-only');
$unhandled = $converter->convert($elementFrom('<div></div>'), 'div', $fallbacks);
$assert(! $unhandled->handled, 'unhandled-outcome');

$state->mode = 'inert';
$inert = $converter->convert($svg, 'svg', $fallbacks);
$assert($inert->handled && null === $inert->block, 'inert-storage-dropped');

$state->mode = 'runtime-safe';
$runtime = $converter->convert($svg, 'svg', $fallbacks)->block;
$assert('core/html' === ($runtime['blockName'] ?? ''), 'safe-runtime-svg-preserved');
$assert(str_contains((string) ($runtime['attrs']['content'] ?? ''), 'viewBox') && str_contains((string) ($runtime['attrs']['content'] ?? ''), ':boxed'), 'runtime-svg-restored-and-sized');

$state->mode = 'drawable-phrasing';
$phrasing = $converter->convert($svg, 'svg', $fallbacks)->block;
$assert('core/paragraph' === ($phrasing['blockName'] ?? '') && '<img src="svg">' === ($phrasing['attrs']['content'] ?? ''), 'decorative-drawable-phrasing-host');
$assert('blocks-engine-synthetic-svg-paragraph' === ($phrasing['attrs']['className'] ?? ''), 'decorative-drawable-phrasing-removes-line-box');

$state->mode = 'drawable-block';
$drawable = $converter->convert($svg, 'svg', $fallbacks)->block;
$assert('core/image' === ($drawable['blockName'] ?? ''), 'decorative-drawable-block');

$drawableVisualLayer = $converter->convert($elementFrom('<svg id="Layer_1"><path d="M0 0"></path></svg>'), 'svg', $fallbacks)->block;
$assert('core/image' === ($drawableVisualLayer['blockName'] ?? ''), 'decorative-drawable-visual-layer');

$state->mode = 'visual';
$visual = $converter->convert($elementFrom('<svg class="decorative"><path d="M0 0"></path></svg>'), 'svg', $fallbacks)->block;
$assert('core/group' === ($visual['blockName'] ?? '') && 'visual-svg' === ($visual['attrs']['className'] ?? ''), 'visual-layer-carrier');

$state->mode = 'decorative-empty';
$decorative = $converter->convert($svg, 'svg', $fallbacks);
$assert($decorative->handled && null === $decorative->block, 'empty-decorative-svg-dropped');

$state->mode = 'phrasing';
$assert('core/paragraph' === ($converter->convert($svg, 'svg', $fallbacks)->block['blockName'] ?? ''), 'ordinary-phrasing-host');
$state->mode = 'block';
$assert('core/image' === ($converter->convert($svg, 'svg', $fallbacks)->block['blockName'] ?? ''), 'ordinary-svg-block');

$state->mode = 'fallback';
$fallbackCaptures = 0;
$fallbacks = array();
$fallback = $converter->convert($svg, 'svg', $fallbacks);
$assert($fallback->handled && null === $fallback->block, 'unmaterialized-svg-handled-without-block');
$assert(1 === $fallbackCaptures && 'html_inline_svg_fallback' === ($fallbacks[0]['diagnostic_code'] ?? ''), 'unmaterialized-svg-captures-fallback');

// A materialized SVG image is content-addressed by its visual payload. Engine
// class tokens are scoped to the compiling document and style nothing inside a
// standalone image, so the same icon compiled on two routes is one asset.
$svgIdentity = new ReflectionMethod(\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer::class, 'svgImageAssetIdentity');
$svgMaterializer = (new ReflectionClass(\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer::class))->newInstanceWithoutConstructor();
$iconOnRouteA = '<svg viewBox="0 0 20 20"><g class="blocks-engine-attribute-12b8d2529d4c-97"><path d="M0 0h20v20H0z" class="blocks-engine-attribute-12b8d2529d4c-98 icon-fill"></path></g></svg>';
$iconOnRouteB = '<svg viewBox="0 0 20 20"><g class="blocks-engine-attribute-bbf8754ba669-60"><path d="M0 0h20v20H0z" class="blocks-engine-attribute-bbf8754ba669-61 icon-fill"></path></g></svg>';
$otherIcon = '<svg viewBox="0 0 20 20"><g class="blocks-engine-attribute-bbf8754ba669-60"><path d="M0 0h10v10H0z" class="blocks-engine-attribute-bbf8754ba669-61 icon-fill"></path></g></svg>';
$assert($svgIdentity->invoke($svgMaterializer, $iconOnRouteA) === $svgIdentity->invoke($svgMaterializer, $iconOnRouteB), 'svg-asset-identity-ignores-document-scoped-engine-classes');
$assert($svgIdentity->invoke($svgMaterializer, $iconOnRouteA) !== $svgIdentity->invoke($svgMaterializer, $otherIcon), 'svg-asset-identity-keeps-distinct-geometry');
$assert(str_contains($svgIdentity->invoke($svgMaterializer, $iconOnRouteA), 'class="icon-fill"'), 'svg-asset-identity-keeps-authored-classes');

// Neutral artwork covers the issue's paragraph/div table and both inline and
// block image materialization, with accessible-name precedence shared by both.
$svgAlt = new ReflectionMethod($svgMaterializer, 'svgImageAlt');
$assert('T' === $svgAlt->invoke($svgMaterializer, $elementFrom('<svg alt="F"><title>T</title></svg>')), 'svg-title-element-precedes-alt');
$wrappers = array( '<p>%sN</p>', '<p>%s</p>', '<div>%s</div>', '%s' );
$names = array(
    'unnamed' => array( '', '', '' ),
    'alt-only' => array( 'alt="F"', '', 'F' ),
    'title-attribute' => array( 'title="T" alt="F"', '', 'T' ),
    'aria-label' => array( 'aria-label="A" title="T" alt="F"', '<title>T</title>', 'A' ),
    'hidden' => array( 'aria-hidden="true" aria-label="A" title="T" alt="F"', '<title>T</title>', '' ),
);
foreach ( $wrappers as $wrapper ) {
    foreach ( $names as $name => [$attributes, $title, $expectedAlt] ) {
        $artwork = '<svg ' . $attributes . ' viewBox="0 0 10 10">' . $title . '<path d="M0 0h10v10z"></path></svg>';
        $output = ( new HtmlTransformer() )->transform(sprintf($wrapper, $artwork))->toArray();
        $markup = (string) ($output['serialized_blocks'] ?? '');
        $label = $wrapper . ':' . $name;
        $assert(! str_contains($markup, '<!-- wp:html'), $label . ':no-core-html');
        $document = new DOMDocument();
        $document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
        $image = $document->getElementsByTagName('img')->item(0);
        $assert($image instanceof DOMElement && str_starts_with($image->getAttribute('src'), 'assets/materialized-svg/'), $label . ':materialized-image');
        $assert($image instanceof DOMElement && $image->hasAttribute('alt') && $expectedAlt === $image->getAttribute('alt'), $label . ':image-alt');
    }
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'SVG element converter tests: ' . $assertions . " passed\n";

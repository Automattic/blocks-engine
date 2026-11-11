<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonElementContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ButtonElementConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\ElementPresentationResolverFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\SourceBlockCreatorFixture;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\PatternTreeFixture;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body><button>Go</button><button><img src="x"></button><button><span class="v6-visually-hidden" aria-hidden="true">View fullsize</span><img src="logo.png" alt="Hero logo"></button><button><h3>Orangutan TT\'s</h3><p>70% Indica / 30% Sativa</p></button><div></div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$button = $document->getElementsByTagName('button')->item(0);
$imageButton = $document->getElementsByTagName('button')->item(1);
$hiddenLabelImageButton = $document->getElementsByTagName('button')->item(2);
$blockContentButton = $document->getElementsByTagName('button')->item(3);
$div = $document->getElementsByTagName('div')->item(0);
if ( ! $button instanceof DOMElement || ! $imageButton instanceof DOMElement || ! $hiddenLabelImageButton instanceof DOMElement || ! $blockContentButton instanceof DOMElement || ! $div instanceof DOMElement ) {
    throw new RuntimeException('Fixture elements not parsed');
}

$mode = 'generic';
$genericCalls = 0;
$captureUnsupported = null;
$genericBlock = array( 'blockName' => 'core/buttons' );
$converter = new ButtonElementConverter(new SourceElementClassifier(), new SourceBlockCreatorFixture(static fn (string $name, array $attributes, array $innerBlocks, DOMElement $element): array => array(
        'blockName' => $name,
        'attrs' => $attributes,
        'innerBlocks' => $innerBlocks,
    )), new ElementPresentationResolverFixture(static fn (DOMElement $element): array => array( 'className' => 'carrier' )), new ButtonElementContext(
    static function (DOMElement $element) use (&$mode): bool {
        return 'search' === $mode;
    },
    new PatternTreeFixture(children: static function (DOMElement $element, array &$fallbacks, bool $capture) use (&$mode, &$captureUnsupported): array {
        $captureUnsupported = $capture;
        $fallbacks[] = array( 'image' => true );
        if ( 'image' === $mode ) {
            return array( array( 'blockName' => 'core/image' ) );
        }
        if ( 'block-content' === $mode ) {
            return array(
                array( 'blockName' => 'core/heading' ),
                array( 'blockName' => 'core/paragraph' ),
            );
        }
        return array();
    }),
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

$hiddenLabel = $converter->convert($hiddenLabelImageButton, 'button', $fallbacks);
$assert('core/group' === ($hiddenLabel->block['blockName'] ?? ''), 'hidden-label-image-carrier-becomes-group');
$assert('core/image' === ($hiddenLabel->block['innerBlocks'][0]['blockName'] ?? ''), 'hidden-label-image-carrier-content-preserved');
$assert(0 === $genericCalls, 'hidden-label-image-carrier-short-circuits-generic-button');

$mode = 'empty-image';
$emptyImage = $converter->convert($imageButton, 'button', $fallbacks);
$assert(! $emptyImage->handled && 0 === $genericCalls, 'empty-image-carrier-does-not-become-a-text-button');

$mode = 'block-content';
$blockContent = $converter->convert($blockContentButton, 'button', $fallbacks);
$assert('core/group' === ($blockContent->block['blockName'] ?? ''), 'block-content-button-becomes-group');
$assert('core/heading' === ($blockContent->block['innerBlocks'][0]['blockName'] ?? '') && 'core/paragraph' === ($blockContent->block['innerBlocks'][1]['blockName'] ?? ''), 'block-content-button-keeps-heading-and-paragraph');
$assert(0 === $genericCalls, 'block-content-button-short-circuits-generic-button');

$mode = 'generic';
$generic = $converter->convert($button, 'button', $fallbacks);
$assert($generic->handled && 'core/buttons' === ($generic->block['blockName'] ?? ''), 'ordinary-button-delegated');

$galleryButton = ( new HtmlTransformer() )->transform('<main><h2>Gallery</h2><ul><li><button type="button" aria-label="Open photo 1"><img src="photo.webp" width="640" height="480" alt="Garden"><span>Garden</span></button></li></ul></main>')->toArray();
$galleryMarkup = (string) ($galleryButton['serialized_blocks'] ?? '');
$assert(str_contains($galleryMarkup, 'photo.webp'), 'gallery button keeps its source image');
$assert(1 === substr_count($galleryMarkup, '>Garden<'), 'gallery button caption is emitted exactly once');
foreach (array(
    'named icon and label' => '<button type="button"><img src="search.svg" width="16" height="16" alt="Search icon"> Search</button>',
    'unsized named icon and label' => '<button type="button"><img src="search.svg" alt="Search icon"> Search</button>',
    'decorative icon and label' => '<button type="button"><img src="search.svg" width="16" height="16" alt=""> Search</button>',
    'stylesheet-sized icon and label' => '<style>.b img { width: 20px; height: auto; }</style><button class="b" type="button"><img src="search.svg" alt="Search icon"> Search</button>',
    'fluid icon-scale icon and label' => '<style>.b img { width: clamp(16px, 2vw, 24px); height: auto; }</style><button class="b" type="button"><img src="search.svg" alt="Search icon"> Search</button>',
) as $label => $html) {
    $result = ( new HtmlTransformer() )->transform('<main>' . $html . '</main>')->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(str_contains($markup, '<!-- wp:button '), $label . ' stays a core/button');
    $assert(! str_contains($markup, '<!-- wp:group') && ! str_contains($markup, '<!-- wp:image'), $label . ' is not re-homed into media content');
}

foreach (array(
    'stylesheet-sized logo beside a distinct caption' => '<style>.b img { width: 160px; height: auto; }</style><button class="b" type="button"><img src="logo.png" alt="Example logo"><span>Other caption</span></button>',
    'attribute-sized photo beside a distinct caption' => '<button type="button"><img src="logo.png" width="640" height="480" alt="Example logo"><span>Other caption</span></button>',
    'fluid logo with a content-scale minimum beside a distinct caption' => '<style>.b img { width: clamp(320px, 30vw, 480px); height: auto; }</style><button class="b" type="button"><img src="logo.png" alt="Example logo"><span>Other caption</span></button>',
    'max()-sized logo beside a distinct caption' => '<style>.b img { width: max(20vw, 200px); height: auto; }</style><button class="b" type="button"><img src="logo.png" alt="Example logo"><span>Other caption</span></button>',
    'min-width-sized logo beside a distinct caption' => '<style>.b img { min-width: 240px; height: auto; }</style><button class="b" type="button"><img src="logo.png" alt="Example logo"><span>Other caption</span></button>',
) as $label => $html) {
    $result = ( new HtmlTransformer() )->transform('<main>' . $html . '<h1>Hero heading</h1></main>')->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(str_contains($markup, '<!-- wp:image') && str_contains($markup, 'src="logo.png" alt="Example logo"'), $label . ' keeps the content image as an image block');
    $assert(! str_contains($markup, 'Example logo<span>') && ! str_contains($markup, '<!-- wp:button '), $label . ' is not flattened into button alt text');
    $assert(1 === substr_count($markup, '>Other caption<'), $label . ' keeps its caption exactly once');
}

$nestedGallery = ( new HtmlTransformer() )->transform('<main><button type="button"><div style="aspect-ratio:4/3"><img src="photo.webp" alt="Garden" srcset="photo.webp 640w, photo-large.webp 1280w"></div><span>Garden</span></button></main>')->toArray();
$nestedGalleryMarkup = (string) ($nestedGallery['serialized_blocks'] ?? '');
$assert(str_contains($nestedGalleryMarkup, '<!-- wp:custom/responsive-media') && str_contains($nestedGalleryMarkup, 'photo.webp') && str_contains($nestedGalleryMarkup, 'photo-large.webp'), 'nested gallery retains responsive image candidates');
$assert(1 === substr_count($nestedGalleryMarkup, '>Garden<'), 'nested gallery caption is emitted once');

$iconButton = ( new HtmlTransformer() )->transform('<main><button type="button" aria-label="Previous photograph"><img src="icon.svg" alt=""></button></main>')->toArray();
$iconMarkup = (string) ($iconButton['serialized_blocks'] ?? '');
$assert(str_contains($iconMarkup, 'ariaLabel') && str_contains($iconMarkup, 'Previous photograph'), 'icon-only button accessible name is retained in authored button attributes');
$assert((bool) preg_match('/<button\b[^>]*aria-label="Previous photograph"[^>]*><img\b/', $iconMarkup), 'icon-only button saves as a named native button with its image');
$svgIconButton = ( new HtmlTransformer() )->transform('<main><button class="gallery-arrow gallery-arrow-left" id="previousButton" type="button" aria-label="Previous photograph"><svg viewBox="0 0 32 64" aria-hidden="true"><path d="M25 5L7 32L25 59"></path></svg></button></main>')->toArray();
$svgIconMarkup = (string) ($svgIconButton['serialized_blocks'] ?? '');
$assert(str_contains($svgIconMarkup, 'ariaLabel') && str_contains($svgIconMarkup, 'Previous photograph'), 'decorative SVG button keeps its accessible name in authored button attributes');
$assert((bool) preg_match('/<button\b[^>]*aria-label="Previous photograph"[^>]*><svg\b/', $svgIconMarkup), 'decorative SVG button saves as a named native button with SVG artwork');

$genericBlock = null;
$null = $converter->convert($button, 'button', $fallbacks);
$assert($null->handled && null === $null->block, 'generic-null-remains-handled');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Button element converter tests: ' . $assertions . " passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MediaTextPattern;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$assertTrue = static function (bool $actual, string $message) use (&$failures): void {
    if ( $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$assertNull = static function (mixed $actual, string $message) use (&$failures): void {
    if ( null === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' actual=' . var_export($actual, true) . PHP_EOL);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ( $expected === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};
$assertContains = static function (string $needle, string $actual, string $message) use (&$failures): void {
    if ( str_contains($actual, $needle) ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' missing=' . var_export($needle, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};

$elementFromHtml = static function (string $html, string $tagName = 'section'): DOMElement {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $element = $document->getElementsByTagName($tagName)->item(0);
    if ( ! $element instanceof DOMElement ) {
        throw new RuntimeException('Fixture did not produce expected DOMElement.');
    }

    return $element;
};
$htmlAttributes = static function (DOMElement $element): array {
    $attributes = array();
    foreach ( $element->attributes ?? array() as $attribute ) {
        $attributes[ $attribute->nodeName ] = $attribute->nodeValue ?? '';
    }

    return $attributes;
};
$transformHtml = static function (string $html): array {
    return ( new HtmlTransformer() )->transform($html)->toArray();
};

$pattern = new MediaTextPattern();

// Frozen public callback contract.
$matchMethod = new ReflectionMethod(MediaTextPattern::class, 'match');
$matchParameters = $matchMethod->getParameters();
$assertSame(
    array( 'element', 'fallbacks', 'convertChildren', 'convertElement', 'presentationAttributes', 'mergedPresentationStyle', 'htmlAttributes', 'resolveAssetUrl', 'createBlock' ),
    array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $matchParameters),
    'match callback parameter names remain frozen.'
);
$assertSame(
    array( 'DOMElement', 'array', 'callable', 'callable', 'callable', 'callable', 'callable', 'callable', 'callable' ),
    array_map(static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $matchParameters),
    'match callback parameter types remain frozen.'
);
$assertTrue($matchParameters[1]->isPassedByReference(), 'match fallbacks parameter remains passed by reference.');
$assertSame('?array', (string) $matchMethod->getReturnType(), 'match nullable-array return type remains frozen.');

$heading = array(
    'blockName'   => 'core/heading',
    'attrs'       => array( 'content' => 'Build' ),
    'innerBlocks' => array(),
);
$paragraph = array(
    'blockName'   => 'core/paragraph',
    'attrs'       => array( 'content' => 'Ship' ),
    'innerBlocks' => array(),
);

/**
 * @param array<int, array<string, mixed>> $convertedText
 * @param array<int, array<string, mixed>> $fallbacks
 * @param array<string, mixed> $record
 * @param array<string, mixed> $presentation
 * @return array<string, mixed>|null
 */
$match = static function (
    DOMElement $element,
    array $convertedText,
    array &$fallbacks,
    array &$record,
    array $presentation = array(),
    bool $emitFallback = false,
    bool $throwMediaStyle = false,
    ?callable $resolveMediaUrl = null,
    bool $throwCreate = false
) use ($pattern, $htmlAttributes): ?array {
    $record = array(
        'convertCalls'         => 0,
        'convertChildrenCalls' => 0,
        'convertedTag'         => null,
        'excluded'             => null,
    );

    $resolveMediaUrl ??= static fn (string $url): string => '/resolved/' . ltrim($url, '/');

    return $pattern->match(
        $element,
        $fallbacks,
        static function (DOMElement $sourceElement, array &$sourceFallbacks, bool $captureUnsupported) use (&$record): array {
            ++$record['convertChildrenCalls'];
            return array();
        },
        static function (DOMElement $sourceElement, array &$sourceFallbacks, bool $captureUnsupported) use (&$record, $convertedText, $emitFallback): ?array {
            ++$record['convertCalls'];
            $record['convertedTag'] = strtolower($sourceElement->tagName);
            if ( $emitFallback ) {
                $sourceFallbacks[] = array( 'type' => 'html', 'reason' => 'unsupported_element' );
            }
            if ( 1 === count($convertedText) ) {
                return array_values($convertedText)[0];
            }

            return array(
                'blockName'   => 'core/group',
                'attrs'       => array(),
                'innerBlocks' => $convertedText,
            );
        },
        static function (DOMElement $sourceElement, array $excludedGeometryProperties) use (&$record, $presentation): array {
            $record['excluded'] = $excludedGeometryProperties;
            return $presentation;
        },
        static function (DOMElement $sourceElement) use ($element, $throwMediaStyle): string {
            if ( $throwMediaStyle && ! $sourceElement->isSameNode($element) ) {
                throw new RuntimeException('media style unavailable');
            }

            return $sourceElement->getAttribute('style');
        },
        $htmlAttributes,
        $resolveMediaUrl,
        static function (string $name, array $attrs, array $innerBlocks, ?DOMElement $sourceElement) use ($throwCreate): array {
            if ( $throwCreate ) {
                throw new RuntimeException('block creation unavailable');
            }

            return array(
                'blockName'   => $name,
                'attrs'       => $attrs,
                'innerBlocks' => $innerBlocks,
            );
        }
    );
};

// Media-left defaults omit position, width, and stacking attrs.
$fallbacks = array();
$record = array();
$mediaLeftElement = $elementFromHtml(
    '<section class="feature"><!-- media --><figure><img src="left.jpg" alt="Left"></figure><div><h2>Build</h2></div></section>'
);
$mediaLeft = $match(
    $mediaLeftElement,
    array( $heading ),
    $fallbacks,
    $record,
    array( 'className' => 'feature', 'layout' => array( 'type' => 'flex' ) )
);
$assertSame('core/media-text', $mediaLeft['blockName'] ?? null, 'Media-left strict pair matches core/media-text.');
$assertSame('image', $mediaLeft['attrs']['mediaType'] ?? null, 'Image media emits mediaType image.');
$assertSame('/resolved/left.jpg', $mediaLeft['attrs']['mediaUrl'] ?? null, 'Image src passes through asset resolver.');
$assertSame('Left', $mediaLeft['attrs']['mediaAlt'] ?? null, 'Image alt passes through.');
$assertTrue(! array_key_exists('mediaPosition', $mediaLeft['attrs'] ?? array()), 'Default left position is omitted.');
$assertTrue(! array_key_exists('mediaWidth', $mediaLeft['attrs'] ?? array()), 'Default width is omitted.');
$assertTrue(! array_key_exists('isStackedOnMobile', $mediaLeft['attrs'] ?? array()), 'Default mobile stacking is omitted.');
$assertTrue(! array_key_exists('layout', $mediaLeft['attrs'] ?? array()), 'Consumed layout attr is removed.');
$assertSame(array( $heading ), $mediaLeft['innerBlocks'] ?? null, 'Converted text side becomes innerBlocks.');
$assertSame(1, $record['convertCalls'], 'Text side converts exactly once.');
$assertSame(0, $record['convertChildrenCalls'], 'Text side never converts through convertChildren coupling.');
$assertSame('div', $record['convertedTag'], 'Only text child is converted.');
$assertSame(array( 'display', 'grid-template-columns', 'align-items', 'gap' ), $record['excluded'], 'Presentation extraction excludes consumed geometry.');

// Host conversion preserves text-child identity while plain groups may hoist.
$headingResult = $transformHtml('<section><img src="x.jpg" alt=""><h2>Only head</h2></section>');
$headingMediaText = $headingResult['blocks'][0] ?? array();
$assertSame('core/media-text', $headingMediaText['blockName'] ?? null, 'Heading text side matches media-text.');
$assertSame('core/heading', $headingMediaText['innerBlocks'][0]['blockName'] ?? null, 'Heading text side keeps core/heading identity.');
$assertTrue(! array_key_exists('mediaAlt', $headingMediaText['attrs'] ?? array()), 'Empty image alt is omitted from media-text attrs.');

$quoteResult = $transformHtml('<section><img src="x.jpg"><blockquote><p>Quoted</p></blockquote></section>');
$assertSame('core/quote', $quoteResult['blocks'][0]['innerBlocks'][0]['blockName'] ?? null, 'Blockquote text side keeps core/quote identity.');

$styledTextResult = $transformHtml('<section><img src="x.jpg"><div class="copy-panel" style="padding:1rem"><p>Styled copy</p></div></section>');
$styledTextBlock = $styledTextResult['blocks'][0]['innerBlocks'][0] ?? array();
$assertSame('core/group', $styledTextBlock['blockName'] ?? null, 'Styled text wrapper keeps core/group identity.');
$assertSame('copy-panel', $styledTextBlock['attrs']['className'] ?? null, 'Styled text group keeps className.');
$assertSame('1rem', $styledTextBlock['attrs']['style']['spacing']['padding']['top'] ?? null, 'Styled text group keeps style attrs.');

$plainTextResult = $transformHtml('<section><img src="x.jpg"><div><h2>Head</h2><p>Copy</p></div></section>');
$plainTextBlocks = $plainTextResult['blocks'][0]['innerBlocks'] ?? array();
$assertSame(2, count($plainTextBlocks), 'Attr-less text group hoists both children.');
$assertSame('core/heading', $plainTextBlocks[0]['blockName'] ?? null, 'Hoisted first child keeps heading identity.');
$assertSame('core/paragraph', $plainTextBlocks[1]['blockName'] ?? null, 'Hoisted second child keeps paragraph identity.');

$inlineParagraphResult = $transformHtml('<section><img src="x.jpg"><p>Read <strong>now</strong>.</p></section>');
$inlineParagraphBlocks = $inlineParagraphResult['blocks'][0]['innerBlocks'] ?? array();
$assertSame(1, count($inlineParagraphBlocks), 'Single paragraph remains one inner block.');
$assertSame('core/paragraph', $inlineParagraphBlocks[0]['blockName'] ?? null, 'Single paragraph keeps core/paragraph identity.');
$assertSame('Read <strong>now</strong>.', $inlineParagraphBlocks[0]['attrs']['content'] ?? null, 'Single paragraph keeps inline markup intact.');

// Media-right emits position and uses media track, not left track.
$fallbacks = array();
$record = array();
$mediaRightElement = $elementFromHtml(
    '<section style="display:grid;grid-template-columns:auto 35%;align-items:center"><div><p>Ship</p></div><figure><img src="right.jpg"></figure></section>'
);
$mediaRight = $match($mediaRightElement, array( $paragraph ), $fallbacks, $record);
$assertSame('right', $mediaRight['attrs']['mediaPosition'] ?? null, 'Second media child emits right position.');
$assertSame(35, $mediaRight['attrs']['mediaWidth'] ?? null, 'Right media width derives from second grid track.');
$assertSame('center', $mediaRight['attrs']['verticalAlignment'] ?? null, 'align-items center maps to center.');

// Video media consumes video without alt.
$fallbacks = array();
$record = array();
$videoElement = $elementFromHtml('<section><div><video src="clip.mp4" controls></video></div><div><p>Watch</p></div></section>');
$video = $match($videoElement, array( $paragraph ), $fallbacks, $record);
$assertSame('video', $video['attrs']['mediaType'] ?? null, 'Video media emits mediaType video.');
$assertSame('/resolved/clip.mp4', $video['attrs']['mediaUrl'] ?? null, 'Video src passes through asset resolver.');
$assertTrue(! array_key_exists('mediaAlt', $video['attrs'] ?? array()), 'Video media omits mediaAlt.');

// Link wrapper attributes survive.
$fallbacks = array();
$record = array();
$linkedElement = $elementFromHtml(
    '<section><figure><a class="zoom" href="/full" target="_blank" rel="noopener"><picture><source srcset="large.webp 2x"><img src="fallback.jpg" alt="Fallback"></picture></a></figure><div><p>Open</p></div></section>'
);
$linked = $match($linkedElement, array( $paragraph ), $fallbacks, $record);
$assertSame('/resolved/fallback.jpg', $linked['attrs']['mediaUrl'] ?? null, 'Picture uses img fallback src, not source srcset.');
$assertSame('/full', $linked['attrs']['href'] ?? null, 'Link href passes through.');
$assertSame('_blank', $linked['attrs']['linkTarget'] ?? null, 'Link target maps to linkTarget.');
$assertSame('noopener', $linked['attrs']['rel'] ?? null, 'Link rel passes through.');
$assertSame('zoom', $linked['attrs']['linkClass'] ?? null, 'Link class maps to linkClass.');

// Link destinations use an anchored scheme allowlist.
foreach ( array(
    'http://example.com/full',
    'https://example.com/full',
    'mailto:editor@example.com',
    'tel:+15551234567',
    '//cdn.example.com/full',
    '/relative/full',
    '../relative/full',
    'relative/full',
) as $safeHref ) {
    $fallbacks = array();
    $record = array();
    $safeLinkedElement = $elementFromHtml('<section><a href="/placeholder"><img src="safe.jpg"></a><div><p>Safe copy</p></div></section>');
    $safeAnchor = $safeLinkedElement->getElementsByTagName('a')->item(0);
    if ( ! $safeAnchor instanceof DOMElement ) {
        throw new RuntimeException('Safe-link fixture did not produce anchor.');
    }
    $safeAnchor->setAttribute('href', $safeHref);
    $safeLinked = $match($safeLinkedElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($safeHref, $safeLinked['attrs']['href'] ?? null, 'Allowed link href survives: ' . $safeHref);
}

// Unsafe link destinations never reach media-text attrs.
foreach ( array( 'javascript:alert(1)', 'javascript :alert(1)', 'data:text/html,unsafe', 'ftp://example.com/file', 'vbscript:unsafe', "/ok\x01bad" ) as $unsafeHref ) {
    $fallbacks = array();
    $record = array();
    $unsafeLinkedElement = $elementFromHtml(
        '<section><figure><a class="unsafe-link" href="/placeholder" target="_blank" rel="noopener"><img src="safe.jpg" alt="Safe"></a></figure><div><p>Safe copy</p></div></section>'
    );
    $unsafeAnchor = $unsafeLinkedElement->getElementsByTagName('a')->item(0);
    if ( ! $unsafeAnchor instanceof DOMElement ) {
        throw new RuntimeException('Unsafe-link fixture did not produce anchor.');
    }
    $unsafeAnchor->setAttribute('href', $unsafeHref);
    $unsafeLinked = $match($unsafeLinkedElement, array( $paragraph ), $fallbacks, $record);
    $assertTrue(! array_key_exists('href', $unsafeLinked['attrs'] ?? array()), 'Unsafe link href is omitted: ' . json_encode($unsafeHref));
    $assertSame('_blank', $unsafeLinked['attrs']['linkTarget'] ?? null, 'Unsafe href does not erase anchor target metadata.');
    $assertSame('noopener', $unsafeLinked['attrs']['rel'] ?? null, 'Unsafe href does not erase anchor rel metadata.');
    $assertSame('unsafe-link', $unsafeLinked['attrs']['linkClass'] ?? null, 'Unsafe href does not erase anchor class metadata.');
}

$unsafeLinkResult = $transformHtml(
    '<section><figure><a class="unsafe-link" href="javascript:alert(1)" target="_blank" rel="noopener"><img src="safe.jpg" alt="Safe"></a></figure><div><p>Safe copy</p></div></section>'
);
$unsafeLinkBlock = $unsafeLinkResult['blocks'][0] ?? array();
$assertSame('core/media-text', $unsafeLinkBlock['blockName'] ?? null, 'Unsafe linked media still converts with safe media URL.');
$assertTrue(! array_key_exists('href', $unsafeLinkBlock['attrs'] ?? array()), 'Unsafe href is absent from emitted block attrs.');
$assertTrue(! str_contains((string) ($unsafeLinkBlock['innerHTML'] ?? ''), 'javascript'), 'Unsafe href is absent from emitted markup.');

$substringLinkResult = $transformHtml(
    '<section><figure><a href="https://e.com/blog/what-is-javascript:-a-primer"><img src="x.jpg"></a></figure><div><p>Copy</p></div></section>'
);
$assertSame(
    'https://e.com/blog/what-is-javascript:-a-primer',
    $substringLinkResult['blocks'][0]['attrs']['href'] ?? null,
    'javascript: substring outside leading scheme survives link allowlist.'
);

// Resolved media URLs use image-safe schemes; unsafe media declines pre-conversion.
foreach ( array(
    'http://example.com/media.jpg',
    'https://example.com/media.jpg',
    '//cdn.example.com/media.jpg',
    '/images/media.jpg',
    '../images/media.jpg',
    'images/media.jpg',
    'data:image/png;base64,AAAA',
) as $safeMediaUrl ) {
    $fallbacks = array();
    $record = array();
    $safeMediaElement = $elementFromHtml('<section><img src="placeholder.jpg"><div><p>Safe media</p></div></section>');
    $safeImage = $safeMediaElement->getElementsByTagName('img')->item(0);
    if ( ! $safeImage instanceof DOMElement ) {
        throw new RuntimeException('Safe-media fixture did not produce image.');
    }
    $safeImage->setAttribute('src', $safeMediaUrl);
    $safeMedia = $match(
        $safeMediaElement,
        array( $paragraph ),
        $fallbacks,
        $record,
        array(),
        false,
        false,
        static fn (string $url): string => $url
    );
    $assertSame($safeMediaUrl, $safeMedia['attrs']['mediaUrl'] ?? null, 'Allowed media URL survives: ' . $safeMediaUrl);
}

foreach ( array( 'javascript:alert(1)', 'data:text/html,unsafe', 'ftp://example.com/media.jpg', 'file:///tmp/media.jpg', "bad\x01media.jpg" ) as $unsafeMediaUrl ) {
    $fallbacks = array();
    $record = array();
    $unsafeMediaElement = $elementFromHtml('<section><img src="placeholder.jpg"><div><p>Unsafe media</p></div></section>');
    $unsafeImage = $unsafeMediaElement->getElementsByTagName('img')->item(0);
    if ( ! $unsafeImage instanceof DOMElement ) {
        throw new RuntimeException('Unsafe-media fixture did not produce image.');
    }
    $unsafeImage->setAttribute('src', $unsafeMediaUrl);
    $unsafeMedia = $match(
        $unsafeMediaElement,
        array( $paragraph ),
        $fallbacks,
        $record,
        array(),
        false,
        false,
        static fn (string $url): string => $url
    );
    $assertNull($unsafeMedia, 'Unsafe media URL declines: ' . json_encode($unsafeMediaUrl));
    $assertSame(0, $record['convertCalls'], 'Unsafe media URL declines before text conversion.');
}

$fallbacks = array();
$record = array();
$unsafeResolvedMedia = $match(
    $mediaLeftElement,
    array( $heading ),
    $fallbacks,
    $record,
    array(),
    false,
    false,
    static fn (string $url): string => 'javascript:resolved'
);
$assertNull($unsafeResolvedMedia, 'Unsafe resolved media URL declines match.');
$assertSame(0, $record['convertCalls'], 'Unsafe resolved media URL declines before text conversion.');

// Width derives from media-child flex-basis, then width, and from two fr tracks.
$fallbacks = array();
$record = array();
$flexBasisElement = $elementFromHtml('<section><figure style="flex-basis:42%"><img src="basis.jpg"></figure><div><p>Basis</p></div></section>');
$flexBasis = $match($flexBasisElement, array( $paragraph ), $fallbacks, $record);
$assertSame(42, $flexBasis['attrs']['mediaWidth'] ?? null, 'Media flex-basis percentage derives width.');

$fallbacks = array();
$record = array();
$widthElement = $elementFromHtml('<section><figure style="width:37.6%"><img src="width.jpg"></figure><div><p>Width</p></div></section>');
$width = $match($widthElement, array( $paragraph ), $fallbacks, $record);
$assertSame(38, $width['attrs']['mediaWidth'] ?? null, 'Media width percentage rounds to nearest integer.');

// Media-child style resolution failures decline and discard local fallbacks.
$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$styleFailure = $match($mediaLeftElement, array( $heading ), $fallbacks, $record, array(), true, true);
$assertNull($styleFailure, 'Media-child style failure declines match.');
$assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Media-child style failure leaves host fallbacks unchanged.');

$fallbacks = array();
$record = array();
$frElement = $elementFromHtml('<section style="display:grid;grid-template-columns:2fr 3fr"><figure><img src="fr.jpg"></figure><div><p>Fr</p></div></section>');
$fr = $match($frElement, array( $paragraph ), $fallbacks, $record);
$assertSame(40, $fr['attrs']['mediaWidth'] ?? null, 'Two fr tracks derive media share.');

foreach ( array( '30% 24rem' => 30, '35% minmax(10rem,1fr)' => 35 ) as $gridTemplate => $expectedWidth ) {
    $fallbacks = array();
    $record = array();
    $mixedTrackElement = $elementFromHtml('<section style="display:grid;grid-template-columns:' . $gridTemplate . '"><img src="mixed.jpg"><div><p>Mixed</p></div></section>');
    $mixedTrack = $match($mixedTrackElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($expectedWidth, $mixedTrack['attrs']['mediaWidth'] ?? null, 'Bare percentage media track ignores other track unit: ' . $gridTemplate);
}

foreach ( array(
    'grid-template-columns:30% auto',
    'display:flex;grid-template-columns:30% auto',
    'display:inline-grid;grid-template-columns:30% auto',
) as $inactiveGridStyle ) {
    $fallbacks = array();
    $record = array();
    $inactiveGridElement = $elementFromHtml('<section style="' . $inactiveGridStyle . '"><img src="inactive-grid.jpg"><div><p>Inactive grid</p></div></section>');
    $inactiveGrid = $match($inactiveGridElement, array( $paragraph ), $fallbacks, $record);
    $assertTrue(! array_key_exists('mediaWidth', $inactiveGrid['attrs'] ?? array()), 'Grid tracks ignored unless display resolves exactly grid: ' . $inactiveGridStyle);
}

foreach ( array( 14 => null, 15 => 15, 85 => 85, 86 => null ) as $sourceWidth => $expectedWidth ) {
    $fallbacks = array();
    $record = array();
    $boundedWidthElement = $elementFromHtml('<section style="display:grid;grid-template-columns:' . $sourceWidth . '% auto"><img src="bounded.jpg"><div><p>Bounded</p></div></section>');
    $boundedWidth = $match($boundedWidthElement, array( $paragraph ), $fallbacks, $record);
    $actualWidth = $boundedWidth['attrs']['mediaWidth'] ?? null;
    $assertSame($expectedWidth, $actualWidth, 'mediaWidth emits only within inclusive 15..85 range: ' . $sourceWidth);
}

// Vertical alignment mapping covers all core values.
foreach ( array( 'flex-start' => 'top', 'start' => 'top', 'center' => 'center', 'flex-end' => 'bottom', 'end' => 'bottom' ) as $alignItems => $expectedAlignment ) {
    $fallbacks = array();
    $record = array();
    $alignmentElement = $elementFromHtml('<section style="display:flex;align-items:' . $alignItems . '"><figure><img src="align.jpg"></figure><div><p>Align</p></div></section>');
    $alignment = $match($alignmentElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($expectedAlignment, $alignment['attrs']['verticalAlignment'] ?? null, 'align-items ' . $alignItems . ' maps to core value.');
}

foreach ( array(
    'align-items:center',
    'display:block;align-items:center',
    'display:inline-flex;align-items:center',
    'display:inline-grid;align-items:center',
) as $inactiveAlignmentStyle ) {
    $fallbacks = array();
    $record = array();
    $inactiveAlignmentElement = $elementFromHtml('<section style="' . $inactiveAlignmentStyle . '"><img src="inactive-align.jpg"><div><p>Align</p></div></section>');
    $inactiveAlignment = $match($inactiveAlignmentElement, array( $paragraph ), $fallbacks, $record);
    $assertTrue(! array_key_exists('verticalAlignment', $inactiveAlignment['attrs'] ?? array()), 'Alignment ignored unless display resolves exactly flex or grid: ' . $inactiveAlignmentStyle);
}

$fallbacks = array();
$record = array();
$gridEndElement = $elementFromHtml('<section style="display:grid;align-items:end"><img src="grid-end.jpg"><div><p>Grid end</p></div></section>');
$gridEnd = $match($gridEndElement, array( $paragraph ), $fallbacks, $record);
$assertSame('bottom', $gridEnd['attrs']['verticalAlignment'] ?? null, 'Grid align-items end maps to bottom.');

// Media-side impurity declines before text conversion.
$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$captionElement = $elementFromHtml('<section class="media-text"><figure><img src="caption.jpg"><figcaption>Caption</figcaption></figure><div><p>Copy</p></div></section>');
$assertNull($match($captionElement, array( $paragraph ), $fallbacks, $record, array(), true), 'Figcaption makes media side impure.');
$assertSame(0, $record['convertCalls'], 'Impure media declines before text conversion.');
$assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Impure decline leaves host fallbacks unchanged.');

// A second media-bearing pane is gallery-shaped, not text-bearing.
$fallbacks = array();
$record = array();
$ambiguousGalleryElement = $elementFromHtml(
    '<div class="media-grid" data-layout="grid"><figure><img src="c.jpg" alt="C"></figure><figure class="tile"><img src="d.jpg" alt="D"><figcaption>D caption</figcaption></figure></div>',
    'div'
);
$assertNull($match($ambiguousGalleryElement, array( $paragraph ), $fallbacks, $record), 'Second pane with media descendant declines as ambiguous gallery.');
$assertSame(0, $record['convertCalls'], 'Second media-bearing pane declines before text conversion.');

// Exactly three element children decline before conversion.
$fallbacks = array();
$record = array();
$threeChildrenElement = $elementFromHtml('<section><figure><img src="three.jpg"></figure><div><p>Copy</p></div><aside>Extra</aside></section>');
$assertNull($match($threeChildrenElement, array( $paragraph ), $fallbacks, $record), 'Three element children decline.');
$assertSame(0, $record['convertCalls'], 'Three-child decline avoids conversion.');

// Strict layout-direction gates decline before text conversion.
foreach ( array(
    'row reverse' => '<section style="display:flex;flex-direction:row-reverse"><img src="reverse.jpg"><div><p>Reverse</p></div></section>',
    'media order' => '<section style="display:flex"><img style="order:0" src="ordered.jpg"><div><p>Ordered</p></div></section>',
    'text order'  => '<section style="display:grid"><img src="ordered.jpg"><div style="order:2"><p>Ordered</p></div></section>',
    'rtl'         => '<section style="display:grid;direction:rtl"><img src="rtl.jpg"><div><p>RTL</p></div></section>',
) as $gateName => $gateHtml ) {
    $fallbacks = array( array( 'reason' => 'existing' ) );
    $record = array();
    $gatedElement = $elementFromHtml($gateHtml);
    $assertNull($match($gatedElement, array( $paragraph ), $fallbacks, $record, array(), true), 'Strict layout gate declines: ' . $gateName);
    $assertSame(0, $record['convertCalls'], 'Strict layout gate runs before text conversion: ' . $gateName);
    $assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Strict layout decline leaves host fallbacks unchanged: ' . $gateName);
}

$fallbacks = array();
$record = array();
$rowElement = $elementFromHtml('<section style="display:flex;flex-direction:row"><img src="row.jpg"><div><p>Row</p></div></section>');
$row = $match($rowElement, array( $paragraph ), $fallbacks, $record);
$assertSame('core/media-text', $row['blockName'] ?? null, 'Normal flex row remains eligible.');

// Link-wrapped video is not representable by core/media-text save markup.
$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$linkedVideoElement = $elementFromHtml('<section><a href="https://example.com/go"><video src="clip.mp4"></video></a><div><p>Copy</p></div></section>');
$assertNull($match($linkedVideoElement, array( $paragraph ), $fallbacks, $record, array(), true), 'Link-wrapped video declines.');
$assertSame(0, $record['convertCalls'], 'Link-wrapped video declines before text conversion.');
$assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Link-wrapped video decline leaves host fallbacks unchanged.');

// Vertical flex declines before conversion and leaves local fallbacks untouched.
$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$verticalElement = $elementFromHtml('<section style="display:flex;flex-direction:column"><figure><img src="stacked.jpg"></figure><div><p>Stacked</p></div></section>');
$assertNull($match($verticalElement, array( $paragraph ), $fallbacks, $record, array(), true), 'Vertical flex container declines.');
$assertSame(0, $record['convertCalls'], 'Vertical gate runs before text conversion.');
$assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Vertical decline discards text-side local fallbacks.');

// Converted text side must contain a recursive text-bearing block.
$fallbacks = array();
$record = array();
$imageOnly = array(
    'blockName'   => 'core/group',
    'attrs'       => array(),
    'innerBlocks' => array( array( 'blockName' => 'core/image', 'attrs' => array(), 'innerBlocks' => array() ) ),
);
$assertNull($match($mediaLeftElement, array( $imageOnly ), $fallbacks, $record), 'Non-text text side declines.');
$assertSame(1, $record['convertCalls'], 'Non-text side converts once for gate and output reuse.');

// Underivable grid geometries omit mediaWidth.
foreach ( array( 'minmax(12rem,1fr) auto', '1fr 40%' ) as $gridTemplate ) {
    $fallbacks = array();
    $record = array();
    $underivableElement = $elementFromHtml('<section style="display:grid;grid-template-columns:' . $gridTemplate . '"><figure style="width:25%"><img src="unknown.jpg"></figure><div><p>Unknown</p></div></section>');
    $underivable = $match($underivableElement, array( $paragraph ), $fallbacks, $record);
    $assertTrue(! array_key_exists('mediaWidth', $underivable['attrs'] ?? array()), 'Underivable grid width is omitted: ' . $gridTemplate);
}

// Text-side fallbacks push only after successful block creation.
$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$matchedWithFallback = $match($mediaLeftElement, array( $heading ), $fallbacks, $record, array(), true);
$assertSame('core/media-text', $matchedWithFallback['blockName'] ?? null, 'Text fallback does not suppress valid match.');
$assertSame(
    array( array( 'reason' => 'existing' ), array( 'type' => 'html', 'reason' => 'unsupported_element' ) ),
    $fallbacks,
    'Matched text-side fallback pushes to host accumulator.'
);

$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$createFailure = $match(
    $mediaLeftElement,
    array( $heading ),
    $fallbacks,
    $record,
    array(),
    true,
    false,
    null,
    true
);
$assertNull($createFailure, 'Block creation failure declines match.');
$assertSame(1, $record['convertCalls'], 'Block creation failure occurs after one text conversion.');
$assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Block creation failure discards text-side local fallbacks.');

// Ladder fallthrough remains unchanged for strict declines.
$geometryResult = $transformHtml(
    '<section style="display:grid;grid-template-columns:30% auto;max-width:900px;min-height:30rem;aspect-ratio:16/9;--media-gap:2rem;padding:1rem"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>'
);
$geometryBlock = $geometryResult['blocks'][0] ?? array();
$geometryOpening = (string) ($geometryBlock['innerContent'][0] ?? '');
$geometryAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $geometryResult['assets'] ?? array()));
$assertSame('core/media-text', $geometryBlock['blockName'] ?? null, 'Grid geometry case emits media-text.');
$assertTrue(! isset($geometryBlock['attrs']['style']['dimensions']['maxWidth']), 'Media-text attrs omit maxWidth.');
$assertContains('style="grid-template-columns:30% auto"', $geometryOpening, 'Media-text wrapper style contains grid tracks.');
foreach ( array( 'max-width', 'min-height', 'aspect-ratio', '--media-gap', 'padding-' ) as $leakedProperty ) {
    $assertTrue(! str_contains($geometryOpening, $leakedProperty), 'Media-text wrapper style omits source property: ' . $leakedProperty);
}
$assertContains('max-width:900px !important', $geometryAssets, 'Carrier stylesheet preserves source max-width.');
$assertContains('min-height:30rem !important', $geometryAssets, 'Carrier stylesheet preserves source min-height.');
$assertContains('aspect-ratio:16/9 !important', $geometryAssets, 'Carrier stylesheet preserves source aspect-ratio.');

$rowReverseResult = $transformHtml('<section style="display:flex;flex-direction:row-reverse"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($rowReverseResult['blocks'][0]['blockName'] ?? null), 'Flex row-reverse falls through without media-text.');

$linkedVideoResult = $transformHtml('<section><a href="https://e.com/go"><video src="v.mp4"></video></a><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($linkedVideoResult['blocks'][0]['blockName'] ?? null), 'Linked video falls through without media-text.');

$captionResult = $transformHtml('<section class="media-text"><figure><img src="caption.jpg"><figcaption>Caption</figcaption></figure><div><p>Copy</p></div></section>');
$assertSame('core/columns', $captionResult['blocks'][0]['blockName'] ?? null, 'Figcaption decline falls through to existing columns path.');

$ambiguousGalleryResult = $transformHtml(
    '<div class="media-grid" data-layout="grid"><figure><img src="c.jpg" alt="C"></figure><figure class="tile"><img src="d.jpg" alt="D"><figcaption>D caption</figcaption></figure></div>'
);
$assertSame('core/gallery', $ambiguousGalleryResult['blocks'][0]['blockName'] ?? null, 'Two media-bearing figure panes remain core/gallery.');

$threeChildrenResult = $transformHtml('<section style="display:flex"><figure><img src="three.jpg"></figure><div><p>Copy</p></div><aside>Extra</aside></section>');
$assertSame('core/columns', $threeChildrenResult['blocks'][0]['blockName'] ?? null, 'Three-child decline falls through to existing columns path.');

$verticalResult = $transformHtml('<section class="media-text" style="display:flex;flex-direction:column"><figure><img src="stacked.jpg"></figure><div><p>Stacked</p></div></section>');
$assertTrue('core/media-text' !== ($verticalResult['blocks'][0]['blockName'] ?? null), 'Vertical flex decline never emits media-text.');
$assertTrue('core/columns' !== ($verticalResult['blocks'][0]['blockName'] ?? null), 'Vertical flex decline keeps existing columns rejection.');

// Existing wp-block-media-text markup round-trips through same strict gate.
$roundTripResult = $transformHtml(
    '<div class="wp-block-media-text has-media-on-the-right is-stacked-on-mobile">'
    . '<div class="wp-block-media-text__content"><p>Round trip</p></div>'
    . '<figure class="wp-block-media-text__media"><img src="round-trip.jpg" alt="Round"></figure>'
    . '</div>'
);
$roundTrip = $roundTripResult['blocks'][0] ?? array();
$assertSame('core/media-text', $roundTrip['blockName'] ?? null, 'wp-block-media-text markup passes strict round-trip gate.');
$assertSame('right', $roundTrip['attrs']['mediaPosition'] ?? null, 'Round-trip DOM order restores right position.');
$assertContains('has-media-on-the-right', (string) ($roundTrip['innerHTML'] ?? ''), 'Round-trip save shape restores right class.');

// Emitted media-text markup passes Runtime serialization validation.
$runtime = new Runtime();
$serializedRoundTrip = $runtime->serializeBlocks(array( $roundTrip ));
$validity = $runtime->validateBlockSerialization($serializedRoundTrip);
$assertSame('pass', $validity['status'] ?? null, 'Emitted media-text markup passes serialization validity.');

if ( 0 === $failures ) {
    echo "media text pattern ok\n";
}

exit(0 === $failures ? 0 : 1);

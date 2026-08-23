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
    bool $throwCreate = false,
    ?callable $resolvePresentationStyle = null
) use ($pattern, $htmlAttributes): ?array {
    $record = array(
        'convertCalls'         => 0,
        'convertChildrenCalls' => 0,
        'convertedTag'         => null,
        'excluded'             => null,
    );

    $resolveMediaUrl ??= static fn (string $url): string => '/resolved/' . ltrim($url, '/');
    $resolvePresentationStyle ??= static fn (DOMElement $sourceElement): string => $sourceElement->getAttribute('style');

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
        static function (DOMElement $sourceElement) use ($element, $throwMediaStyle, $resolvePresentationStyle): string {
            if ( $throwMediaStyle && ! $sourceElement->isSameNode($element) ) {
                throw new RuntimeException('media style unavailable');
            }

            return $resolvePresentationStyle($sourceElement);
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
    '<section class="feature" style="display:flex"><!-- media --><figure><img src="left.jpg" alt="Left"></figure><div><h2>Build</h2></div></section>'
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
$headingResult = $transformHtml('<section style="display:flex"><img src="x.jpg" alt=""><h2>Only head</h2></section>');
$headingMediaText = $headingResult['blocks'][0] ?? array();
$assertSame('core/media-text', $headingMediaText['blockName'] ?? null, 'Heading text side matches media-text.');
$assertSame('core/heading', $headingMediaText['innerBlocks'][0]['blockName'] ?? null, 'Heading text side keeps core/heading identity.');
$assertTrue(! array_key_exists('mediaAlt', $headingMediaText['attrs'] ?? array()), 'Empty image alt is omitted from media-text attrs.');

$quoteResult = $transformHtml('<section style="display:flex"><img src="x.jpg"><blockquote><p>Quoted</p></blockquote></section>');
$assertSame('core/quote', $quoteResult['blocks'][0]['innerBlocks'][0]['blockName'] ?? null, 'Blockquote text side keeps core/quote identity.');

$styledTextResult = $transformHtml('<section style="display:flex"><img src="x.jpg"><div class="copy-panel" style="padding:1rem"><p>Styled copy</p></div></section>');
$styledTextBlock = $styledTextResult['blocks'][0]['innerBlocks'][0] ?? array();
$assertSame('core/group', $styledTextBlock['blockName'] ?? null, 'Styled text wrapper keeps core/group identity.');
$assertSame('copy-panel blocks-engine-css-owned-layout', $styledTextBlock['attrs']['className'] ?? null, 'Styled text group keeps className plus layout-item marker.');
$assertSame('1rem', $styledTextBlock['attrs']['style']['spacing']['padding']['top'] ?? null, 'Styled text group keeps style attrs.');

$plainTextResult = $transformHtml('<section style="display:flex"><img src="x.jpg"><div><h2>Head</h2><p>Copy</p></div></section>');
$plainTextBlocks = $plainTextResult['blocks'][0]['innerBlocks'] ?? array();
$assertSame(2, count($plainTextBlocks), 'Attr-less text group hoists both children.');
$assertSame('core/heading', $plainTextBlocks[0]['blockName'] ?? null, 'Hoisted first child keeps heading identity.');
$assertSame('core/paragraph', $plainTextBlocks[1]['blockName'] ?? null, 'Hoisted second child keeps paragraph identity.');

$inlineParagraphResult = $transformHtml('<section style="display:flex"><img src="x.jpg"><p>Read <strong>now</strong>.</p></section>');
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
$videoElement = $elementFromHtml('<section style="display:flex"><div><video src="clip.mp4" controls></video></div><div><p>Watch</p></div></section>');
$video = $match($videoElement, array( $paragraph ), $fallbacks, $record);
$assertSame('video', $video['attrs']['mediaType'] ?? null, 'Video media emits mediaType video.');
$assertSame('/resolved/clip.mp4', $video['attrs']['mediaUrl'] ?? null, 'Video src passes through asset resolver.');
$assertTrue(! array_key_exists('mediaAlt', $video['attrs'] ?? array()), 'Video media omits mediaAlt.');

// Link wrapper attributes survive.
$fallbacks = array();
$record = array();
$linkedElement = $elementFromHtml(
    '<section style="display:flex"><figure><a class="zoom" href="/full" target="_blank" rel="noopener"><picture><source srcset="large.webp 2x"><img src="fallback.jpg" alt="Fallback"></picture></a></figure><div><p>Open</p></div></section>'
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
    $safeLinkedElement = $elementFromHtml('<section style="display:flex"><a href="/placeholder"><img src="safe.jpg"></a><div><p>Safe copy</p></div></section>');
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
        '<section style="display:flex"><figure><a class="unsafe-link" href="/placeholder" target="_blank" rel="noopener"><img src="safe.jpg" alt="Safe"></a></figure><div><p>Safe copy</p></div></section>'
    );
    $unsafeAnchor = $unsafeLinkedElement->getElementsByTagName('a')->item(0);
    if ( ! $unsafeAnchor instanceof DOMElement ) {
        throw new RuntimeException('Unsafe-link fixture did not produce anchor.');
    }
    $unsafeAnchor->setAttribute('href', $unsafeHref);
    $unsafeLinked = $match($unsafeLinkedElement, array( $paragraph ), $fallbacks, $record);
    $assertTrue(! array_key_exists('href', $unsafeLinked['attrs'] ?? array()), 'Unsafe link href is omitted: ' . json_encode($unsafeHref));
    $assertSame(null, $unsafeLinked['attrs']['linkTarget'] ?? null, 'Rejected href drops anchor target metadata.');
    $assertSame(null, $unsafeLinked['attrs']['rel'] ?? null, 'Rejected href drops anchor rel metadata.');
    $assertSame(null, $unsafeLinked['attrs']['linkClass'] ?? null, 'Rejected href drops anchor class metadata.');
}

$unsafeLinkResult = $transformHtml(
    '<section style="display:flex"><figure><a class="unsafe-link" href="javascript:alert(1)" target="_blank" rel="noopener"><img src="safe.jpg" alt="Safe"></a></figure><div><p>Safe copy</p></div></section>'
);
$unsafeLinkBlock = $unsafeLinkResult['blocks'][0] ?? array();
$assertSame('core/media-text', $unsafeLinkBlock['blockName'] ?? null, 'Unsafe linked media still converts with safe media URL.');
$assertTrue(! array_key_exists('href', $unsafeLinkBlock['attrs'] ?? array()), 'Unsafe href is absent from emitted block attrs.');
$assertTrue(! str_contains((string) ($unsafeLinkBlock['innerHTML'] ?? ''), 'javascript'), 'Unsafe href is absent from emitted markup.');

$substringLinkResult = $transformHtml(
    '<section style="display:flex"><figure><a href="https://e.com/blog/what-is-javascript:-a-primer"><img src="x.jpg"></a></figure><div><p>Copy</p></div></section>'
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
    $safeMediaElement = $elementFromHtml('<section style="display:flex"><img src="placeholder.jpg"><div><p>Safe media</p></div></section>');
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

foreach ( array( 'javascript:alert(1)', 'data:text/html,unsafe', 'data:image/svg+xml;base64,AAAA', 'data:image/SVG;base64,AAAA', 'ftp://example.com/media.jpg', 'file:///tmp/media.jpg', "bad\x01media.jpg" ) as $unsafeMediaUrl ) {
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
$flexBasisElement = $elementFromHtml('<section style="display:flex"><figure style="flex-basis:42%"><img src="basis.jpg"></figure><div><p>Basis</p></div></section>');
$flexBasis = $match($flexBasisElement, array( $paragraph ), $fallbacks, $record);
$assertSame(42, $flexBasis['attrs']['mediaWidth'] ?? null, 'Media flex-basis percentage derives width.');

$fallbacks = array();
$record = array();
$widthElement = $elementFromHtml('<section style="display:flex"><figure style="width:37.6%"><img src="width.jpg"></figure><div><p>Width</p></div></section>');
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
    'grid-template-columns:30% auto' => 30,
    'display:block;grid-template-columns:30% auto' => 30,
    'display:flex;grid-template-columns:30% auto' => null,
    'display:inline-grid;grid-template-columns:30% auto' => 30,
) as $gridIntentStyle => $expectedWidth ) {
    $fallbacks = array();
    $record = array();
    $gridIntentElement = $elementFromHtml('<section style="' . $gridIntentStyle . '"><img src="grid-intent.jpg"><div><p>Grid intent</p></div></section>');
    $gridIntent = $match($gridIntentElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($expectedWidth, $gridIntent['attrs']['mediaWidth'] ?? null, 'Grid tracks imply grid intent unless display resolves flex: ' . $gridIntentStyle);
}

foreach ( array( 10 => 15, 14 => 15, 15 => 15, 85 => 85, 86 => 85, 90 => 85 ) as $sourceWidth => $expectedWidth ) {
    $fallbacks = array();
    $record = array();
    $boundedWidthElement = $elementFromHtml('<section style="display:grid;grid-template-columns:' . $sourceWidth . '% auto"><img src="bounded.jpg"><div><p>Bounded</p></div></section>');
    $boundedWidth = $match($boundedWidthElement, array( $paragraph ), $fallbacks, $record);
    $actualWidth = $boundedWidth['attrs']['mediaWidth'] ?? null;
    $assertSame($expectedWidth, $actualWidth, 'mediaWidth clamps to inclusive 15..85 range: ' . $sourceWidth);
}

$fallbacks = array();
$record = array();
$flexContradictionElement = $elementFromHtml('<section style="display:flex;grid-template-columns:30% auto"><img style="width:41%" src="flex-width.jpg"><div><p>Flex width</p></div></section>');
$flexContradiction = $match($flexContradictionElement, array( $paragraph ), $fallbacks, $record);
$assertSame(41, $flexContradiction['attrs']['mediaWidth'] ?? null, 'Resolved flex ignores grid tracks and keeps media flex-basis/width fallback order.');

// Vertical alignment mapping covers all core values.
foreach ( array( 'flex-start' => 'top', 'start' => 'top', 'center' => 'center', 'flex-end' => 'bottom', 'end' => 'bottom' ) as $alignItems => $expectedAlignment ) {
    $fallbacks = array();
    $record = array();
    $alignmentElement = $elementFromHtml('<section style="display:flex;align-items:' . $alignItems . '"><figure><img src="align.jpg"></figure><div><p>Align</p></div></section>');
    $alignment = $match($alignmentElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($expectedAlignment, $alignment['attrs']['verticalAlignment'] ?? null, 'align-items ' . $alignItems . ' maps to core value.');
}

foreach ( array(
    'align-items:center' => null,
    'display:block;align-items:center' => null,
    'display:inline-flex;align-items:center' => 'center',
    'display:inline-grid;align-items:center' => 'center',
) as $alignmentStyle => $expectedAlignment ) {
    $fallbacks = array();
    $record = array();
    $alignmentElement = $elementFromHtml('<section style="' . $alignmentStyle . '"><img src="alignment.jpg"><div><p>Align</p></div></section>');
    $alignment = $match($alignmentElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($expectedAlignment, $alignment['attrs']['verticalAlignment'] ?? null, 'Inline display forms share flex/grid alignment semantics: ' . $alignmentStyle);
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
    'inline flex column' => '<section style="display:inline-flex;flex-direction:column"><img src="vertical.jpg"><div><p>Vertical</p></div></section>',
    'flex flow reverse' => '<section style="display:flex;flex-flow:row-reverse wrap"><img src="flow-reverse.jpg"><div><p>Reverse</p></div></section>',
    'media order' => '<section style="display:flex"><img style="order:1" src="ordered.jpg"><div><p>Ordered</p></div></section>',
    'text order'  => '<section style="display:grid"><img src="ordered.jpg"><div style="order:2"><p>Ordered</p></div></section>',
    'rtl'         => '<section style="display:grid;direction:rtl"><img src="rtl.jpg"><div><p>RTL</p></div></section>',
    'dir rtl'     => '<section dir="rtl" style="display:grid"><img src="rtl.jpg"><div><p>RTL</p></div></section>',
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

foreach ( array( '0', '+0', '-0', '0.0', 'initial', 'unset' ) as $initialOrder ) {
    $fallbacks = array();
    $record = array();
    $initialOrderElement = $elementFromHtml('<section style="display:flex"><img src="initial-order.jpg"><div style="order:' . $initialOrder . '"><p>Initial order</p></div></section>');
    $initialOrderBlock = $match($initialOrderElement, array( $paragraph ), $fallbacks, $record);
    $assertSame('core/media-text', $initialOrderBlock['blockName'] ?? null, 'Initial-equivalent child order remains eligible: ' . $initialOrder);
}

foreach ( array(
    'order:1 !important;order:0' => null,
    'order:0 !important;order:1' => 'core/media-text',
) as $orderCascade => $expectedBlockName ) {
    $fallbacks = array();
    $record = array();
    $orderCascadeElement = $elementFromHtml('<section style="display:flex"><img src="order-cascade.jpg"><div style="' . $orderCascade . '"><p>Order cascade</p></div></section>');
    $orderCascadeBlock = $match($orderCascadeElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($expectedBlockName, $orderCascadeBlock['blockName'] ?? null, 'Order gate honors declaration importance: ' . $orderCascade);
}

foreach ( array(
    'flex-direction:row;flex-flow:row-reverse wrap' => null,
    'flex-flow:row-reverse wrap;flex-direction:row' => 'core/media-text',
    'flex-flow:row;flex-direction:row;flex-flow:row-reverse wrap' => null,
    'flex-direction:row !important;flex-flow:column' => 'core/media-text',
) as $directionOrder => $expectedBlockName ) {
    $fallbacks = array();
    $record = array();
    $directionOrderElement = $elementFromHtml('<section style="display:flex;' . $directionOrder . '"><img src="flow-order.jpg"><div><p>Flow order</p></div></section>');
    $directionOrderBlock = $match($directionOrderElement, array( $paragraph ), $fallbacks, $record);
    $assertSame($expectedBlockName, $directionOrderBlock['blockName'] ?? null, 'Last flex-flow/flex-direction declaration controls direction: ' . $directionOrder);
}

foreach ( array(
    'display:flex;flex-flow:column;flex-direction:banana',
    'display:flex;flex-direction:column;flex-flow:nope',
    'display:flex;display:banana;flex-direction:column',
) as $invalidDirectionStyle ) {
    $fallbacks = array();
    $record = array();
    $invalidDirectionElement = $elementFromHtml('<section style="' . $invalidDirectionStyle . '"><img src="invalid-direction.jpg"><div><p>Invalid direction</p></div></section>');
    $invalidDirectionBlock = $match($invalidDirectionElement, array( $paragraph ), $fallbacks, $record);
    $assertNull($invalidDirectionBlock, 'Invalid CSS value does not override earlier valid layout declaration: ' . $invalidDirectionStyle);
}

$fallbacks = array();
$record = array();
$invalidInitialOrderElement = $elementFromHtml('<section style="display:flex"><img src="invalid-order.jpg"><div style="order:0;order:banana"><p>Invalid order</p></div></section>');
$invalidInitialOrderBlock = $match($invalidInitialOrderElement, array( $paragraph ), $fallbacks, $record);
$assertSame('core/media-text', $invalidInitialOrderBlock['blockName'] ?? null, 'Invalid order does not override earlier zero order.');

$fallbacks = array();
$record = array();
$invalidAlignmentElement = $elementFromHtml('<section style="display:flex;align-items:center;align-items:banana"><img src="invalid-align.jpg"><div><p>Invalid align</p></div></section>');
$invalidAlignmentBlock = $match($invalidAlignmentElement, array( $paragraph ), $fallbacks, $record);
$assertSame('center', $invalidAlignmentBlock['attrs']['verticalAlignment'] ?? null, 'Invalid alignment does not override earlier center alignment.');

$fallbacks = array();
$record = array();
$invalidGridElement = $elementFromHtml('<section style="grid-template-columns:30% auto;grid-template-columns:banana"><img src="invalid-grid.jpg"><div><p>Invalid grid</p></div></section>');
$invalidGridBlock = $match($invalidGridElement, array( $paragraph ), $fallbacks, $record);
$assertSame(30, $invalidGridBlock['attrs']['mediaWidth'] ?? null, 'Invalid grid template does not override earlier valid tracks.');

$fallbacks = array();
$record = array();
$invalidWidthElement = $elementFromHtml('<section style="display:flex"><img style="width:40%;width:banana" src="invalid-width.jpg"><div><p>Invalid width</p></div></section>');
$invalidWidthBlock = $match($invalidWidthElement, array( $paragraph ), $fallbacks, $record);
$assertSame(40, $invalidWidthBlock['attrs']['mediaWidth'] ?? null, 'Invalid media width does not override earlier percentage width.');

// Authored direction/order rules reach strict gates for low-value direct children.
$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$authoredOrderElement = $elementFromHtml('<section><img src="ordered.jpg"><div class="copy"><p>Ordered</p></div></section>');
$authoredOrder = $match(
    $authoredOrderElement,
    array( $paragraph ),
    $fallbacks,
    $record,
    array(),
    true,
    false,
    null,
    false,
    static fn (DOMElement $sourceElement): string => str_contains(' ' . $sourceElement->getAttribute('class') . ' ', ' copy ')
        ? 'order:2'
        : $sourceElement->getAttribute('style')
);
$assertNull($authoredOrder, 'Authored child order declines match.');
$assertSame(0, $record['convertCalls'], 'Authored child order declines before text conversion.');
$assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Authored child order leaves host fallbacks unchanged.');

$fallbacks = array( array( 'reason' => 'existing' ) );
$record = array();
$authoredRtlElement = $elementFromHtml('<section class="shell"><img src="rtl.jpg"><div><p>RTL</p></div></section>');
$authoredRtl = $match(
    $authoredRtlElement,
    array( $paragraph ),
    $fallbacks,
    $record,
    array(),
    true,
    false,
    null,
    false,
    static fn (DOMElement $sourceElement): string => str_contains(' ' . $sourceElement->getAttribute('class') . ' ', ' shell ')
        ? 'display:grid;direction:rtl'
        : $sourceElement->getAttribute('style')
);
$assertNull($authoredRtl, 'Authored container rtl declines match.');
$assertSame(0, $record['convertCalls'], 'Authored container rtl declines before text conversion.');
$assertSame(array( array( 'reason' => 'existing' ) ), $fallbacks, 'Authored container rtl leaves host fallbacks unchanged.');

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
    '<section style="display:grid;grid-template-columns:30% auto;max-width:900px;min-height:30rem;aspect-ratio:16/9;--media-gap:2rem;padding:1rem;background-color:var(--wp--preset--color--accent)"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>'
);
$geometryBlock = $geometryResult['blocks'][0] ?? array();
$geometryOpening = (string) ($geometryBlock['innerContent'][0] ?? '');
$geometryAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $geometryResult['assets'] ?? array()));
$assertSame('core/media-text', $geometryBlock['blockName'] ?? null, 'Grid geometry case emits media-text.');
$assertSame(
    array( 'top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem' ),
    $geometryBlock['attrs']['style']['spacing']['padding'] ?? null,
    'Media-text attrs preserve supported padding.'
);
$assertTrue(! isset($geometryBlock['attrs']['style']['dimensions']), 'Media-text attrs omit unsupported dimensions.');
$assertSame('accent', $geometryBlock['attrs']['backgroundColor'] ?? null, 'Media-text preserves top-level preset attr.');
$assertContains('has-accent-background-color has-background', $geometryOpening, 'Media-text preserves top-level preset classes.');
$assertContains('be-inline-geometry-', (string) ($geometryBlock['attrs']['className'] ?? ''), 'Media-text preserves generated geometry carrier class.');
$assertContains('padding-top:1rem;padding-right:1rem;padding-bottom:1rem;padding-left:1rem;grid-template-columns:30% auto', $geometryOpening, 'Media-text wrapper merges support styles with grid tracks.');
foreach ( array( 'max-width', 'min-height', 'aspect-ratio', '--media-gap' ) as $leakedProperty ) {
    $assertTrue(! str_contains($geometryOpening, $leakedProperty), 'Media-text wrapper style omits source property: ' . $leakedProperty);
}
$assertContains('max-width:900px !important', $geometryAssets, 'Carrier stylesheet preserves source max-width.');
$assertContains('min-height:30rem !important', $geometryAssets, 'Carrier stylesheet preserves source min-height.');
$assertContains('aspect-ratio:16/9 !important', $geometryAssets, 'Carrier stylesheet preserves source aspect-ratio.');

$variableGeometryResult = $transformHtml(
    '<section style="display:flex;--base:420px;--pane:var(--base);min-height:var(--pane)"><figure><img src="x.jpg" alt=""></figure><div><p>Copy</p></div></section>'
);
$variableGeometryBlock = $variableGeometryResult['blocks'][0] ?? array();
$variableGeometryOpening = (string) ($variableGeometryBlock['innerContent'][0] ?? '');
$variableGeometryAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $variableGeometryResult['assets'] ?? array()));
$assertSame('core/media-text', $variableGeometryBlock['blockName'] ?? null, 'Variable geometry case emits media-text.');
$assertContains('--base:420px !important', $variableGeometryAssets, 'Carrier stylesheet preserves transitive custom-property definition.');
$assertContains('--pane:var(--base) !important', $variableGeometryAssets, 'Carrier stylesheet preserves directly referenced custom-property definition.');
$assertContains('min-height:var(--pane) !important', $variableGeometryAssets, 'Carrier stylesheet preserves variable geometry declaration.');
$assertTrue(! str_contains($variableGeometryOpening, '--pane'), 'Media-text wrapper keeps custom properties out of inline style.');
$assertTrue(! str_contains($variableGeometryOpening, 'min-height'), 'Media-text wrapper keeps variable geometry out of inline style.');

$importantGeometryResult = $transformHtml(
    '<section style="display:flex;min-height:420px !important"><figure><img src="x.jpg" alt=""></figure><div><p>Copy</p></div></section>'
);
$importantGeometryBlock = $importantGeometryResult['blocks'][0] ?? array();
$importantGeometryOpening = (string) ($importantGeometryBlock['innerContent'][0] ?? '');
$importantGeometryAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $importantGeometryResult['assets'] ?? array()));
$assertSame('core/media-text', $importantGeometryBlock['blockName'] ?? null, 'Important geometry case emits media-text.');
$assertContains('min-height:420px !important', $importantGeometryAssets, 'Carrier stylesheet preserves important-only geometry.');
$assertTrue(! str_contains($importantGeometryOpening, 'min-height'), 'Media-text wrapper keeps important geometry out of inline style.');

$importantCascadeResult = $transformHtml(
    '<section style="display:flex;min-height:420px !important;min-height:200px"><figure><img src="x.jpg" alt=""></figure><div><p>Copy</p></div></section>'
);
$importantCascadeAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $importantCascadeResult['assets'] ?? array()));
$assertContains('min-height:420px !important', $importantCascadeAssets, 'Carrier stylesheet honors important geometry over later normal declaration.');
$assertTrue(! str_contains($importantCascadeAssets, 'min-height:200px'), 'Carrier stylesheet drops losing normal geometry declaration.');

$caseSensitiveVariableResult = $transformHtml(
    '<section style="display:flex;--x:400px;--X:500px;min-height:var(--x)"><figure><img src="x.jpg" alt=""></figure><div><p>Copy</p></div></section>'
);
$caseSensitiveVariableAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $caseSensitiveVariableResult['assets'] ?? array()));
$assertContains('--x:400px !important', $caseSensitiveVariableAssets, 'Carrier stylesheet preserves case-sensitive custom-property identity.');
$assertTrue(! str_contains($caseSensitiveVariableAssets, '--x:500px'), 'Carrier stylesheet does not merge differently cased custom properties.');

$clampedWidthResult = $transformHtml('<section style="display:grid;grid-template-columns:10% auto"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$clampedWidthBlock = $clampedWidthResult['blocks'][0] ?? array();
$assertSame(15, $clampedWidthBlock['attrs']['mediaWidth'] ?? null, 'Out-of-range source width clamps in emitted attrs.');
$assertContains('grid-template-columns:15% auto', (string) ($clampedWidthBlock['innerContent'][0] ?? ''), 'Clamped width controls emitted wrapper track.');

$impliedGridResult = $transformHtml('<section style="grid-template-columns:30% auto"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertSame(30, $impliedGridResult['blocks'][0]['attrs']['mediaWidth'] ?? null, 'Grid tracks derive emitted width without explicit display grid.');

$inlineFlexAlignmentResult = $transformHtml('<section style="display:inline-flex;align-items:center"><figure><img src="x.jpg" alt=""></figure><div><p>Copy</p></div></section>');
$assertSame('center', $inlineFlexAlignmentResult['blocks'][0]['attrs']['verticalAlignment'] ?? null, 'Inline flex preserves emitted vertical alignment.');

$rowReverseResult = $transformHtml('<section style="display:flex;flex-direction:row-reverse"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($rowReverseResult['blocks'][0]['blockName'] ?? null), 'Flex row-reverse falls through without media-text.');

$inlineFlexColumnResult = $transformHtml('<section style="display:inline-flex;flex-direction:column"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($inlineFlexColumnResult['blocks'][0]['blockName'] ?? null), 'Inline-flex column falls through without media-text.');

$flexFlowReverseResult = $transformHtml('<section style="display:flex;flex-flow:row-reverse wrap"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($flexFlowReverseResult['blocks'][0]['blockName'] ?? null), 'Flex-flow row-reverse falls through without media-text.');

$authoredFlexFlowResult = $transformHtml('<style>.flow-shell{display:flex;flex-flow:row-reverse wrap}</style><section class="flow-shell"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($authoredFlexFlowResult['blocks'][0]['blockName'] ?? null), 'Stylesheet-authored flex-flow row-reverse reaches media-text gate.');

$importantAuthoredFlowResult = $transformHtml('<style>.flow-priority{display:flex;flex-flow:column !important}</style><section class="flow-priority" style="flex-flow:row"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($importantAuthoredFlowResult['blocks'][0]['blockName'] ?? null), 'Stylesheet important flex-flow beats inline normal flex-flow.');

$repeatedAuthoredFlowResult = $transformHtml('<style>.flow-repeat{display:flex;flex-flow:row;flex-direction:row;flex-flow:row-reverse wrap}</style><section class="flow-repeat"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($repeatedAuthoredFlowResult['blocks'][0]['blockName'] ?? null), 'Stylesheet declaration order resolves repeated flex-flow against flex-direction.');

$inlineImportantFlowResult = $transformHtml('<style>.flow-inline-priority{display:flex;flex-flow:column !important}</style><section class="flow-inline-priority" style="flex-direction:row !important"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertSame('core/media-text', $inlineImportantFlowResult['blocks'][0]['blockName'] ?? null, 'Inline important flex-direction beats stylesheet important flex-flow.');

$specificAuthoredFlowResult = $transformHtml('<style>#specific-flow{display:flex;flex-flow:column}.specific-flow{flex-flow:row}</style><section id="specific-flow" class="specific-flow"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($specificAuthoredFlowResult['blocks'][0]['blockName'] ?? null), 'Higher-specificity stylesheet flex-flow beats later lower-specificity rule.');

$tupleSpecificityFlowResult = $transformHtml('<style>#target{display:flex;flex-flow:column}.a.b.c.d.e.f.g.h.i.j.k{flex-flow:row}</style><section id="target" class="a b c d e f g h i j k"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($tupleSpecificityFlowResult['blocks'][0]['blockName'] ?? null), 'ID specificity beats any number of class selectors without scalar carry.');

$attributeValueSpecificityResult = $transformHtml('<style>#target{display:flex;flex-flow:column}[data-token="#fake"]{flex-flow:row}</style><section id="target" data-token="#fake"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($attributeValueSpecificityResult['blocks'][0]['blockName'] ?? null), 'ID-like text inside attribute value does not add ID specificity.');

foreach ( array(
    'display:flex;flex-flow:column;flex-direction:banana',
    'display:flex;flex-direction:column;flex-flow:nope',
    'display:flex;display:banana;flex-direction:column',
) as $invalidIntegrationStyle ) {
    $invalidIntegrationResult = $transformHtml('<section style="' . $invalidIntegrationStyle . '"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
    $assertTrue('core/media-text' !== ($invalidIntegrationResult['blocks'][0]['blockName'] ?? null), 'Transform ignores invalid layout declaration: ' . $invalidIntegrationStyle);
}

$invalidAuthoredLayoutResult = $transformHtml('<style>.invalid-layout{display:flex;flex-flow:column;flex-direction:banana}</style><section class="invalid-layout"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($invalidAuthoredLayoutResult['blocks'][0]['blockName'] ?? null), 'Stylesheet invalid value does not override earlier valid layout declaration.');

$initialOrderResult = $transformHtml('<section style="display:flex"><img src="x.jpg" alt=""><div style="order:0"><p>Copy</p></div></section>');
$assertSame('core/media-text', $initialOrderResult['blocks'][0]['blockName'] ?? null, 'Authored order zero remains eligible for media-text.');

$authoredOrderResult = $transformHtml('<style>.copy{order:2}</style><section><img src="x.jpg"><div class="copy"><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($authoredOrderResult['blocks'][0]['blockName'] ?? null), 'Authored .copy{order:2} declines media-text.');

$importantAuthoredOrderResult = $transformHtml('<style>.ordered-copy{order:1 !important}</style><section style="display:flex"><img src="x.jpg"><div class="ordered-copy" style="order:0"><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($importantAuthoredOrderResult['blocks'][0]['blockName'] ?? null), 'Stylesheet important order beats inline normal order.');

$repeatedAuthoredOrderResult = $transformHtml('<style>.repeated-order{order:1 !important;order:0}</style><section style="display:flex"><img src="x.jpg"><div class="repeated-order"><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($repeatedAuthoredOrderResult['blocks'][0]['blockName'] ?? null), 'Stylesheet declaration importance resolves repeated order declarations.');

$authoredRtlResult = $transformHtml('<style>.shell{display:grid;direction:rtl}</style><section class="shell"><img src="x.jpg"><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($authoredRtlResult['blocks'][0]['blockName'] ?? null), 'Authored .shell{display:grid;direction:rtl} declines media-text.');

$dirRtlResult = $transformHtml('<section dir="rtl" style="display:grid;grid-template-columns:30% auto"><img src="x.jpg"><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($dirRtlResult['blocks'][0]['blockName'] ?? null), 'Container dir=rtl declines media-text.');

$svgDataResult = $transformHtml('<section><img src="data:image/svg+xml;base64,AAAA" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($svgDataResult['blocks'][0]['blockName'] ?? null), 'SVG data media URL declines media-text.');

$lastDisplayWinsResult = $transformHtml('<section style="display:flex;display:grid;flex-direction:row-reverse"><img src="x.jpg"><div><p>Copy</p></div></section>');
$assertSame('core/media-text', $lastDisplayWinsResult['blocks'][0]['blockName'] ?? null, 'Last duplicate display declaration controls row-reverse gate.');

$lastFlexDirectionWinsResult = $transformHtml('<section style="display:flex;flex-direction:row-reverse;flex-direction:row"><img src="x.jpg"><div><p>Copy</p></div></section>');
$assertSame('core/media-text', $lastFlexDirectionWinsResult['blocks'][0]['blockName'] ?? null, 'Last duplicate flex-direction declaration controls row-reverse gate.');

$reviewerLastDisplayResult = $transformHtml('<section style="display:flex;display:block;flex-direction:column"><img src="x.jpg"><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($reviewerLastDisplayResult['blocks'][0]['blockName'] ?? null), 'Last display:block declaration declines via the mechanism gate, not the stale flex column.');

$reviewerLastDirectionResult = $transformHtml('<section style="display:flex;flex-direction:column;flex-direction:row"><img src="x.jpg"><div><p>Copy</p></div></section>');
$assertSame('core/media-text', $reviewerLastDirectionResult['blocks'][0]['blockName'] ?? null, 'Last flex-direction:row declaration supersedes stale column.');

$linkedVideoResult = $transformHtml('<section><a href="https://e.com/go"><video src="v.mp4"></video></a><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($linkedVideoResult['blocks'][0]['blockName'] ?? null), 'Linked video falls through without media-text.');

$captionResult = $transformHtml('<section class="media-text"><figure><img src="caption.jpg"><figcaption>Caption</figcaption></figure><div><p>Copy</p></div></section>');
$assertSame('core/columns', $captionResult['blocks'][0]['blockName'] ?? null, 'Figcaption decline falls through to existing columns path.');

$ambiguousGalleryResult = $transformHtml(
    '<div class="media-grid" data-layout="grid"><figure><img src="c.jpg" alt="C"></figure><figure class="tile"><img src="d.jpg" alt="D"><figcaption>D caption</figcaption></figure></div>'
);
$assertSame('core/gallery', $ambiguousGalleryResult['blocks'][0]['blockName'] ?? null, 'Two media-bearing figure panes remain core/gallery.');

$threeChildrenResult = $transformHtml('<section style="display:flex"><figure><img src="three.jpg"></figure><div><p>Copy</p></div><aside>Extra</aside></section>');
$assertSame('core/group', $threeChildrenResult['blocks'][0]['blockName'] ?? null, 'Three-child decline falls through to author-owned layout preservation.');

$verticalResult = $transformHtml('<section class="media-text" style="display:flex;flex-direction:column"><figure><img src="stacked.jpg"></figure><div><p>Stacked</p></div></section>');
$assertTrue('core/media-text' !== ($verticalResult['blocks'][0]['blockName'] ?? null), 'Vertical flex decline never emits media-text.');
$assertTrue('core/columns' !== ($verticalResult['blocks'][0]['blockName'] ?? null), 'Vertical flex decline keeps existing columns rejection.');

// Unresolvable var() on gate properties fails closed instead of converting
// with the default layout.
foreach ( array(
    'display:flex;flex-direction:var(--stack-direction)',
    'display:flex;flex-flow:var(--stack-flow)',
    'display:var(--layout-mode)',
    'display:flex;direction:var(--text-direction)',
) as $unresolvableContainerStyle ) {
    $unresolvableContainerResult = $transformHtml('<section style="' . $unresolvableContainerStyle . '"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
    $assertTrue('core/media-text' !== ($unresolvableContainerResult['blocks'][0]['blockName'] ?? null), 'Unresolvable container gate value declines: ' . $unresolvableContainerStyle);
}

$authoredVarDirectionResult = $transformHtml('<style>.token-flow{display:flex;flex-direction:var(--stack-direction)}</style><section class="token-flow"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($authoredVarDirectionResult['blocks'][0]['blockName'] ?? null), 'Stylesheet var() flex-direction declines media-text.');

$varOrderResult = $transformHtml('<section style="display:flex"><img src="x.jpg" alt="" style="order:var(--o)"><div><p>Copy</p></div></section>');
$assertTrue('core/media-text' !== ($varOrderResult['blocks'][0]['blockName'] ?? null), 'Unresolvable child order declines media-text.');

// Inherited RTL declines even when declared on an ancestor.
$ancestorDirResult = $transformHtml('<div dir="rtl"><section style="display:flex"><img src="x.jpg" alt=""><div><p>Copy</p></div></section></div>');
$assertTrue('core/media-text' !== ($ancestorDirResult['blocks'][0]['blockName'] ?? null) && ! str_contains(json_encode($ancestorDirResult['blocks']), 'core\/media-text'), 'Ancestor dir=rtl declines media-text.');

$bodyDirectionResult = $transformHtml('<style>body{direction:rtl}</style><section style="display:flex"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue(! str_contains(json_encode($bodyDirectionResult['blocks']), 'core\/media-text'), 'Inherited body{direction:rtl} declines media-text.');

$nearestLtrResult = $transformHtml('<div dir="rtl"><section dir="ltr" style="display:flex"><img src="x.jpg" alt=""><div><p>Copy</p></div></section></div>');
$assertTrue(str_contains(json_encode($nearestLtrResult['blocks']), 'core\/media-text'), 'Nearest dir=ltr overrides ancestor rtl and converts.');

$dirAutoResult = $transformHtml('<section dir="auto" style="display:flex"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue(! str_contains(json_encode($dirAutoResult['blocks']), 'core\/media-text'), 'dir=auto fails closed and declines media-text.');

// Floated panes decline instead of converting with DOM-order position.
$floatRightResult = $transformHtml('<section><img src="x.jpg" alt="" style="float:right;width:40%"><div><p>Copy</p></div></section>');
$assertTrue(! str_contains(json_encode($floatRightResult['blocks']), 'core\/media-text'), 'Floated media pane declines media-text.');

$authoredFloatResult = $transformHtml('<style>.pull{float:left}</style><section><img src="x.jpg" alt=""><div class="pull"><p>Copy</p></div></section>');
$assertTrue(! str_contains(json_encode($authoredFloatResult['blocks']), 'core\/media-text'), 'Stylesheet-floated text pane declines media-text.');

// Grid templates that cannot express a mediaWidth decline instead of
// silently rendering 50/50.
foreach ( array(
    'display:grid;grid-template-columns:300px auto',
    'display:grid;grid-template-columns:minmax(200px,1fr) 2fr',
    'display:grid;grid-template-columns:none',
    'display:grid;grid-template-columns:var(--cols)',
) as $inexpressibleGridStyle ) {
    $inexpressibleGridResult = $transformHtml('<section style="' . $inexpressibleGridStyle . '"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
    $assertTrue(! str_contains(json_encode($inexpressibleGridResult['blocks']), 'core\/media-text'), 'Inexpressible grid template declines: ' . $inexpressibleGridStyle);
}

$expressibleGridResult = $transformHtml('<section style="display:grid;grid-template-columns:30% auto"><img src="x.jpg" alt=""><div><p>Copy</p></div></section>');
$assertTrue(str_contains(json_encode($expressibleGridResult['blocks']), 'core\/media-text'), 'Percentage grid template still converts.');

// A split-implying class with no authored horizontal CSS renders stacked and
// defers to the columns demotion policy instead of fabricating a side pair.
$classOnlySplitResult = $transformHtml('<div class="hero-grid"><div class="hero-text"><h1>Fresh Bread Daily</h1><p>Baked every morning.</p></div><figure><img src="https://example.com/bread.jpg" alt="Bread"></figure></div>');
$assertTrue(! str_contains(json_encode($classOnlySplitResult['blocks']), 'core\/media-text'), 'Class-implied split without authored CSS declines media-text.');

$classSplitWithCssResult = $transformHtml('<div class="hero-grid" style="display:grid;grid-template-columns:60% auto"><div><h1>Fresh Bread Daily</h1><p>Baked every morning.</p></div><figure><img src="https://example.com/bread.jpg" alt="Bread"></figure></div>');
$assertTrue(str_contains(json_encode($classSplitWithCssResult['blocks']), 'core\/media-text'), 'Class-implied split with authored grid converts.');

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

// Media-text style resolution memoizes by the shared presentation cache key.
$memoizedTransformer = new HtmlTransformer();
$memoizedElement = $elementFromHtml('<section style="display:flex"><img src="memo.jpg"><div><p>Memo</p></div></section>');
$mediaStyleMethod = new ReflectionMethod(HtmlTransformer::class, 'mediaTextPresentationStyle');
$presentationKeyMethod = new ReflectionMethod(HtmlTransformer::class, 'presentationCacheKey');
$sessionProperty = new ReflectionProperty(HtmlTransformer::class, 'session');
$firstMediaStyle = $mediaStyleMethod->invoke($memoizedTransformer, $memoizedElement);
$memoizedElement->setAttribute('style', 'display:grid');
$secondMediaStyle = $mediaStyleMethod->invoke($memoizedTransformer, $memoizedElement);
$mediaStyleCache = $sessionProperty->getValue($memoizedTransformer)->presentationResolutionCache->mediaTextStyles;
$presentationKey = $presentationKeyMethod->invoke($memoizedTransformer, $memoizedElement);
$assertSame('display:flex', $firstMediaStyle, 'Media-text presentation style resolves initial authored style.');
$assertSame($firstMediaStyle, $secondMediaStyle, 'Media-text presentation style reuses cached value for same DOM node.');
$assertSame($firstMediaStyle, $mediaStyleCache[$presentationKey] ?? null, 'Media-text style cache uses shared presentation cache key.');

// Emitted media-text markup passes Runtime serialization validation.
$runtime = new Runtime();
$serializedRoundTrip = $runtime->serializeBlocks(array( $roundTrip ));
$validity = $runtime->validateBlockSerialization($serializedRoundTrip);
$assertSame('pass', $validity['status'] ?? null, 'Emitted media-text markup passes serialization validity.');

if ( 0 === $failures ) {
    echo "media text pattern ok\n";
}

exit(0 === $failures ? 0 : 1);

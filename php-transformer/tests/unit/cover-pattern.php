<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverPattern;

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

$pattern = new CoverPattern();

// Frozen public callback contract.
$matchMethod = new ReflectionMethod(CoverPattern::class, 'match');
$matchParameters = $matchMethod->getParameters();
$assertSame(
    array( 'element', 'fallbacks', 'convertChildren', 'presentationAttributes', 'mergedPresentationStyle', 'htmlAttributes', 'resolveAssetUrl', 'createBlock' ),
    array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $matchParameters),
    'match callback parameter names remain frozen.'
);
$assertSame(
    array( 'DOMElement', 'array', 'callable', 'callable', 'callable', 'callable', 'callable', 'callable' ),
    array_map(static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $matchParameters),
    'match callback parameter types remain frozen.'
);
$assertTrue($matchParameters[1]->isPassedByReference(), 'match fallbacks parameter remains passed by reference.');
$assertSame('?array', (string) $matchMethod->getReturnType(), 'match nullable-array return type remains frozen.');

$rejectionMethod = new ReflectionMethod(CoverPattern::class, 'rejectionGate');
$assertSame(
    array( 'style', 'hasTextBearingChildren' ),
    array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $rejectionMethod->getParameters()),
    'rejectionGate parameter names remain frozen.'
);
$assertSame('?string', (string) $rejectionMethod->getReturnType(), 'rejectionGate nullable-string return type remains frozen.');

/**
 * @param array<int, array<string, mixed>> $children
 * @param array<string, mixed> $presentation
 * @param array<string, string> $htmlAttributes
 * @param array<int, array<string, mixed>> $fallbacks
 * @param array<string, mixed> $record
 * @return array<string, mixed>|null
 */
$match = static function (
    DOMElement $element,
    array $children,
    array $presentation,
    array $htmlAttributes,
    array &$fallbacks,
    array &$record,
    bool $throwPresentation = false
) use ($pattern): ?array {
    $record = array(
        'convertCalls' => 0,
        'excluded'     => null,
    );

    return $pattern->match(
        $element,
        $fallbacks,
        static function (DOMElement $sourceElement, array &$sourceFallbacks, bool $captureUnsupported) use (&$record, $children): array {
            ++$record['convertCalls'];
            $sourceFallbacks[] = array( 'blockName' => 'blocks-engine/fallback' );
            return $children;
        },
        static function (DOMElement $sourceElement, array $excludedGeometryProperties) use (&$record, $presentation, $throwPresentation): array {
            $record['excluded'] = $excludedGeometryProperties;
            if ( $throwPresentation ) {
                throw new RuntimeException('presentation unavailable');
            }
            return $presentation;
        },
        static fn (DOMElement $sourceElement): string => $sourceElement->getAttribute('style'),
        static fn (DOMElement $sourceElement): array => $htmlAttributes,
        static fn (string $url): string => 'resolved:' . $url,
        static fn (string $name, array $attrs, array $innerBlocks, ?DOMElement $sourceElement): array => array(
            'blockName'   => $name,
            'attrs'       => $attrs,
            'innerBlocks' => $innerBlocks,
        )
    );
};

$heading = array(
    'blockName'   => 'core/heading',
    'attrs'       => array( 'content' => 'Build' ),
    'innerBlocks' => array(),
);

// 1. Hero matches and converted children become cover innerBlocks.
$fallbacks = array();
$record = array();
$hero = $elementFromHtml('<section aria-label="Hero image" style="background-image:url(https://example.com/hero.jpg);background-size:cover"><h1>Build</h1></section>');
$cover = $match($hero, array( $heading ), array( 'className' => 'hero' ), array( 'aria-label' => 'Hero image' ), $fallbacks, $record);
$assertSame('core/cover', $cover['blockName'] ?? null, 'Hero matches core/cover.');
$assertSame(array( $heading ), $cover['innerBlocks'] ?? null, 'Converted children become cover innerBlocks.');
$assertSame('resolved:https://example.com/hero.jpg', $cover['attrs']['url'] ?? null, 'Background URL passes through asset resolver.');
$assertSame('Hero image', $cover['attrs']['alt'] ?? null, 'Background image alt derives from source attributes.');
$assertSame(0, $cover['attrs']['dimRatio'] ?? null, 'Hero without overlay carries dimRatio 0.');
$assertSame(array( array( 'blockName' => 'blocks-engine/fallback' ) ), $fallbacks, 'Matched local fallbacks push to host accumulator.');

// 2. convertChildren runs exactly once on a match.
$assertSame(1, $record['convertCalls'], 'convertChildren runs exactly once on match.');

// 3. Gate 1 rejects missing background URLs before child conversion.
$fallbacks = array();
$record = array();
$noUrl = $elementFromHtml('<section style="background-size:cover"><h1>Build</h1></section>');
$assertNull($match($noUrl, array( $heading ), array(), array(), $fallbacks, $record), 'Missing background URL rejects cover.');
$assertSame(0, $record['convertCalls'], 'Missing background URL does not convert children.');

// 4. Gate 2 rejects sub-threshold, non-cover backgrounds.
$fallbacks = array();
$record = array();
$small = $elementFromHtml('<section style="background-image:url(small.jpg);background-size:auto;min-height:120px"><h1>Build</h1></section>');
$assertNull($match($small, array( $heading ), array(), array(), $fallbacks, $record), 'Sub-threshold background rejects cover.');
$assertSame(0, $record['convertCalls'], 'Sub-threshold background does not convert children.');

// 5. Gate 2b rejects repeating backgrounds.
$fallbacks = array();
$record = array();
$texture = $elementFromHtml('<section style="background-image:url(texture.png);background-size:cover;background-repeat:repeat"><h1>Build</h1></section>');
$assertNull($match($texture, array( $heading ), array(), array(), $fallbacks, $record), 'Repeating background rejects cover.');
$assertSame(0, $record['convertCalls'], 'Repeating background does not convert children.');

// 6. Gate 3 rejects empty converted children and discards local fallbacks.
$fallbacks = array( array( 'blockName' => 'existing/fallback' ) );
$record = array();
$empty = $elementFromHtml('<section style="background-image:url(empty.jpg);background-size:cover"></section>');
$assertNull($match($empty, array(), array(), array(), $fallbacks, $record), 'Empty converted children reject cover.');
$assertSame(array( array( 'blockName' => 'existing/fallback' ) ), $fallbacks, 'Rejected local fallbacks do not reach host accumulator.');

// 7. Gate 3 rejects image-only children, including nested image-only groups.
$fallbacks = array();
$record = array();
$imageOnly = array(
    'blockName'   => 'core/group',
    'attrs'       => array(),
    'innerBlocks' => array( array( 'blockName' => 'core/image', 'attrs' => array(), 'innerBlocks' => array() ) ),
);
$assertNull($match($hero, array( $imageOnly ), array(), array(), $fallbacks, $record), 'Image-only children reject cover.');

$fallbacks = array();
$record = array();
$nestedHeading = array(
    'blockName'   => 'core/group',
    'attrs'       => array(),
    'innerBlocks' => array( $heading ),
);
$assertSame('core/cover', $match($hero, array( $nestedHeading ), array(), array(), $fallbacks, $record)['blockName'] ?? null, 'Text-bearing scan recurses through innerBlocks.');

// 8. Presentation extraction excludes all consumed background geometry.
$assertSame(
    array( 'background', 'background-image', 'background-size', 'background-position', 'background-repeat' ),
    $record['excluded'],
    'Presentation extraction receives frozen background exclusions.'
);

// 9. Consumed gradients do not survive as style.color.gradient.
$fallbacks = array();
$record = array();
$gradient = $elementFromHtml('<section style="background:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url(hero.jpg) center/cover"><h1>Build</h1></section>');
$gradientCover = $match(
    $gradient,
    array( $heading ),
    array( 'style' => array( 'color' => array( 'gradient' => 'linear-gradient(#0008,#0008)', 'text' => '#ffffff' ) ) ),
    array(),
    $fallbacks,
    $record
);
$assertTrue(! isset($gradientCover['attrs']['style']['color']['gradient']), 'Consumed gradient is removed from cover style.');
$assertSame('#ffffff', $gradientCover['attrs']['style']['color']['text'] ?? null, 'Non-gradient color support remains intact.');

// 10. Dim, min-height, and focal-point derivations land in attrs.
$fallbacks = array();
$record = array();
$derived = $elementFromHtml('<section style="background:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url(hero.jpg) center/cover;background-position:right top;min-height:480px"><h1>Build</h1></section>');
$derivedCover = $match($derived, array( $heading ), array( 'layout' => array( 'type' => 'flex' ) ), array(), $fallbacks, $record);
$assertSame(50, $derivedCover['attrs']['dimRatio'] ?? null, 'Uniform overlay maps to dimRatio.');
$assertSame('#000000', $derivedCover['attrs']['customOverlayColor'] ?? null, 'Valid custom overlay color lands.');
$assertSame(480, $derivedCover['attrs']['minHeight'] ?? null, 'Min-height value lands.');
$assertSame('px', $derivedCover['attrs']['minHeightUnit'] ?? null, 'Min-height unit lands.');
$assertSame(array( 'x' => 1.0, 'y' => 0.0 ), $derivedCover['attrs']['focalPoint'] ?? null, 'Focal point lands.');
$assertTrue(! array_key_exists('layout', $derivedCover['attrs'] ?? array()), 'Cover attrs omit layout.');

// 11. Unsafe URLs reject at Gate 1.
$fallbacks = array();
$record = array();
$unsafe = $elementFromHtml('<section style="background-image:url(javascript:alert(1));background-size:cover"><h1>Build</h1></section>');
$assertNull($match($unsafe, array( $heading ), array(), array(), $fallbacks, $record), 'Unsafe background URL rejects cover.');
$assertSame(0, $record['convertCalls'], 'Unsafe background URL does not convert children.');

// 12. rejectionGate reports first failing gate and null on full pass.
$heroStyle = 'background-image:url(hero.jpg);background-size:cover';
$assertSame('not_hero_sized', $pattern->rejectionGate('background-image:url(x.png)', true), 'rejectionGate reports hero-size failure.');
$assertSame('no_text_content', $pattern->rejectionGate($heroStyle, false), 'rejectionGate reports missing text content.');
$assertNull($pattern->rejectionGate($heroStyle, true), 'rejectionGate returns null when all gates pass.');

// Post-gate presentation failure remains fail-open and still emits a cover.
$fallbacks = array();
$record = array();
$failOpenCover = $match($hero, array( $heading ), array(), array(), $fallbacks, $record, true);
$assertSame('core/cover', $failOpenCover['blockName'] ?? null, 'Presentation failure does not throw or suppress gated cover.');
$assertSame('resolved:https://example.com/hero.jpg', $failOpenCover['attrs']['url'] ?? null, 'Fail-open cover retains independently derivable URL.');

echo "cover pattern ok\n";

exit(0 === $failures ? 0 : 1);

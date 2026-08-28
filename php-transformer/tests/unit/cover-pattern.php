<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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
$transformHtml = static function (string $html): array {
    return (new HtmlTransformer())->transform($html)->toArray();
};
$blockNames = static function (array $blocks) use (&$blockNames): array {
    $names = array();
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        $name = $block['blockName'] ?? null;
        if ( is_string($name) ) {
            $names[] = $name;
        }
        $innerBlocks = $block['innerBlocks'] ?? array();
        if ( is_array($innerBlocks) ) {
            array_push($names, ...$blockNames($innerBlocks));
        }
    }

    return $names;
};
$assetCss = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }

    return $css;
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
$paragraph = array(
    'blockName'   => 'core/paragraph',
    'attrs'       => array( 'content' => 'Ship' ),
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
    array( 'background', 'background-image', 'background-size', 'background-position', 'background-repeat', 'min-height', 'height' ),
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

// K3: Consumed min-height/height geometry emits exactly once through core/cover attrs.
$minHeightResult = $transformHtml('<section class="hero" style="background-image:url(https://example.com/hero.jpg);background-size:cover;min-height:480px"><h1>Build</h1><p>Ship</p></section>');
$minHeightCover = $minHeightResult['blocks'][0] ?? array();
$minHeightOpening = (string) ($minHeightCover['innerContent'][0] ?? '');
$assertSame('core/cover', $minHeightCover['blockName'] ?? null, 'K3: Min-height hero remains core/cover.');
$assertSame(1, substr_count($minHeightOpening, 'min-height'), 'K3: Min-height hero opening markup emits min-height once.');
$assertTrue(! (bool) preg_match('/(?<!min-)height:480px/', $minHeightOpening), 'K3: Min-height hero does not emit a definite height.');
$assertSame(480, $minHeightCover['attrs']['minHeight'] ?? null, 'K3: Min-height hero keeps numeric minHeight attr.');
$assertTrue(! isset($minHeightCover['attrs']['style']['dimensions']['minHeight']), 'K3: Min-height hero removes duplicate style.dimensions.minHeight.');
$assertTrue(! str_contains($assetCss($minHeightResult), 'min-height:480px'), 'K3: Min-height hero generates no carrier min-height rule.');
$assertTrue(! str_contains($assetCss($minHeightResult), 'height:480px'), 'K3: Min-height hero generates no carrier height rule (floor semantics, no definite box).');

$heightResult = $transformHtml('<section class="hero" style="background-image:url(https://example.com/hero.jpg);background-size:cover;height:345.5px"><h1>Build</h1><p>Ship</p></section>');
$heightCover = $heightResult['blocks'][0] ?? array();
$heightOpening = (string) ($heightCover['innerContent'][0] ?? '');
$heightCss = $assetCss($heightResult);
$heightCarrierClasses = array();
preg_match_all('/be-inline-geometry-[a-f0-9-]+/', (string) ($heightCover['attrs']['className'] ?? ''), $heightCarrierClasses);
$heightCarrierClasses = $heightCarrierClasses[0] ?? array();
$assertSame('core/cover', $heightCover['blockName'] ?? null, 'K3: Height-fallback hero remains core/cover.');
$assertSame(1, substr_count($heightOpening, 'min-height'), 'K3: Height-fallback hero opening markup emits min-height once.');
$assertTrue(! (bool) preg_match('/(?<!min-)height:345\.5px/', $heightOpening), 'K3: Height-fallback hero keeps the wrapper inline style save()-canonical (no inline height the editor would flag for recovery).');
$assertTrue(! isset($heightCover['attrs']['style']['dimensions']['minHeight']), 'K3: Height-fallback hero removes duplicate style.dimensions.minHeight.');
$assertTrue($heightCarrierClasses !== array(), 'K3: Definite-height hero carries a generated geometry carrier class on the cover.');
$heightCarrierHasHeight = false;
foreach ( $heightCarrierClasses as $heightCarrierClass ) {
    if ( str_contains($heightCss, ':root .' . $heightCarrierClass . '{height:345.5px}')
        && ! str_contains($heightCss, '.' . $heightCarrierClass . '{height:345.5px !important}') ) {
        $heightCarrierHasHeight = true;
    }
}
$assertTrue($heightCarrierHasHeight, 'K3: Definite-height hero pins the cover box through a :root carrier height rule without !important.');

// K4: CSS-owned semantic flex heroes retain their source section and direct children.
$columnsResult = $transformHtml('<section style="display:flex;background-image:url(https://example.com/h.jpg);background-size:cover"><div style="flex:1"><h1>Left</h1></div><div style="flex:1"><p>Right</p></div></section>');
$columnsNames = $blockNames($columnsResult['blocks'] ?? array());
$assertTrue(in_array('core/group', $columnsNames, true) && ! in_array('core/columns', $columnsNames, true), 'K4: Flex hero candidate preserves section topology through core/group without layout defaults.');
$assertTrue(! in_array('core/cover', $columnsNames, true), 'K4: Flex hero candidate does not become core/cover.');

// K5: Navigation-bearing shell wrappers never become core/cover.
$shellResult = $transformHtml('<div style="background-image:url(https://example.com/h.jpg);background-size:cover"><nav><a href="/a">A</a><a href="/b">B</a></nav><h1>T</h1><p>Body</p></div>');
$shellNames = $blockNames($shellResult['blocks'] ?? array());
$assertTrue(! in_array('core/cover', $shellNames, true), 'K5: Navigation-bearing shell rejects core/cover.');

$fallbacks = array( array( 'blockName' => 'existing/fallback' ) );
$record = array();
$navigation = array(
    'blockName'   => 'core/group',
    'attrs'       => array(),
    'innerBlocks' => array(
        array( 'blockName' => 'core/navigation', 'attrs' => array(), 'innerBlocks' => array() ),
        $paragraph,
    ),
);
$assertNull($match($hero, array( $navigation ), array(), array(), $fallbacks, $record), 'K5: Recursive core/navigation child rejects cover.');
$assertSame(array( array( 'blockName' => 'existing/fallback' ) ), $fallbacks, 'K5: Navigation rejection discards local fallbacks.');

// K6/N3/P3: Design gradients become visible core customGradient attrs on the overlay span.
$gradientResult = $transformHtml('<section class="hero" style="background:linear-gradient(90deg,#ff0000,#0000ff),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$designGradientCover = $gradientResult['blocks'][0] ?? array();
$designGradientOpening = (string) ($designGradientCover['innerContent'][0] ?? '');
$designGradientWrapper = (string) strstr($designGradientOpening, '<img', true);
$assertSame('core/cover', $designGradientCover['blockName'] ?? null, 'K6: Design-gradient hero remains core/cover.');
$assertTrue(! str_contains((string) json_encode($designGradientCover['attrs'] ?? array()), 'url('), 'K6: Cover attrs contain no URL layer.');
$assertTrue(! str_contains($designGradientOpening, 'url('), 'K6: Cover opening markup contains no CSS URL layer.');
$assertSame('linear-gradient(90deg,#ff0000,#0000ff)', $designGradientCover['attrs']['customGradient'] ?? null, 'N3: Design gradient lands in customGradient.');
$assertSame(100, $designGradientCover['attrs']['dimRatio'] ?? null, 'P3: Design gradient uses full overlay opacity.');
$assertTrue(! isset($designGradientCover['attrs']['customOverlayColor']), 'P3: Design gradient emits no customOverlayColor.');
$assertTrue(! isset($designGradientCover['attrs']['style']['color']['gradient']), 'N3: Design gradient leaves no style.color.gradient attr.');
$assertTrue(
    str_contains($designGradientOpening, 'class="wp-block-cover__background has-background-dim-100 has-background-dim wp-block-cover__gradient-background has-background-gradient"'),
    'P3: Design gradient span carries full-opacity core classes.'
);
$assertTrue(str_contains($designGradientOpening, 'style="background:linear-gradient(90deg,#ff0000,#0000ff)"'), 'N3: Overlay span paints customGradient.');
$assertTrue(! str_contains($designGradientWrapper, 'linear-gradient'), 'N3: Wrapper opening carries no design gradient.');

$layeredGradientResult = $transformHtml('<section class="hero" style="background:linear-gradient(90deg,#ff0000,#0000ff),radial-gradient(circle,#ffffff,#000000),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$layeredGradientCover = $layeredGradientResult['blocks'][0] ?? array();
$assertSame(
    'linear-gradient(90deg,#ff0000,#0000ff),radial-gradient(circle,#ffffff,#000000)',
    $layeredGradientCover['attrs']['customGradient'] ?? null,
    'N3: Multiple surviving gradient layers remain joined by a top-level comma.'
);

// N4/P5: Uniform overlays remain dim/color attrs and never become customGradient.
$uniformGradientResult = $transformHtml('<section class="hero" style="background:linear-gradient(0deg, rgba(0,0,0,.5), rgba(0,0,0,.5)),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$uniformGradientCover = $uniformGradientResult['blocks'][0] ?? array();
$uniformGradientOpening = (string) ($uniformGradientCover['innerContent'][0] ?? '');
$assertSame(50, $uniformGradientCover['attrs']['dimRatio'] ?? null, 'N4: Uniform overlay keeps dimRatio 50.');
$assertSame('#000000', $uniformGradientCover['attrs']['customOverlayColor'] ?? null, 'N4: Uniform overlay keeps customOverlayColor.');
$assertTrue(! isset($uniformGradientCover['attrs']['customGradient']), 'N4: Uniform overlay emits no customGradient.');
$assertTrue(! isset($uniformGradientCover['attrs']['style']['color']['gradient']), 'N4: Uniform overlay leaves no style.color.gradient attr.');
$assertTrue(str_contains($uniformGradientOpening, 'has-background-dim'), 'N4: Uniform overlay span carries has-background-dim.');
$assertTrue(! str_contains($uniformGradientOpening, 'has-background-dim-50'), 'P5: Uniform overlay omits the ratio-50 step class.');
$assertTrue(! str_contains($uniformGradientOpening, 'has-background-gradient'), 'N4: Uniform overlay span omits has-background-gradient.');

$degenerateUniformResult = $transformHtml('<section class="hero" style="background:linear-gradient(rgba(0,0,0,.04),rgba(0,0,0,0.04)),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$degenerateUniformCover = $degenerateUniformResult['blocks'][0] ?? array();
$assertSame(0, $degenerateUniformCover['attrs']['dimRatio'] ?? null, 'P5: Semantically equal degenerate uniform stops keep dimRatio 0.');
$assertTrue(! isset($degenerateUniformCover['attrs']['customOverlayColor']), 'P5: Degenerate uniform overlay emits no customOverlayColor.');

$sidewaysUniformResult = $transformHtml('<section class="hero" style="background:linear-gradient(90deg,rgba(0,0,0,.5),rgba(0,0,0,.5)),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$sidewaysUniformCover = $sidewaysUniformResult['blocks'][0] ?? array();
$assertSame(0, $sidewaysUniformCover['attrs']['dimRatio'] ?? null, 'P5: Uniform-only 90deg gradient keeps existing dimRatio 0 derivation.');
$assertTrue(! isset($sidewaysUniformCover['attrs']['customOverlayColor']), 'P5: Uniform-only 90deg gradient emits no customOverlayColor.');

$crossSyntaxUniformResult = $transformHtml('<section class="hero" style="background:linear-gradient(#000,rgb(0,0,0)),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$crossSyntaxUniformCover = $crossSyntaxUniformResult['blocks'][0] ?? array();
$assertSame(0, $crossSyntaxUniformCover['attrs']['dimRatio'] ?? null, 'P5: Cross-syntax uniform stops keep existing dimRatio 0 derivation.');
$assertTrue(! isset($crossSyntaxUniformCover['attrs']['customOverlayColor']), 'P5: Cross-syntax uniform gradient emits no customOverlayColor.');

$radialUniformResult = $transformHtml('<section class="hero" style="background:radial-gradient(circle,rgba(0,0,0,.04),rgba(0,0,0,0.04)),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$radialUniformCover = $radialUniformResult['blocks'][0] ?? array();
$assertSame(0, $radialUniformCover['attrs']['dimRatio'] ?? null, 'P5: Uniform-only radial gradient keeps existing dimRatio 0 derivation.');
$assertTrue(! isset($radialUniformCover['attrs']['customOverlayColor']), 'P5: Uniform-only radial gradient emits no customOverlayColor.');

$conicUniformResult = $transformHtml('<section class="hero" style="background:conic-gradient(from 45deg,rgba(0,0,0,.04),rgba(0,0,0,0.04)),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$conicUniformCover = $conicUniformResult['blocks'][0] ?? array();
$assertSame(0, $conicUniformCover['attrs']['dimRatio'] ?? null, 'P5: Uniform-only conic gradient keeps existing dimRatio 0 derivation.');
$assertTrue(! isset($conicUniformCover['attrs']['customOverlayColor']), 'P5: Uniform-only conic gradient emits no customOverlayColor.');

// P4: Mixed uniform and design gradients all remain in customGradient, in source order.
$mixedGradientResult = $transformHtml('<section class="hero" style="background:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),linear-gradient(90deg,rgba(255,0,0,.55),rgba(0,0,255,.55)),url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$mixedGradientCover = $mixedGradientResult['blocks'][0] ?? array();
$mixedGradientOpening = (string) ($mixedGradientCover['innerContent'][0] ?? '');
$assertSame(100, $mixedGradientCover['attrs']['dimRatio'] ?? null, 'P4: Mixed gradient uses full overlay opacity.');
$assertTrue(! isset($mixedGradientCover['attrs']['customOverlayColor']), 'P4: Mixed gradient emits no customOverlayColor.');
$assertSame(
    'linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),linear-gradient(90deg,rgba(255,0,0,.55),rgba(0,0,255,.55))',
    $mixedGradientCover['attrs']['customGradient'] ?? null,
    'P4: Mixed gradient keeps every gradient layer in source order.'
);
$assertTrue(
    str_contains($mixedGradientOpening, 'class="wp-block-cover__background has-background-dim-100 has-background-dim wp-block-cover__gradient-background has-background-gradient" style="background:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),linear-gradient(90deg,rgba(255,0,0,.55),rgba(0,0,255,.55))"'),
    'P4: Mixed overlay span carries full-opacity core classes and source-order gradient style.'
);

$duplicateUniformGradient = 'linear-gradient(0deg, rgba(0,0,0,.5), rgba(0,0,0,.5))';
$duplicateUniformResult = $transformHtml('<section class="hero" style="background:' . $duplicateUniformGradient . ',' . $duplicateUniformGradient . ',url(https://example.com/h.jpg) center/cover;min-height:480px"><h1>T</h1><p>B</p></section>');
$duplicateUniformCover = $duplicateUniformResult['blocks'][0] ?? array();
$assertSame(50, $duplicateUniformCover['attrs']['dimRatio'] ?? null, 'N3/N4: First duplicate uniform gradient becomes dimRatio.');
$assertSame('#000000', $duplicateUniformCover['attrs']['customOverlayColor'] ?? null, 'N3/N4: First duplicate uniform gradient becomes overlay color.');
$assertSame($duplicateUniformGradient, $duplicateUniformCover['attrs']['customGradient'] ?? null, 'N3/N4: Second duplicate uniform gradient survives as customGradient.');

// K7: Multiple image layers reject cover instead of dropping a layer.
$multiLayerStyle = 'background-image:url(https://example.com/top.png),url(https://example.com/bottom.png);background-size:cover;min-height:480px';
$multiLayerResult = $transformHtml('<section class="hero" style="' . $multiLayerStyle . '"><h1>T</h1><p>B</p></section>');
$multiLayerNames = $blockNames($multiLayerResult['blocks'] ?? array());
$assertTrue(! in_array('core/cover', $multiLayerNames, true), 'K7: Multi-layer background rejects core/cover.');
$assertSame('multi_layer_background', $pattern->rejectionGate($multiLayerStyle, true), 'K7: rejectionGate reports multi_layer_background.');

// K8: Cover URL follows source-order cascade.
$resetResult = $transformHtml('<section class="hero" style="background-image:url(https://example.com/gone.jpg);background-size:cover;min-height:480px;background:#fff"><h1>T</h1><p>B</p></section>');
$assertTrue(! in_array('core/cover', $blockNames($resetResult['blocks'] ?? array()), true), 'K8: Later background reset rejects core/cover.');

$laterUrlResult = $transformHtml('<section class="hero" style="background:url(https://example.com/first.jpg) center/cover;background-image:url(https://example.com/second.jpg);min-height:480px"><h1>T</h1><p>B</p></section>');
$laterUrlCover = $laterUrlResult['blocks'][0] ?? array();
$assertSame('core/cover', $laterUrlCover['blockName'] ?? null, 'K8: Later background-image URL remains core/cover.');
$assertSame('https://example.com/second.jpg', $laterUrlCover['attrs']['url'] ?? null, 'K8: Later background-image URL wins.');

echo "cover pattern ok\n";

exit(0 === $failures ? 0 : 1);

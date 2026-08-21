<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

// A fragment (no <style> of its own) whose hero <img> gets its 4/3 crop from a
// descendant rule the transform flattens (`.hero-frame img`). The rule lives in
// a caller-supplied author stylesheet, which must reach HtmlTransformer through
// compileFragment -> FormatBridge::convertResult -> HtmlAdapter::transformResult
// as the `static_css` option, so the crop promotes to native aspectRatio/scale.
$fragment = '<figure class="hero-figure"><div class="hero-frame">'
    . '<img src="https://example.com/creative-director.jpg" alt="Creative director portrait">'
    . '</div><figcaption>Creative Director</figcaption></figure>';
$authorCss = '.hero-figure{margin:0}.hero-frame{position:relative;overflow:hidden}'
    . '.hero-frame img{width:100%;aspect-ratio:4 / 3;object-fit:cover;filter:contrast(1.06)}';

$compiler = new ArtifactCompiler();

// Baseline: without the author stylesheet, the descendant shape rule is
// unreachable and the image stays a plain core/image (no crop attributes).
$without = $compiler->compileFragment($fragment, 'design/home.html');
$assert(
    'success' === $without->status,
    'compileFragment without author CSS still succeeds'
);
$assert(
    ! str_contains($without->serializedBlocks, '"aspectRatio"'),
    'compileFragment without author CSS leaves the image uncropped (no aspectRatio)'
);

// With the author stylesheet threaded as `static_css`, the shape constraint is
// resolved and promoted to native block attributes.
$with = $compiler->compileFragment(
    $fragment,
    'design/home.html',
    'html',
    array( 'static_css' => $authorCss )
);
$assert(
    'success' === $with->status,
    'compileFragment with author CSS succeeds'
);
$block = $with->blocks[0] ?? array();
$attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
$assert(
    'core/image' === ($block['blockName'] ?? ''),
    'first block is a core/image'
);
$assert(
    '4/3' === ($attrs['aspectRatio'] ?? null),
    'author CSS promotes aspectRatio 4/3 through the fragment path'
);
$assert(
    'cover' === ($attrs['scale'] ?? null),
    'author CSS promotes scale cover through the fragment path'
);
$assert(
    str_contains($with->serializedBlocks, '"aspectRatio":"4/3"')
        && str_contains($with->serializedBlocks, '"scale":"cover"'),
    'serialized fragment blocks carry the native crop attributes'
);

// CSS inspection is based on a matching selector, not a vocabulary-derived
// class or tag name. A higher-specificity authored rule must still be the value
// a core-block recognizer sees.
$arbitraryFragment = '<figure class="q7v"><div class="n3x"><img src="https://example.com/arbitrary.jpg" alt="Arbitrary naming"></div></figure>';
$arbitrary = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '.n3x img{aspect-ratio:1 / 1;object-fit:contain}.q7v .n3x img{aspect-ratio:5 / 6;object-fit:cover}' )
);
$arbitraryAttrs = is_array($arbitrary->blocks[0]['attrs'] ?? null) ? $arbitrary->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($arbitraryAttrs['aspectRatio'] ?? null) && 'cover' === ($arbitraryAttrs['scale'] ?? null),
    'arbitrary class names and authored selector specificity drive native image recognition'
);
$arbitraryReverse = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '.q7v .n3x img{aspect-ratio:5 / 6;object-fit:cover}.n3x img{aspect-ratio:1 / 1;object-fit:contain}' )
);
$arbitraryReverseAttrs = is_array($arbitraryReverse->blocks[0]['attrs'] ?? null) ? $arbitraryReverse->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($arbitraryReverseAttrs['aspectRatio'] ?? null) && 'cover' === ($arbitraryReverseAttrs['scale'] ?? null),
    'higher-specificity arbitrary selectors win static crop recognition even when authored first'
);
$arbitraryResponsive = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '.n3x img{aspect-ratio:1 / 1;object-fit:contain}@media (min-width:1024px){.q7v .n3x img{aspect-ratio:5 / 6;object-fit:cover}}' )
);
$arbitraryResponsiveAttrs = is_array($arbitraryResponsive->blocks[0]['attrs'] ?? null) ? $arbitraryResponsive->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($arbitraryResponsiveAttrs['aspectRatio'] ?? null) && 'cover' === ($arbitraryResponsiveAttrs['scale'] ?? null),
    'arbitrary class names retain responsive CSS recognition through the fragment path'
);
$arbitraryResponsiveReverse = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '@media (min-width:1024px){.q7v .n3x img{aspect-ratio:5 / 6;object-fit:cover}.n3x img{aspect-ratio:1 / 1;object-fit:contain}}' )
);
$arbitraryResponsiveReverseAttrs = is_array($arbitraryResponsiveReverse->blocks[0]['attrs'] ?? null) ? $arbitraryResponsiveReverse->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($arbitraryResponsiveReverseAttrs['aspectRatio'] ?? null) && 'cover' === ($arbitraryResponsiveReverseAttrs['scale'] ?? null),
    'higher-specificity arbitrary selectors win responsive crop recognition even when authored first'
);
$conditionalBeforeStatic = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '@media (min-width:1024px){.n3x img{aspect-ratio:5 / 6;object-fit:cover}}.n3x img{aspect-ratio:1 / 1;object-fit:contain}' )
);
$conditionalBeforeStaticAttrs = is_array($conditionalBeforeStatic->blocks[0]['attrs'] ?? null) ? $conditionalBeforeStatic->blocks[0]['attrs'] : array();
$assert(
    '1/1' === ($conditionalBeforeStaticAttrs['aspectRatio'] ?? null) && 'contain' === ($conditionalBeforeStaticAttrs['scale'] ?? null),
    'a later static rule wins an earlier applicable responsive rule at equal specificity'
);
$duplicateDeclarations = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '.n3x img{aspect-ratio:5 / 6 !important;aspect-ratio:1 / 1;object-fit:cover !important;object-fit:contain}' )
);
$duplicateDeclarationAttrs = is_array($duplicateDeclarations->blocks[0]['attrs'] ?? null) ? $duplicateDeclarations->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($duplicateDeclarationAttrs['aspectRatio'] ?? null) && 'cover' === ($duplicateDeclarationAttrs['scale'] ?? null),
    'important duplicate crop declarations beat later normal declarations in one rule'
);
$inlineDuplicateFragment = '<figure class="q7v"><div class="n3x"><img src="https://example.com/arbitrary.jpg" alt="Arbitrary naming" style="aspect-ratio:5 / 6 !important;aspect-ratio:1 / 1;object-fit:cover !important;object-fit:contain"></div></figure>';
$inlineDuplicate = $compiler->compileFragment($inlineDuplicateFragment, 'design/home.html', 'html');
$inlineDuplicateAttrs = is_array($inlineDuplicate->blocks[0]['attrs'] ?? null) ? $inlineDuplicate->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($inlineDuplicateAttrs['aspectRatio'] ?? null) && 'cover' === ($inlineDuplicateAttrs['scale'] ?? null),
    'important duplicate inline crop declarations beat later normal declarations'
);
$layeredCrop = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '@layer components{.n3x img{aspect-ratio:5 / 6;object-fit:cover}}' )
);
$layeredCropAttrs = is_array($layeredCrop->blocks[0]['attrs'] ?? null) ? $layeredCrop->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($layeredCropAttrs['aspectRatio'] ?? null) && 'cover' === ($layeredCropAttrs['scale'] ?? null),
    'layer-grouped crop declarations remain applicable at desktop'
);
$supportedCrop = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '@supports (aspect-ratio:1 / 1){.n3x img{aspect-ratio:5 / 6;object-fit:cover}}' )
);
$supportedCropAttrs = is_array($supportedCrop->blocks[0]['attrs'] ?? null) ? $supportedCrop->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($supportedCropAttrs['aspectRatio'] ?? null) && 'cover' === ($supportedCropAttrs['scale'] ?? null),
    'positively-known supports conditions retain crop declarations'
);
$unsupportedCrop = $compiler->compileFragment(
    $arbitraryFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '@supports (unknown-crop-feature:value){.n3x img{aspect-ratio:5 / 6;object-fit:cover}}' )
);
$unsupportedCropAttrs = is_array($unsupportedCrop->blocks[0]['attrs'] ?? null) ? $unsupportedCrop->blocks[0]['attrs'] : array();
$assert(
    ! isset($unsupportedCropAttrs['aspectRatio']) && ! isset($unsupportedCropAttrs['scale']),
    'unresolved supports conditions do not flatten into crop declarations'
);

/**
 * Resolve the first block's attributes for a fragment compiled against $css.
 *
 * @return array<string, mixed>
 */
$cropAttributes = static function (string $css) use ($compiler, $fragment): array {
    $result = $compiler->compileFragment($fragment, 'design/home.html', 'html', array( 'static_css' => $css ));
    $block = $result->blocks[0] ?? array();

    return is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
};

// `object-fit:cover !important` is the usual defence against core's
// `.wp-block-image img` rules. Importance must be stripped from the keyword the
// same way it is from aspect-ratio, or the allowlist never matches and the whole
// promotion silently declines.
$important = $cropAttributes(
    '.hero-figure{margin:0}.hero-frame img{aspect-ratio:4 / 3;object-fit:cover !important}'
);
$assert(
    '4/3' === ($important['aspectRatio'] ?? null) && 'cover' === ($important['scale'] ?? null),
    'an !important object-fit still promotes, with importance stripped from the scale keyword'
);

// Breakpoints authored in em/rem resolve against the root font size, so
// `min-width:64em` is the same 1024px desktop override as `min-width:1024px` and
// must win over the base rule the same way. (Range syntax stays unsupported.)
$emBreakpoint = $cropAttributes(
    '.hero-figure{margin:0}.hero-frame img{aspect-ratio:4 / 3;object-fit:cover}'
        . '@media (min-width: 64em){.hero-frame img{aspect-ratio:5 / 6}}'
);
$assert(
    '5/6' === ($emBreakpoint['aspectRatio'] ?? null),
    'an em-authored min-width breakpoint resolves at 16px per em and wins the desktop slot'
);

// An inline declaration outranks every matched stylesheet rule at normal
// importance, whatever viewport that rule is bound to. A desktop @media override
// must not displace it.
$inlineFragment = '<figure class="hero-figure"><div class="hero-frame">'
    . '<img src="https://example.com/creative-director.jpg" alt="Creative director portrait" style="aspect-ratio:1/1">'
    . '</div></figure>';
$inlineOverMedia = $compiler->compileFragment(
    $inlineFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '.hero-frame img{object-fit:cover}@media (min-width: 1024px){.hero-frame img{aspect-ratio:5 / 6}}' )
);
$inlineAttrs = is_array($inlineOverMedia->blocks[0]['attrs'] ?? null) ? $inlineOverMedia->blocks[0]['attrs'] : array();
$assert(
    '1/1' === ($inlineAttrs['aspectRatio'] ?? null),
    'an inline aspect-ratio outranks a desktop @media override at normal importance'
);

// The inline win is about cascade importance, not about ignoring media rules: an
// !important override still beats a normal inline declaration.
$importantMedia = $compiler->compileFragment(
    $inlineFragment,
    'design/home.html',
    'html',
    array( 'static_css' => '.hero-frame img{object-fit:cover}@media (min-width: 1024px){.hero-frame img{aspect-ratio:5 / 6 !important}}' )
);
$importantMediaAttrs = is_array($importantMedia->blocks[0]['attrs'] ?? null) ? $importantMedia->blocks[0]['attrs'] : array();
$assert(
    '5/6' === ($importantMediaAttrs['aspectRatio'] ?? null),
    'an !important desktop override still outranks a normal inline aspect-ratio'
);

$inlineScaleOnly = $compiler->compileFragment(
    '<img src="https://example.com/crop.jpg" alt="Crop" style="object-fit:cover">',
    'design/home.html',
    'html'
);
$inlineScaleOnlyAttrs = is_array($inlineScaleOnly->blocks[0]['attrs'] ?? null) ? $inlineScaleOnly->blocks[0]['attrs'] : array();
$assert(
    'cover' === ($inlineScaleOnlyAttrs['scale'] ?? null) && ! isset($inlineScaleOnlyAttrs['aspectRatio']),
    'an explicit inline object-fit becomes a scale-only native image attribute without inventing box geometry'
);

if ( 0 < $failures ) {
    exit(1);
}

echo "compile-fragment author stylesheet unit tests: {$passes} passed\n";

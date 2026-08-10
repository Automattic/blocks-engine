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

if ( 0 < $failures ) {
    exit(1);
}

echo "compile-fragment author stylesheet unit tests: {$passes} passed\n";

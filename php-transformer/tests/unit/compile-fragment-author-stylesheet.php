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

if ( 0 < $failures ) {
    exit(1);
}

echo "compile-fragment author stylesheet unit tests: {$passes} passed\n";

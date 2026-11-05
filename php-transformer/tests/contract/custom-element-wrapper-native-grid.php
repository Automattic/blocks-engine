<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$css = 'interact-element{display:contents}.grid{display:grid;grid-template-columns:200px 300px;gap:24px}'
    . '#frame1{grid-area:1 / 1 / 2 / 2;width:200px}#frame2{grid-area:1 / 2 / 2 / 3;width:300px}'
    . '[data-interact-key="k1"] :is(.frame){transform:translateY(40px)}';
$html = '<!doctype html><html><head><link rel="stylesheet" href="grid.css"></head><body><main><div class="grid" id="grid">'
    . '<interact-element data-interact-key="k1"><div class="frame" id="frame1"><img src="one.jpg" alt="One" width="200" height="150"><h2>Native heading</h2></div></interact-element>'
    . '<interact-element data-interact-key="k2"><div class="frame" id="frame2"><img src="two.jpg" alt="Two" width="300" height="150"><p>Native paragraph.</p></div></interact-element>'
    . '</div></main></body></html>';

$artifact = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 'website/grid.css', 'kind' => 'css', 'content' => $css),
    ),
) )->toArray();
$markup = (string) ($artifact['serialized_blocks'] ?? '');
preg_match_all('/<!-- wp:([^\s>]+)/', $markup, $blocks);
$assert(array() !== $blocks[1], 'the page emits blocks');
foreach ( $blocks[1] as $name ) {
    $assert(! str_contains($name, '/') || str_starts_with($name, 'core/'), 'every emitted block is native: ' . $name);
    $assert(! in_array($name, array('html', 'core/html'), true), 'no raw HTML escape hatch');
}
$assert(2 === substr_count($markup, '<!-- wp:image '), 'both images are native Image blocks');
$assert(str_contains($markup, '<!-- wp:heading '), 'the heading is native');
$assert(str_contains($markup, '<!-- wp:paragraph '), 'the paragraph is native');

$frontendCss = '';
foreach ( $artifact['assets'] ?? array() as $asset ) {
    if ( str_ends_with((string) ($asset['path'] ?? ''), '.css') && ! str_contains($asset['path'], 'editor-static-state') ) {
        $frontendCss .= "\n" . $asset['content'];
    }
}
$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<!doctype html><html><body>' . preg_replace('/<!--.*?-->/s', '', $markup) . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$cascade = new StaticCssCascade($document, $frontendCss);
foreach ( array(1, 2) as $index ) {
    $frame = $document->getElementById('frame' . $index);
    $assert($frame instanceof DOMElement, 'frame identity survives');
    if ( ! $frame instanceof DOMElement ) {
        continue;
    }
    $wrapper = $frame->parentNode;
    $assert($wrapper instanceof DOMElement && str_contains($wrapper->getAttribute('class'), 'wp-block-group'), 'the selector hook is a native Group');
    if ( $wrapper instanceof DOMElement ) {
        $style = $cascade->resolve($wrapper, array('display'), array());
        $assert('contents' === ($style['display'] ?? ''), 'the emitted CSS keeps the wrapper boxless');
    }
    $style = $cascade->resolve($frame, array('transform', 'grid-area', 'width'), array());
    $assert(($index === 1 ? 'translateY(40px)' : '') === ($style['transform'] ?? ''), 'the k1 rule targets only the k1 frame');
    $assert(($index === 1 ? '1 / 1 / 2 / 2' : '1 / 2 / 2 / 3') === ($style['grid-area'] ?? ''), 'the frame keeps its grid placement');
    $assert(($index === 1 ? '200px' : '300px') === ($style['width'] ?? ''), 'the frame keeps its sizing');
}
$grid = $document->getElementById('grid');
$assert($grid instanceof DOMElement, 'grid identity survives');
if ( $grid instanceof DOMElement ) {
    $style = $cascade->resolve($grid, array('display', 'gap'), array());
    $assert('grid' === ($style['display'] ?? '') && '24px' === ($style['gap'] ?? ''), 'the parent retains its grid and gap');
}
if ( 0 !== $failures ) {
    exit(1);
}
echo "Custom element native grid wrapper contract tests passed\n";

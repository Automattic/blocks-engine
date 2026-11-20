<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A ratio declared on a bare image's own class moves to the synthetic figure
// with that class. The image keeps a definite height from another rule, so in
// the source the ratio never applies (the box is 260px tall). The figure must
// not apply the ratio on its own auto height and grow into a square.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><title>t</title><style>'
            . '.grid{display:grid}.grid img{display:block;width:100%;height:260px;object-fit:contain}.photo{display:block;width:100%;aspect-ratio:1}'
            . '</style></head><body><main><div class="grid"><a href="/a/"><img src="a.png" alt="A" class="photo"><h3>A</h3></a></div></main></body></html>',
    ),
))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    $css .= (string) ($asset['content'] ?? '');
}
if (!preg_match('/<figure class="[^"]*\bphoto\b/', $markup)) {
    throw new RuntimeException('The image class must move to its synthetic figure: ' . $markup);
}
if (!preg_match('/\.photo\.wp-block-image > img[^{]*\{[^}]*aspect-ratio:1/', $css)) {
    throw new RuntimeException('The bridge must restate the ratio on the image: ' . $css);
}
if (!preg_match('/\.photo\.wp-block-image\{aspect-ratio:auto\}/', $css)) {
    throw new RuntimeException('The synthetic figure must not apply the moved ratio to its own box: ' . $css);
}
echo "Moved image ratio stays on the image box.\n";

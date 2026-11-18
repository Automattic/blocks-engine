<?php
declare(strict_types=1);

/**
 * An HTML `height` attribute is a presentational hint for CSS `height`. When
 * author CSS states the image width (`img{width:100%}`) and an aspect-ratio
 * but no height, the hint is the used height: both axes are definite, so
 * aspect-ratio does not apply. core/image must keep that height rather than
 * treating it as file metadata and letting the ratio size the image.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$card = static fn (string $src, string $alt): string => '<article><div class="photo"><img src="' . $src . '" alt="' . $alt . '" width="600" height="390"></div><h3>' . $alt . '</h3></article>';
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Img</title>'
    . '<style>img{display:block;width:100%;object-fit:cover}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:28px;padding:40px}.photo{overflow:hidden}.photo img{aspect-ratio:1.55}</style></head>'
    . '<body><main><h1>News</h1><div class="cards">'
    . $card('a.jpg', 'One') . $card('b.jpg', 'Two') . $card('c.jpg', 'Three')
    . '</div></main></body></html>';

$compile = static fn (string $page): string => (string) (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array( 'website/index.html' => $page ),
))->toArray()['serialized_blocks'];

$markup = $compile($html);
if ( 3 !== preg_match_all('/<!-- wp:image (\{.*?\}) -->(.*?)<!-- \/wp:image -->/s', $markup, $matches, PREG_SET_ORDER) ) {
    throw new RuntimeException('expected three native images');
}
foreach ( $matches as $match ) {
    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    if ( '100%' !== ($attrs['width'] ?? null) || '390px' !== ($attrs['height'] ?? null) ) {
        throw new RuntimeException('presentational height must survive beside a CSS width: ' . $match[1]);
    }
    if ( ! str_contains($match[2], 'width:100%;height:390px') ) {
        throw new RuntimeException('rendered image must carry the used height: ' . $match[2]);
    }
}

// Control: when CSS states neither axis, the pair describes the file and the
// stylesheet-owned crop keeps sizing the image.
$control = $compile('<style>img{aspect-ratio:1/1;object-fit:cover}</style><main><p><img src="a.jpg" alt="One" width="600" height="390"></p></main>');
if ( ! preg_match('/<!-- wp:image (\{.*?\}) -->/', $control, $match) ) {
    throw new RuntimeException('control: expected a native image');
}
$attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
if ( isset($attrs['width']) || isset($attrs['height']) || '1/1' !== ($attrs['aspectRatio'] ?? null) ) {
    throw new RuntimeException('control: intrinsic pair must not override the native crop: ' . $match[1]);
}

echo "Image presentational height over aspect-ratio passed\n";

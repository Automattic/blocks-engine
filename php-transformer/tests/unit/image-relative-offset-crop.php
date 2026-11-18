<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A focal-point banner crop: the frame clips a relatively positioned image whose
// inline offset picks the visible slice and whose `max()` width overflows the
// frame. core/image saves neither on its <img>; both must still reach it.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => <<<'HTML'
<!doctype html><html><head><style>
.banner{position:relative;overflow:hidden;height:500px}
#thumbnail{position:absolute;top:0;right:0;bottom:0;left:0}
@media print{img{max-width:100% !important}}
</style></head><body><main><div class="banner">
<figure id="thumbnail" class="content-fill"><img src="banner.jpg" alt="Banner" style="position: relative;left: 0px;top: -226.312px;width: max(674px, 100vw);height: max(449px, 66.64vw)"></figure>
</div></main></body></html>
HTML,
)))->toArray();

$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$markup = $result['serialized_blocks'];
if (! preg_match('/<figure id="thumbnail" class="([^"]+)"/', $markup, $figure)) {
    throw new RuntimeException('The banner image must lower to a native image block.');
}
$rules = array();
foreach (preg_split('/\s+/', $figure[1]) ?: array() as $className) {
    if (preg_match_all('/\.' . preg_quote($className, '/') . ' img\{([^}]*)\}/', $css, $matches)) {
        $rules = array_merge($rules, $matches[1]);
    }
}
$declarations = implode(';', $rules);
if (! str_contains($declarations, 'position:relative') || ! str_contains($declarations, 'top:-226.312px')) {
    throw new RuntimeException('The source image offset must reach the lowered <img>: ' . $declarations);
}
if (! str_contains($declarations, 'max-width:none')) {
    throw new RuntimeException('A max() width floor must not be clamped by core image max-width (print rules never apply on screen): ' . $declarations);
}
echo "Image relative offset crop passed\n";

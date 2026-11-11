<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

foreach (array(
    'link' => '<link rel="stylesheet" href="a.css">',
    'media-link' => '<link rel="stylesheet" href="a.css" media="(min-width:641px)"><link rel="stylesheet" href="inactive.css" media="(max-width:640px)">',
    'media-import' => '<style>@import url("inactive.css") (max-width:480px);@import url("inactive.css") (min-width:481px) and (max-width:640px);@import url("a.css") (min-width:641px);</style>',
) as $delivery => $head) {
    foreach (array(
        'cover' => array('cover', '', 'cover', '25% 75%'),
        'contain' => array('contain', '', 'contain', '25% 75%'),
        'inline wins' => array('cover', ';background-size:contain;background-position:80% 20%', 'contain', '80% 20%'),
    ) as $label => [$authorSize, $inline, $scale, $position]) {
        $result = (new ArtifactCompiler())->compile(array('files' => array(
            array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head>' . $head . '</head><body><main><div class="hero"><div class="bg" style="background-image:url(x.jpg)' . $inline . '"></div><h2>Title</h2></div></main></body></html>'),
            array('path' => 'a.css', 'kind' => 'css', 'content' => '.hero{position:relative;height:600px}.hero .bg{background-size:' . $authorSize . ';background-position:25% 75%}.bg{background-size:auto;background-position:left top;position:absolute;inset:0;width:100%;height:100%}'),
            array('path' => 'inactive.css', 'kind' => 'css', 'content' => '.hero .bg{background-size:auto;background-position:right bottom}'),
        )))->toArray();
        $markup = (string) $result['serialized_blocks'];
        $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
        if (!preg_match('/<figure\b[^>]*class="([^"]*)"[^>]*>\s*<img\b/', $markup, $figure)
            || !str_contains($figure[1], 'blocks-engine-background-image-' . $scale)) {
            throw new RuntimeException($delivery . ' / ' . $label . ': author cascade must supply the lowered image scale.');
        }
        if (!preg_match('/\b(be-inline-geometry-[a-f0-9]+)\b/', $figure[1], $carrier)
            || !str_contains($css, '.' . $carrier[1] . '{object-position:' . $position . '}.' . $carrier[1] . '>img{object-position:inherit}')) {
            throw new RuntimeException($delivery . ' / ' . $label . ': author cascade must supply the lowered image position.');
        }
    }
}
echo "Background image author sizing contract passed.\n";

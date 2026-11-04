<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

foreach (array(
    'author cover flex' => array(true, 'cover', 'background-image:url(x.png)', 'none'),
    'inline cover flex' => array(false, 'cover', 'background-image:url(x.png)', 'none'),
    'author contain' => array(true, 'contain', 'background-image:url(x.png)', 'none'),
    'inline contain' => array(false, 'contain', 'background-image:url(x.png)', 'none'),
    'author layered' => array(true, 'cover', 'background-image:url(x.png),linear-gradient(red,blue)', 'none,linear-gradient(red,blue)'),
    'inline layered' => array(false, 'cover', 'background-image:url(x.png),linear-gradient(red,blue)', 'none,linear-gradient(red,blue)'),
    'author shorthand' => array(true, 'cover', 'background:url(x.png) center/cover no-repeat', 'none'),
    'inline layered shorthand' => array(false, 'cover', 'background:url(x.png) center/cover no-repeat,linear-gradient(red,blue)', 'none,linear-gradient(red,blue)'),
    'author layered shorthand' => array(true, 'cover', 'background:url(x.png) center/cover no-repeat,linear-gradient(red,blue)', 'none,linear-gradient(red,blue)'),
    'important author' => array(true, 'cover', 'background-image:url(x.png)!important', 'none'),
) as $label => [$authorRule, $scale, $background, $remaining]) {
    $style = 'display:flex;width:242px;height:149px;' . $background . ';background-size:' . $scale . ';background-color:#abcdef';
    $html = $authorRule
        ? '<style>.photo{' . $style . '}</style><div class="photo"></div>'
        : '<div class="photo" style="' . $style . '"></div>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) $result['serialized_blocks'];
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    if (!preg_match('/<div\b[^>]*class="([^"]*\bphoto\b[^"]*)"[^>]*>(?:\s|<!--.*?-->)*<figure\b[^>]*class="([^"]*)"/', $markup, $owner)
        || !str_contains($owner[2], 'blocks-engine-background-image-' . $scale)) {
        throw new RuntimeException($label . ': source group must own the sized image.');
    }
    if (!str_contains($css, '.blocks-engine-background-image-contain){width:100%;height:100%}')) {
        throw new RuntimeException($label . ': figure must fill both axes.');
    }
    if (!preg_match('/:root \.(be-inline-geometry-[a-f0-9]+)\{background-image:' . preg_quote($remaining, '/') . ' !important\}/', $css, $carrier)
        || !str_contains($owner[1], $carrier[1])) {
        throw new RuntimeException($label . ': owning group must suppress only the materialized layer.');
    }
    if (!str_contains($css . $markup, '#abcdef')) {
        throw new RuntimeException($label . ': source background colour must survive.');
    }
}
echo "Sized background image contract passed.\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/unlinked-inline-image-strut.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
$figures = $xpath->query('//figure[img]');
if (false === $figures || 2 !== $figures->length) {
    throw new RuntimeException('Both unlinked source images must become native image figures.');
}
$strutClass = 'blocks-engine-synthetic-image-figure-strut';
$positionedClass = 'blocks-engine-synthetic-image-figure-inline';
// Require the hidden guard inside :where(): it adds zero specificity, keeping
// the original :root + class specificity (0,2,0), unlike bare :not([hidden]).
if (!str_contains($figures->item(0)->getAttribute('class'), $strutClass)
    || !str_contains($css, ':root .' . $strutClass . ':where(:not([hidden])){display:contents}')
    || !str_contains($css, '.' . $strutClass . ' img{display:inline}')
    || !str_contains($css, 'figure.wp-block-image:where(.' . $strutClass . ') img{vertical-align:baseline}')) {
    throw new RuntimeException('The unlinked inline image must keep its parent line box through a transparent strut figure on the source baseline.');
}
// The strut figure is not the positioning carrier: positioning inheritance
// belongs to authored positioned leaves in inline figures, not every image.
if (str_contains($markup, $positionedClass) || str_contains($css, 'position:inherit;inset:inherit')) {
    throw new RuntimeException('A strut figure must not inherit or carry figure positioning.');
}
if (str_contains($figures->item(1)->getAttribute('class'), $strutClass)
    || !str_contains($css, ':where(.blocks-engine-image).wp-block-image > img,:where(.blocks-engine-image).wp-block-image > a > img{display:block;')) {
    throw new RuntimeException('The block source image must retain ordinary block image support.');
}
// Source positioning can live in a conditional stylesheet, not the current
// viewport. No carousel markup is needed to exercise this sizing boundary.
foreach (array('absolute', 'fixed') as $position) {
    $source = '<style>@media(min-width:1280px){.shell{position:' . $position . ';left:50%;transform:translateX(-50%)}}</style>'
        . '<div class="shell"><img src="logo.svg" width="100" height="60" alt="Positioned"></div>'
        . '<div style="position:relative"><img src="photo.svg" width="100" height="60" alt="In flow"></div>';
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    $document = new DOMDocument();
    @$document->loadHTML((string) $compiled['serialized_blocks']);
    $figures = (new DOMXPath($document))->query('//figure[img]');
    if (false === $figures || 2 !== $figures->length
        || str_contains($figures->item(0)->getAttribute('class'), $strutClass)
        || !str_contains($figures->item(1)->getAttribute('class'), $strutClass)) {
        throw new RuntimeException('Conditional out-of-flow parents must keep figure boxes; relative parents must retain the inline strut.');
    }
}
// Author positioning on an image never replays onto a strut leaf, and an
// inline SVG icon keeps its figure box so image-only rules cannot reach it.
$source = '<style>.media img{width:100%}@media (max-width:600px){.media img{position:absolute;inset:0}}</style>'
    . '<main><div class="media"><img src="photo.webp" alt="Photo"><div><svg width="24" height="24"><path d="M0 0L10 10"/></svg></div></div></main>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$compiledCss = implode("\n", array_column(array_filter($compiled['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if (!str_contains((string) $compiled['serialized_blocks'], $strutClass) || 1 === preg_match('/:where\(\.' . $strutClass . '\)[^{}]*\{[^}]*position:/', $compiledCss)) {
    throw new RuntimeException('A conditionally positioned photo keeps its strut, but its authored positioning must not replay onto the strut leaf.');
}
$document = new DOMDocument();
@$document->loadHTML((string) $compiled['serialized_blocks']);
foreach ((new DOMXPath($document))->query('//figure[img[contains(@src,"materialized-svg")]]') as $icon) {
    if (str_contains($icon->getAttribute('class'), $strutClass)) {
        throw new RuntimeException('A materialized SVG icon must keep its figure box.');
    }
}
echo "Unlinked inline image strut contract passed.\n";

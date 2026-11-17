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
$inlineClass = 'blocks-engine-synthetic-image-figure-inline';
if (!str_contains($figures->item(0)->getAttribute('class'), $inlineClass)
    || !str_contains($css, ':root .' . $inlineClass . '{display:contents}')
    || !str_contains($css, '.' . $inlineClass . ' img{display:inline}')) {
    throw new RuntimeException('The unlinked inline image must keep its parent line box through the existing transparent figure mechanism.');
}
if (str_contains($figures->item(1)->getAttribute('class'), $inlineClass)
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
        || str_contains($figures->item(0)->getAttribute('class'), $inlineClass)
        || !str_contains($figures->item(1)->getAttribute('class'), $inlineClass)) {
        throw new RuntimeException('Conditional out-of-flow parents must keep figure boxes; relative parents must retain the inline strut.');
    }
}
echo "Unlinked inline image strut contract passed.\n";

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
echo "Unlinked inline image strut contract passed.\n";

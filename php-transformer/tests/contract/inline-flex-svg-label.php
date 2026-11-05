<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/inline-flex-svg-label.html');
foreach (array($html, str_replace('Drag to turn', '<a href="/turn">Drag to turn</a>', $html)) as $source) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    $markup = $result['serialized_blocks'];
    if (!str_contains($markup, 'blocks-engine-source-box-paragraph') || !str_contains($markup, '<img ')) {
        throw new RuntimeException('The SVG label must retain its source layout box in an editable paragraph.');
    }
    $native = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($source)->toArray();
    if ('pass' !== ($native['source_reports']['wp_block_validity']['status'] ?? '')) {
        throw new RuntimeException('The SVG label must be editor-valid.');
    }
}
echo "OK: inline-flex SVG label retains its source layout box\n";

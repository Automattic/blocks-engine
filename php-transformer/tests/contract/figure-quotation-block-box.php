<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>.rail{display:flex}.item{width:280px;margin:0;padding:22px 24px 18px}.item blockquote{margin:0 0 12px;font-size:14px;line-height:1.45}.item figcaption{font-size:9px}</style><main><div class="rail"><figure class="item"><blockquote>A quotation that wraps onto two lines in its rail.</blockquote><figcaption>Publication</figcaption></figure></div></main>'),
))->toArray();
$html = $result['serialized_blocks'];
if (!str_contains($html, 'blocks-engine-source-box-paragraph')) {
    throw new RuntimeException('The consumed blockquote must keep a block paragraph box rather than an inline synthetic run.');
}
if (!str_contains($html, 'Publication') || !str_contains($html, '12px')) {
    throw new RuntimeException('The quotation must retain its caption and authored bottom margin.');
}
echo "Figure quotation block box contract passed.\n";

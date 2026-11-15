<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<main><blockquote><p>A considered portrait.</p><footer class="attribution"><span class="rule" style="display:block;width:48px;height:1px;background:red"></span><div><p>The artist</p><p>Founder</p></div></footer></blockquote></main>'),
))->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '<cite>') || !str_contains($markup, 'attribution') || !str_contains($markup, 'rule') || !str_contains($markup, '<!-- wp:quote')) {
    throw new RuntimeException('Structured quote attributions must retain their editable layout boxes, not become citation RichText.');
}
echo "Structured quote attribution passed\n";

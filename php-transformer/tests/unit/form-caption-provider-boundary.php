<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<main><form class="signup"><label>News by post</label><div class="row"><input type="email" placeholder="Email"><button type="submit">Subscribe</button></div></form></main><style>.signup{margin-top:40px}.row{display:flex;align-items:center}</style>'),
))->toArray();
$markup = $result['serialized_blocks'];
// A controls-only preserved slot would leave this caption in the page while
// provider metadata independently materializes the same caption and spacing.
if (! str_contains($markup, '"tagName":"form"')) {
    throw new RuntimeException('Captioned form must lower as one readable provider boundary');
}
if (1 !== substr_count($markup, 'News by post')) {
    throw new RuntimeException('Keep the caption exactly once');
}
echo "Captioned provider boundary passed\n";

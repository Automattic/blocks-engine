<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A retained host is not flattened: passive motion-layer metadata on a
// descendant must not force its editable text into a raw HTML companion.
$html = '<!doctype html><html><head><style>interact-element{display:contents}'
    . '[data-interact-key="card"] .card{background:blue}</style></head><body>'
    . '<main><interact-element data-interact-key="card"><div class="card">'
    . '<div class="background mask" data-motion-part="BG_LAYER background"></div><div><h3>Benefit</h3>'
    . '<p>Editable description.</p></div></div></interact-element></main></body></html>';
$artifact = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(array('path' => 'website/index.html', 'kind' => 'html', 'content' => $html)),
))->toArray();
$markup = (string) ($artifact['serialized_blocks'] ?? '');
preg_match_all('/<!-- wp:([^\s>]+)/', $markup, $matches);
foreach ($matches[1] as $name) {
    if (preg_match('~/(?:block|card)(?:-[a-f0-9]+)?$~', $name) || in_array($name, array('html', 'core/html'), true)) {
        fwrite(STDERR, "FAIL: motion structure forced a non-native block: {$name}\n");
        exit(1);
    }
}
if (!str_contains($markup, '<!-- wp:heading ') || !str_contains($markup, '<!-- wp:paragraph ')) {
    fwrite(STDERR, "FAIL: the host's heading and paragraph must remain native\n");
    exit(1);
}
echo "Custom wrapper motion structure contract passed\n";

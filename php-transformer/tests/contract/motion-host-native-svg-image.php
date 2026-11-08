<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Page selectors must still reach the vector, but editing uses Core's image
// replacement surface rather than a companion's SVG source textarea.
$html = '<!doctype html><html><head><style>interact-element{display:contents}'
    . '.card{display:grid}svg{display:block;width:40px;height:40px}[data-interact-key="card"] [data-color="1"]{fill:red}</style></head><body>'
    . '<main><interact-element data-interact-key="card"><div class="card"><div data-motion-part="BG_LAYER card"></div>'
    . '<svg viewBox="0 0 20 20"><path data-color="1" d="M0 0h20v20H0z"/></svg>'
    . '<h2>Editable title</h2></div></interact-element></main></body></html>';
$artifact = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(array('path' => 'website/index.html', 'kind' => 'html', 'content' => $html)),
))->toArray();
$markup = (string) ($artifact['serialized_blocks'] ?? '');
if (!str_contains($markup, '<!-- wp:image ') || !str_contains($markup, 'blocksEngineInlineSvg')
    || !str_contains($markup, 'data-color') || str_contains($markup, '/svg-artwork')
    || str_contains($markup, '<!-- wp:html')) {
    fwrite(STDERR, "FAIL: motion-host SVG must use a native image with document-context paint\n");
    exit(1);
}
echo "Motion host native SVG image contract passed\n";

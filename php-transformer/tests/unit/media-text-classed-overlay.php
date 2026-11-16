<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// An unremarkable classed wrapper still participates in pattern layout gates.
// The badge is not a second pane, even when its position comes from a stylesheet.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>.row{display:flex;align-items:center}.frame{width:100%;aspect-ratio:3/4;overflow:hidden}.photo{width:100%;height:100%;object-fit:cover}.absolute{position:absolute}.hidden{display:none}@media(min-width:640px){.shown{display:block}}.note{bottom:-24px;left:-24px;padding:24px}</style><main><div class="row"><div class="frame"><img class="photo" src="photo.jpg" alt="Portrait"></div><div class="absolute hidden shown note"><p>A thoughtful quotation.</p><p>The author</p></div></div></main>'),
))->toArray();
if (str_contains($result['serialized_blocks'], '<!-- wp:media-text')) {
    throw new RuntimeException('An out-of-flow badge must not become a media-text pane');
}
if (!str_contains($result['serialized_blocks'], '<!-- wp:image') || str_contains($result['serialized_blocks'], '<!-- wp:html')) {
    throw new RuntimeException('Keep the image editable in its source layout');
}
echo "Classed media overlay passed\n";

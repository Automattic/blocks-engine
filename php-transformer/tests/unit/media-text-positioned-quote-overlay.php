<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The quote container is not a high-value styled element. Recognition must
// still read its cascade, including responsive visibility, before making panes.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => <<<'HTML'
<style>
.flex{display:flex}.items-center{align-items:center}.relative{position:relative}
.frame{aspect-ratio:3/4;width:100%;overflow:hidden}
.photo{height:100%;width:100%;object-fit:cover}
.absolute{position:absolute}.hidden{display:none}
@media(min-width:640px){.visible{display:block}}
</style>
<main><div class="relative flex items-center">
<div class="relative frame"><img src="portrait.jpg" alt="Portrait" class="photo"></div>
<div class="absolute hidden visible"><p>A considered portrait.</p><p>— The artist</p></div>
</div></main>
HTML
    ),
))->toArray();

$markup = $result['serialized_blocks'];
if (str_contains($markup, '<!-- wp:media-text ') || !str_contains($markup, '<!-- wp:image ')) {
    throw new RuntimeException('An out-of-flow quote is an overlay, not a second Media & Text pane.');
}
if (!str_contains($markup, 'relative frame') || !str_contains($markup, 'absolute hidden visible')) {
    throw new RuntimeException('The image frame and positioned quote must retain their source boxes.');
}
if (str_contains($markup, '<!-- wp:html')) {
    throw new RuntimeException('The image and quote must remain editable native blocks.');
}
echo "Media text positioned quote overlay passed\n";

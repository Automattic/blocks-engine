<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<style>.row{display:flex}.overlay{position:absolute}.full{width:100%}</style><main><div class="row"><img src="photo.jpg" alt="Portrait"><div class="overlay"><p>Photo credit</p></div></div><blockquote><p>Quotation</p><footer class="row"><span>—</span><div><p>Author</p><p>Role</p></div></footer></blockquote><form><label>News by post</label><div class="row"><input class="full" type="email" name="email"><button type="submit">Subscribe</button></div></form></main>',
)))->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '<!-- wp:media-text')) {
    throw new RuntimeException('An absolute credit must not become a second media pane.');
}
if (str_contains($markup, '<cite') || str_contains($markup, '<footer')) {
    throw new RuntimeException('Structured quote attribution must retain its blocks without creating a site footer.');
}
$form = current(array_filter($result['fallbacks'], static fn(array $f): bool => 'html_form_fallback' === ($f['diagnostic_code'] ?? null)));
if (!$form || !str_contains($form['binding']['search_block_markup'] ?? '', 'News by post')) {
    throw new RuntimeException('Caption must share the provider replacement boundary.');
}
$submit = $form['presentation_graph']['controls'][1]['control']['styles'] ?? array();
if ('0 1 auto' !== ($submit['flex'] ?? null) || 'auto' !== ($submit['width'] ?? null)) {
    throw new RuntimeException('Provider submit must retain native intrinsic flex sizing.');
}
echo "Imported form and overlay geometry passed\n";

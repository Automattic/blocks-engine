<?php
declare(strict_types=1);

// Compiles a page holding a hidden runtime element, a closed dialog and a
// linked image, and prints the editor script its theme bootstrap enqueues
// together with the saved block attributes the editor hands to it.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="/site.css"></head><body><header>'
    . '<a class="brand" href="/"><img src="/logo.png" alt="Logo" width="150" height="150"></a></header><main><h1>Home</h1>'
    . '<ul class="contact"><li><a href="mailto:a@example.com">a@example.com</a></li><li hidden><a id="phone" href="#">Phone soon</a></li></ul>'
    . '<dialog class="cart" aria-labelledby="cart-title"><h2 id="cart-title">Cart</h2><p>Empty.</p></dialog></main></body></html>';
$css = '.brand{display:flex}.brand img{height:58px;width:auto;border-radius:50%}.contact li{display:flex}.cart[open]{display:flex}@media (max-width:600px){.brand img{height:50px}}';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'site.css' => $css, 'logo.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))))->toArray()['source_reports']['wordpress_site_plan'];

$bootstrap = (string) (array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'] ?? '');
$script = '';
if (preg_match_all("/add_action\\( 'enqueue_block_editor_assets', static function \\(\\): void \\{ wp_add_inline_script\\( 'wp-block-editor', ('(?:[^'\\\\]|\\\\.)*'), 'after' \\); \\} \\);/s", $bootstrap, $found)) {
    foreach ($found[1] as $literal) if (str_contains($literal, 'editor.BlockListBlock')) $script = (string) eval('return ' . $literal . ';');
}
$markup = implode("\n", array_column($plan['pages'], 'canonical_block_markup')) . implode("\n", array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
// The editor sources core/image link attributes from the saved anchor.
$blocks = array();
preg_match_all('/<!--\s*wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)\s+(\{.*?\})\s*\/?-->(?:(<figure\b.*?)<!-- \/wp:image -->)?/s', $markup, $matches, PREG_SET_ORDER);
foreach ($matches as $match) {
    $attributes = json_decode($match[2], true);
    if ('image' === $match[1] && preg_match('/<a\b([^>]*)>/', $match[3] ?? '', $anchor)) {
        foreach (array('href' => 'href', 'class' => 'linkClass') as $name => $key) {
            if (preg_match('/\s' . $name . '="([^"]*)"/', $anchor[1], $value)) $attributes[$key] = html_entity_decode($value[1]);
        }
    }
    $blocks[] = array('name' => str_contains($match[1], '/') ? $match[1] : 'core/' . $match[1], 'attributes' => $attributes);
}
echo json_encode(array('script' => $script, 'blocks' => $blocks));

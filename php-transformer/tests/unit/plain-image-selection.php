<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A plain <img src> decodes that one file at every width. The lowered image
// records it as a src-only selection, and the theme runtime keeps WordPress
// from offering the attachment's generated renditions (core sizes, or sizes a
// plugin such as WooCommerce regenerates later) as srcset candidates, which a
// narrow viewport would decode as different bytes.
$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
};

$result = (new HtmlTransformer())->transform('<img src="portrait.jpg" alt="Portrait">')->toArray();
$block = $result['blocks'][0] ?? array();
$selection = $block['attrs']['metadata']['blocksEngineImageSelection'] ?? null;
if (array( 'src' => 'portrait.jpg' ) !== $selection) {
    $fail('a plain image must record its single authored src as a src-only selection: ' . json_encode($selection));
}
if (! str_contains((string) $result['serialized_blocks'], '"blocksEngineImageSelection":{"src":"portrait.jpg"}')) {
    $fail('the src-only selection must survive block serialization');
}

// An inline data image has no file to bind; it records no selection.
$inline = (new HtmlTransformer())->transform('<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="">')->toArray();
if (isset($inline['blocks'][0]['attrs']['metadata']['blocksEngineImageSelection'])) {
    $fail('a data URI image must not record a selection');
}

// Render through the theme runtime with minimal WordPress stand-ins.
class WP_HTML_Tag_Processor
{
    private ?DOMElement $tag = null;
    private DOMDocument $dom;
    public function __construct(string $html) { $this->dom = new DOMDocument(); @$this->dom->loadHTML('<body>' . $html . '</body>'); }
    public function next_tag(string $name): bool { $this->tag = $this->dom->getElementsByTagName(strtolower($name))->item(0); return null !== $this->tag; }
    public function get_attribute(string $name): ?string { return $this->tag?->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
    public function set_attribute(string $name, string $value): void { $this->tag?->setAttribute($name, $value); }
    public function remove_attribute(string $name): void { $this->tag?->removeAttribute($name); }
    public function get_updated_html(): string { return (string) $this->dom->saveHTML($this->dom->getElementsByTagName('body')->item(0)); }
}
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function add_filter(string $name, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['plainImageFilters'][$name] = $callback; }
eval(ThemeBootstrap::IMAGE_SELECTION_RENDERER);
$render = $GLOBALS['plainImageFilters']['render_block_core/image'] ?? null;
$srcset = $GLOBALS['plainImageFilters']['wp_img_tag_add_srcset_and_sizes_attr'] ?? null;
if (! is_callable($render) || ! is_callable($srcset)) {
    $fail('the image selection runtime must filter both the image render and core srcset addition');
}

// The importer binds the selection to the attachment URL it renders.
$url = 'https://example.test/wp-content/uploads/portrait.jpg';
$block['attrs']['metadata']['blocksEngineImageSelection']['src'] = $url;
$markup = '<figure class="wp-block-image"><img src="' . $url . '" alt="Portrait" class="wp-image-7"/></figure>';
if ($markup !== $render($markup, $block)) {
    $fail('a src-only selection must leave the rendered image markup unchanged');
}
if (false !== $srcset(true, '<img src="' . $url . '" alt="Portrait" class="wp-image-7"/>', 'the_content', 7)) {
    $fail('core must not add attachment renditions as srcset to a src-only image');
}
if (true !== $srcset(true, '<img src="https://example.test/wp-content/uploads/other.jpg" class="wp-image-8"/>', 'the_content', 8)) {
    $fail('images without a src-only selection keep core srcset');
}
echo "Plain image selection passed\n";

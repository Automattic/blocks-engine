<?php
declare(strict_types=1);

// A photo thumbnail button is a Core group in the editor and a native
// <button> on the front end. The editor's div inherits the page's font and
// colour and paints the box its block supports carry; the published button
// must paint the same, not fall back to the UA button font, colour, padding
// and border (issue #5647: thumbnails rendered black Arial on the front end).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$result = (new HtmlTransformer())->transform(
    '<div class="thumbs"><button aria-label="Product photo" class="border border-primary"><img src="https://example.com/photo.jpg" alt="" width="256" height="256"></button></div>'
)->toArray();
$box = null;
$find = static function (array $blocks) use (&$find, &$box): void {
    foreach ($blocks as $block) {
        if (isset($block['attrs']['metadata']['blocksEngineControlBox'])) { $box = $block; return; }
        $find($block['innerBlocks'] ?? array());
    }
};
$find($result['blocks']);
$assert(is_array($box) && 'button' === ($box['attrs']['metadata']['blocksEngineControlTagName'] ?? null), 'The photo button is a Core group published as a native button.');

// Core renders the group's projected block supports (colour, type, box) as
// preset classes and an inline style, as an import of a captured site does.
$style = 'border-width:1px;border-top-style:solid;padding-top:0px;font-weight:400;line-height:24px';
$rendered = '<div class="wp-block-group has-text-color border is-layout-flow wp-block-group-is-layout-flow has-contrast-color has-large-font-size" style="' . $style . '"><figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt=""/></figure></div>';

class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $root = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<html><body>' . $html . '</body></html>');
    }
    public function next_tag(): bool {
        $this->root = $this->dom->getElementsByTagName('body')->item(0)->firstElementChild;
        return null !== $this->root;
    }
    public function get_attribute(string $name): ?string { return $this->root->hasAttribute($name) ? $this->root->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->root->setAttribute($name, true === $value ? '' : $value); }
    public function remove_attribute(string $name): void { $this->root->removeAttribute($name); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->root); }
}
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['photoButtonRenderFilter'] = $callback; }
function add_action(string $hook, callable $callback): void { $callback(); }
function wp_register_style(string $handle, bool $src): void {}
function wp_enqueue_style(string $handle): void {}
function wp_add_inline_style(string $handle, string $css): void { $GLOBALS['photoButtonInlineCss'][$handle] = $css; }
eval(RuntimeAttributesRuntime::source());
eval(RuntimeAttributesRuntime::nativeControlStyleSource());

$dom = new DOMDocument();
@$dom->loadHTML($GLOBALS['photoButtonRenderFilter']($rendered, $box));
$button = $dom->getElementsByTagName('button')->item(0);
$assert($button instanceof DOMElement && 'Product photo' === $button->getAttribute('aria-label'), 'The published control is a native button with its accessible name.');
$classes = preg_split('/\s+/', $button->getAttribute('class'));
foreach (array('border', 'border-primary', 'has-text-color', 'has-contrast-color', 'has-large-font-size') as $class) {
    $assert(in_array($class, $classes, true), "The published button keeps {$class}: " . $button->getAttribute('class'));
}
foreach (array('wp-block-group', 'is-layout-flow', 'wp-block-group-is-layout-flow') as $class) {
    $assert(!in_array($class, $classes, true), "The published button sheds Core group chrome {$class}.");
}
$assert($button->hasAttribute('data-blocks-engine-native-control'), 'The published button is marked as a native control by an attribute choice states do not rewrite.');
$assert($style === $button->getAttribute('style'), 'The published button keeps the box and type its block supports paint in the editor: ' . $button->getAttribute('style'));

$css = $GLOBALS['photoButtonInlineCss']['blocks-engine-native-control'] ?? '';
$selector = ':where(button[data-blocks-engine-native-control])';
$assert(str_starts_with($css, $selector . '{') && str_contains($css, 'font:inherit') && str_contains($css, 'color:inherit'), 'The published button inherits font and colour as the editor div does, at zero specificity: ' . $css);

echo "Photo button published presentation: native button paints as the editor group.\n";

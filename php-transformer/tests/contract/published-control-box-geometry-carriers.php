<?php
declare(strict_types=1);

// A control box (a dialog trigger's button and its icon wrappers) and an
// inline SVG image publish their source element instead of Core's group or
// figure. The box-tree geometry carrier the block saved sizes that element:
// without it a 200x200 icon SVG in a 30px hamburger widens the page.

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\InlineSvgImageRuntime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $tag = null;
    private int $cursor = 0;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
    }
    public function next_tag(array|string $query = array()): bool {
        $name = is_string($query) ? $query : ($query['tag_name'] ?? null);
        $elements = iterator_to_array($this->dom->getElementsByTagName('body')->item(0)->getElementsByTagName('*'));
        for (; $this->cursor < count($elements); $this->cursor++) {
            $element = $elements[$this->cursor];
            if (null === $name || 0 === strcasecmp($name, $element->tagName)) {
                $this->tag = $element;
                $this->cursor++;
                return true;
            }
        }
        return false;
    }
    public function get_attribute(string $name): ?string { return $this->tag->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->tag->setAttribute($name, true === $value ? '' : $value); }
    public function remove_attribute(string $name): void { $this->tag->removeAttribute($name); }
    public function add_class(string $name): void { $this->tag->setAttribute('class', trim($this->tag->getAttribute('class') . ' ' . $name)); }
    public function get_updated_html(): string {
        $html = '';
        foreach ($this->dom->getElementsByTagName('body')->item(0)->childNodes as $node) $html .= $this->dom->saveHTML($node);
        return $html;
    }
}
function wp_kses(string $html, array $allowed): string { return $html; }
$filters = array();
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['filters'][$hook] = $callback; }
eval(RuntimeAttributesRuntime::source());
eval(InlineSvgImageRuntime::source());

$classes = static function (string $html): array {
    $processor = new WP_HTML_Tag_Processor($html);
    $processor->next_tag();
    return preg_split('/\s+/', (string) $processor->get_attribute('class'), -1, PREG_SPLIT_NO_EMPTY);
};

// The icon wrapper: a control box whose own source class has no rule left
// that sizes it; its carrier holds the captured 30px box.
$icon = $GLOBALS['filters']['render_block'](
    '<div class="wp-block-group _animatedIcon icon be-inline-geometry-06715aaf74a91f19 blocks-engine-editor-anchor-animated-icon is-layout-flow wp-block-group-is-layout-flow" style="margin-top:0px"><div class="wp-block-group be-inline-geometry-e794cab5c9d3b06f is-layout-flow"></div></div>',
    array('blockName' => 'core/group', 'attrs' => array('metadata' => array('blocksEngineControlBox' => array('class' => '_animatedIcon icon', 'id' => 'animated-icon'))))
);
$published = $classes($icon);
$assert(in_array('be-inline-geometry-06715aaf74a91f19', $published, true), 'The published control box keeps its geometry carrier: ' . $icon);
$assert(in_array('_animatedIcon', $published, true) && in_array('blocks-engine-editor-anchor-animated-icon', $published, true), 'The published control box keeps its source classes and engine markers: ' . $icon);
$assert(!in_array('wp-block-group', $published, true) && !in_array('is-layout-flow', $published, true), 'The published control box sheds Core group chrome: ' . $icon);

// The icon itself: an inline SVG image publishes the source <svg> root, which
// takes the figure's carrier so its captured size replaces width="200".
$svg = '<svg viewBox="0 0 200 200" height="200" width="200" xmlns="http://www.w3.org/2000/svg"><path d="M180 161H20z"></path></svg>';
$src = 'data:image/svg+xml;base64,' . base64_encode($svg);
$image = $GLOBALS['filters']['render_block_core/image'](
    '<figure class="wp-block-image blocks-engine-synthetic-image-figure be-inline-geometry-c5941cdd74dfc038 blocks-engine-image"><img src="' . $src . '" alt=""/></figure>',
    array('blockName' => 'core/image', 'attrs' => array('metadata' => array('blocksEngineInlineSvg' => array('svg' => $svg, 'src' => $src))))
);
$assert(str_starts_with($image, '<svg'), 'The inline SVG image publishes its source root: ' . $image);
$assert(array('be-inline-geometry-c5941cdd74dfc038') === $classes($image), 'The published SVG root takes only the figure geometry carrier: ' . $image);

echo "Published control box geometry carriers passed.\n";

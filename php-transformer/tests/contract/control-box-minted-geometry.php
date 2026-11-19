<?php
declare(strict_types=1);

// A captured menu opener is published from its control-box metadata and its
// icon from the original inline SVG. Projection moves the source rules that
// size them (`.icon{width:var(--size)}`, `.icon svg{width:var(--size)}`) onto
// minted `be-inline-geometry-*` classes on the saved group and image figure.
// Rendering must keep those classes on the published box and the restored
// SVG root; otherwise the 200x200 viewBox sizes the icon and the header
// grows by the difference, displacing every text run below it.

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
    /** @var list<DOMElement> */
    private array $elements;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<html><body>' . $html . '</body></html>');
        $this->elements = iterator_to_array($this->dom->getElementsByTagName('body')->item(0)->getElementsByTagName('*'), false);
    }
    public function next_tag(array|string $query = array()): bool {
        $name = is_string($query) ? strtolower($query) : null;
        while (array() !== $this->elements) {
            $element = array_shift($this->elements);
            if (null === $name || $name === strtolower($element->tagName)) return null !== ($this->tag = $element);
        }
        return false;
    }
    public function has_class(string $name): bool { return in_array($name, preg_split('/\s+/', $this->tag->getAttribute('class')), true); }
    public function add_class(string $name): void { if (!$this->has_class($name)) $this->tag->setAttribute('class', trim($this->tag->getAttribute('class') . ' ' . $name)); }
    public function get_attribute(string $name): ?string { return $this->tag->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->tag->setAttribute($name, true === $value ? '' : $value); }
    public function remove_attribute(string $name): void { $this->tag->removeAttribute($name); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->dom->getElementsByTagName('body')->item(0)->firstElementChild); }
}
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function wp_kses(string $html, array $allowed): string { return $html; }
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['mintedGeometryFilters'][$hook] = $callback; }
eval(RuntimeAttributesRuntime::source());
eval(InlineSvgImageRuntime::source());
$render = static fn (string $hook, string $content, array $block): string => $GLOBALS['mintedGeometryFilters'][$hook]($content, $block);

$box = $render('render_block', '<div class="wp-block-group _animatedIcon animated-icon be-inline-geometry-06715aaf74a91f19 blocks-engine-editor-anchor-icon is-layout-flow" style="margin-top:0px"></div>',
    array('blockName' => 'core/group', 'attrs' => array('metadata' => array('blocksEngineControlBox' => array('class' => '_animatedIcon animated-icon', 'id' => 'animated-icon')))));
$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $box . '</body></html>');
$classes = preg_split('/\s+/', (string) $document->getElementById('animated-icon')?->getAttribute('class'));
$assert(in_array('be-inline-geometry-06715aaf74a91f19', $classes, true), 'The published control box keeps the minted geometry class that sizes it: ' . $box);
$assert(in_array('blocks-engine-editor-anchor-icon', $classes, true) && in_array('_animatedIcon', $classes, true), 'Engine markers and the authored classes stay on the box.');
$assert(!in_array('wp-block-group', $classes, true) && !in_array('is-layout-flow', $classes, true), 'Group layout chrome is still shed from the published box.');

$svg = '<svg viewBox="0 0 200 200" width="200" height="200" class="glyph"><path d="M180 161H20v-17h160z"></path></svg>';
$src = 'data:image/svg+xml;base64,' . base64_encode($svg);
$icon = $render('render_block_core/image', '<figure class="wp-block-image blocks-engine-synthetic-image-figure be-inline-geometry-c5941cdd74dfc038 blocks-engine-image"><img src="' . $src . '" alt=""/></figure>',
    array('blockName' => 'core/image', 'attrs' => array('metadata' => array('blocksEngineInlineSvg' => array('svg' => $svg, 'src' => $src)))));
$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $icon . '</body></html>');
$root = $document->getElementsByTagName('svg')->item(0);
$assert(null !== $root && 0 === $document->getElementsByTagName('figure')->length, 'The original inline SVG root replaces Core\'s figure: ' . $icon);
$classes = preg_split('/\s+/', (string) $root->getAttribute('class'));
$assert(in_array('be-inline-geometry-c5941cdd74dfc038', $classes, true), 'The restored SVG root carries the figure\'s minted geometry class: ' . $icon);
$assert(in_array('glyph', $classes, true), 'The SVG keeps its own authored classes.');
$assert(!in_array('wp-block-image', $classes, true) && !in_array('blocks-engine-synthetic-image-figure', $classes, true), 'Figure-only classes do not move onto the SVG.');

echo "Control box minted geometry passed.\n";

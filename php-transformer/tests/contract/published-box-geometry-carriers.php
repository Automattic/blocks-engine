<?php
/**
 * Two theme render filters publish a different element than the block saved:
 * a CSS-drawn control box sheds Core's group chrome, and an inline SVG image
 * renders its source `<svg>` root instead of Core's figure. On a box-tree
 * route the author stylesheet is retired, so the geometry carriers the block
 * saved are the published element's only styles. Both filters keep them.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\InlineSvgImageRuntime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ($condition) {
        echo "PASS: $message\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: $message\n" . ('' !== $detail ? $detail . "\n" : ''));
};

// A minimal stand-in for WordPress's HTML API: the first matching tag only.
class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $tag = null;
    public function __construct(string $html)
    {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
    }
    public function next_tag(?string $name = null): bool
    {
        $body = $this->dom->getElementsByTagName('body')->item(0);
        $this->tag = null === $name ? $body->firstElementChild : $body->getElementsByTagName(strtolower($name))->item(0);
        return null !== $this->tag;
    }
    public function get_attribute(string $name): ?string { return $this->tag->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->tag->setAttribute($name, true === $value ? '' : $value); }
    public function remove_attribute(string $name): void { $this->tag->removeAttribute($name); }
    public function has_class(string $class): bool { return in_array($class, preg_split('/\s+/', $this->tag->getAttribute('class')), true); }
    public function add_class(string $class): void { $this->tag->setAttribute('class', trim($this->tag->getAttribute('class') . ' ' . $class)); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->dom->getElementsByTagName('body')->item(0)->firstElementChild); }
}
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['publishedCarrierFilters'][$hook] = $callback; }
function wp_kses(string $html, array $allowed): string { return $html; }
eval(RuntimeAttributesRuntime::source());
eval(InlineSvgImageRuntime::source());

$classes = static function (string $html, string $tag): array {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $element = $dom->getElementsByTagName($tag)->item(0);
    return $element instanceof DOMElement ? preg_split('/\s+/', trim($element->getAttribute('class'))) : array();
};

// A CSS-drawn menu button: its group carries the source classes in metadata
// and its geometry carrier in className.
$box = array(
    'blockName' => 'core/group',
    'attrs' => array(
        'tagName' => 'div',
        'className' => 'menu-button blocks-engine-source-div-0123456789ab-0 be-inline-geometry-0123456789abcdef',
        'metadata' => array('blocksEngineControlBox' => array('type' => 'button', 'class' => 'menu-button', 'aria-label' => 'Menu')),
    ),
);
$rendered = $GLOBALS['publishedCarrierFilters']['render_block']('<div class="wp-block-group menu-button blocks-engine-source-div-0123456789ab-0 be-inline-geometry-0123456789abcdef is-layout-flow" style="padding:0"><span>icon</span></div>', $box);
$published = $classes($rendered, 'div');
$assert(in_array('be-inline-geometry-0123456789abcdef', $published, true), 'the published control box keeps its geometry carrier', $rendered);
$assert(in_array('menu-button', $published, true) && in_array('blocks-engine-source-div-0123456789ab-0', $published, true), 'the published control box keeps its source classes and engine markers', $rendered);
$assert(!in_array('wp-block-group', $published, true) && !in_array('is-layout-flow', $published, true) && !str_contains($rendered, 'style='), 'the published control box still sheds Core group chrome', $rendered);

// An inline SVG image: the figure saved the carrier; the source root renders.
$svg = '<svg viewBox="0 0 20 20" width="200" height="200"><path d="M0 0h20v2H0z"></path></svg>';
$src = 'data:image/svg+xml;base64,' . base64_encode($svg);
$image = array('blockName' => 'core/image', 'attrs' => array('metadata' => array('blocksEngineInlineSvg' => array('svg' => $svg, 'src' => $src))));
$rendered = $GLOBALS['publishedCarrierFilters']['render_block_core/image']('<figure class="wp-block-image blocks-engine-synthetic-image-figure be-inline-geometry-fedcba9876543210 blocks-engine-image"><img src="' . $src . '" alt=""/></figure>', $image);
$root = $classes($rendered, 'svg');
$assert(str_starts_with($rendered, '<svg'), 'the inline SVG still renders its source root, not the figure', $rendered);
$assert(array('be-inline-geometry-fedcba9876543210') === $root, 'the SVG root takes the figure geometry carrier, and only it', $rendered);

$plain = $GLOBALS['publishedCarrierFilters']['render_block_core/image']('<figure class="wp-block-image"><img src="' . $src . '" alt=""/></figure>', $image);
$assert(!str_contains($plain, 'class='), 'an SVG whose figure has no carrier renders unchanged', $plain);

exit($failures > 0 ? 1 : 0);

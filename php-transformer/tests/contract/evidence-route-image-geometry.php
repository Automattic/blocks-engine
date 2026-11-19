<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\InlineSvgImageRuntime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

/*
 * Issue #5859: on a route with box-tree evidence, images rendered twice and
 * below their source position.
 *
 * A responsive capture folds its desktop and phone frames into one document
 * and shows one island per viewport through its device gate sheet. Box-tree
 * carriers record one viewport's resting pose, so retiring the gate showed
 * both islands at every width.
 *
 * A header control box (a hamburger button kept as a group) sheds Core's
 * group chrome when published, and its icon (a native image of an inline SVG)
 * is published as the bare SVG. Their geometry carrier classes went with the
 * shed wrappers, so the icon fell back to its intrinsic SVG size and the
 * taller header pushed every image down.
 */
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' !== $detail ? ': ' . $detail : ''));
    echo "PASS: $message\n";
};
$desktop = CaptureEvidence::DESKTOP_ONLY_CLASS;
$mobile = CaptureEvidence::MOBILE_ONLY_CLASS;
$page = array(
    'source_path' => 'about/index.html',
    'document_metadata' => array('engine_owned_styles' => true),
    'canonical_block_markup' => '<!-- wp:image {"className":"' . $desktop . '"} --><figure class="wp-block-image ' . $desktop . '"><img src="/a.avif" alt=""/></figure><!-- /wp:image -->'
        . '<!-- wp:image {"className":"' . $mobile . '"} --><figure class="wp-block-image ' . $mobile . '"><img src="/a-mobile.avif" alt=""/></figure><!-- /wp:image -->',
);
// The gate as projected for a site: route-scoped arms and arms carried onto
// converted controls share the gate rule, and the desktop half reaches a
// second (shared chrome) sheet too.
$gate = '@media(min-width:1024px){:where(:root:not(.be-r-1)) .' . $mobile . ',:where(:root:is(.be-r-1)) .' . $mobile . ':not(:where(.blocks-engine-role-control)),:where(.blocks-engine-control-abc-0)>:where(.wp-block-button__link){display:none!important}}'
    . '@media(max-width:1023px){:where(:root:is(.be-r-2)) .' . $desktop . ':not(:where(.blocks-engine-role-control)){display:none!important}}'
    . '.' . $desktop . ' .caption,.' . $mobile . '{color:blue}';
$chrome = '@media(max-width:1023px){.' . $desktop . ':where(:root:not(.be-rs-1),:root:not(.be-rs-1) *){display:none!important}}';
$sheet = static fn(string $css): array => array('kind' => 'css', 'source' => 'author', 'content' => $css, 'scopes' => array(array('kind' => 'global')));
[$kept, $keptChrome] = array_column(EvidenceRouteStylesheets::retire(array($sheet($gate), $sheet($chrome)), array($page)), 'content');

$assert('@media(min-width:1024px){.' . $mobile . '{display:none!important}}@media(max-width:1023px){.' . $desktop . '{display:none!important}}' === $kept, 'the gate keeps hiding each island outside its viewport, as the bare island class', $kept);
$assert('' === $keptChrome, 'a gate condition already kept is not delivered twice', $keptChrome);

class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $tag = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<html><body>' . $html . '</body></html>');
    }
    public function next_tag(string $name = ''): bool {
        foreach ($this->dom->getElementsByTagName('body')->item(0)->getElementsByTagName('' === $name ? '*' : strtolower($name)) as $element) return null !== ($this->tag = $element);
        return false;
    }
    public function add_class(string $name): void { $this->tag->setAttribute('class', trim($this->tag->getAttribute('class') . ' ' . $name)); }
    public function get_attribute(string $name): ?string { return $this->tag->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
    public function set_attribute(string $name, string|bool $value): void { $this->tag->setAttribute($name, true === $value ? '' : $value); }
    public function remove_attribute(string $name): void { $this->tag->removeAttribute($name); }
    public function get_updated_html(): string { return $this->dom->saveHTML($this->tag); }
}
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['filters'][$hook] = $callback; }
function wp_kses(string $html, array $allowed): string { return $html; }
eval(RuntimeAttributesRuntime::source());
eval(InlineSvgImageRuntime::source());

$box = array('class' => 'hamburger-open-button button', 'aria-label' => 'Menu');
$rendered = $GLOBALS['filters']['render_block'](
    '<div class="wp-block-group hamburger-open-button button be-inline-geometry-0123456789abcdef blocks-engine-subject is-layout-flow" style="padding:0px"><svg width="200" height="200"></svg></div>',
    array('blockName' => 'core/group', 'attrs' => array('metadata' => array('blocksEngineControlBox' => $box)))
);
$classes = preg_match('/^<div class="([^"]*)"/', $rendered, $match) ? explode(' ', $match[1]) : array();
$assert(in_array('be-inline-geometry-0123456789abcdef', $classes, true), 'a published control box keeps the geometry carrier that sizes it', $rendered);
$assert(!in_array('wp-block-group', $classes, true) && !in_array('is-layout-flow', $classes, true) && !str_contains($rendered, 'style='), 'it still sheds Core group chrome', $rendered);

$svg = '<svg viewBox="0 0 200 200" width="200" height="200"><path d="M0 0h200v20H0z"></path></svg>';
$src = 'data:image/svg+xml;base64,' . base64_encode($svg);
$rendered = $GLOBALS['filters']['render_block_core/image'](
    '<figure class="wp-block-image blocks-engine-synthetic-image-figure be-inline-geometry-fedcba9876543210 blocks-engine-image"><img src="' . $src . '" alt=""/></figure>',
    array('blockName' => 'core/image', 'attrs' => array('metadata' => array('blocksEngineInlineSvg' => array('svg' => $svg, 'src' => $src))))
);
$assert(1 === preg_match('/^<svg\b[^>]*\sclass="be-inline-geometry-fedcba9876543210"[^>]*>/', $rendered), 'a published inline SVG keeps the geometry carrier of the image it replaces', $rendered);

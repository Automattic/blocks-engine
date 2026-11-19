<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationAnchorRuntime;

// Issue #6744: a navigation link pairing one passive leading inline SVG with
// visible text keeps the icon before its visible label; an icon-only link keeps
// the icon and its hidden accessible-name label.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$svg = static fn (string $icon): string => '<svg class="fa-' . $icon . '" aria-hidden="true" viewBox="0 0 16 16"><path fill="currentColor" d="M0 0h16v16H0z"/></svg>';
$html = '<!doctype html><html><head><title>Home</title><style>.top-nav{display:flex;list-style:none}.nav-link{padding:4px 8px;color:#2a493b}.nav-link svg{width:1em;margin-right:4px}</style></head><body>'
    . '<header><nav><ul class="top-nav">'
    . '<li class="nav-item"><a class="nav-link" href="/contact/">' . $svg('phone') . ' Contact</a></li>'
    . '<li class="nav-item"><a class="nav-link" href="https://linkedin.com/x">' . $svg('linkedin') . '</a></li>'
    . '</ul></nav></header><main><h1>Home</h1></main></body></html>';

$markup = (string) ((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'] ?? '');
$assert(2 === preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $markup, $links), 'Both items stay native core/navigation-link blocks: ' . $markup);
$blocks = array();
foreach ($links[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $blocks[$attrs['url']] = array('blockName' => 'core/navigation-link', 'attrs' => $attrs);
}
$contact = $blocks['/contact/']['attrs'] ?? array();
$linkedin = $blocks['https://linkedin.com/x']['attrs'] ?? array();
$assert('Contact' === ($contact['label'] ?? null) && str_contains((string) ($contact['metadata']['blocksEngineNavigationAnchor']['svg'] ?? ''), 'fa-phone') && true === ($contact['metadata']['blocksEngineNavigationAnchor']['labelVisible'] ?? null), 'The icon + label item carries its artwork and a visible label: ' . $json);
$assert(str_contains((string) ($linkedin['metadata']['blocksEngineNavigationAnchor']['svg'] ?? ''), 'fa-linkedin') && !isset($linkedin['metadata']['blocksEngineNavigationAnchor']['labelVisible']), 'The icon-only item keeps its artwork with a hidden label.');

$validity = (new HtmlTransformer())->transform($html)->toArray()['source_reports']['wp_block_validity']['status'] ?? '';
$assert('pass' === $validity, 'The navigation stays editor-valid, got ' . $validity);

// Minimal stand-ins for the WordPress APIs the emitted runtime calls.
class WP_HTML_Tag_Processor
{
    private DOMDocument $document;
    private array $nodes;
    private int $position = -1;
    public function __construct(string $html)
    {
        $this->document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $this->document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->nodes = iterator_to_array((new DOMXPath($this->document))->query('//body//*'));
    }
    public function next_tag(array $query = array()): bool
    {
        while (isset($this->nodes[++$this->position])) {
            $node = $this->nodes[$this->position];
            if (isset($query['tag_name']) && strtolower($query['tag_name']) !== strtolower($node->tagName)) continue;
            if (isset($query['class_name']) && !in_array($query['class_name'], preg_split('/\s+/', $node->getAttribute('class')) ?: array(), true)) continue;
            return true;
        }
        return false;
    }
    public function get_attribute(string $name): ?string { $node = $this->nodes[$this->position]; return $node->hasAttribute($name) ? $node->getAttribute($name) : null; }
    public function set_attribute(string $name, string $value): void { $this->nodes[$this->position]->setAttribute($name, $value); }
    public function add_class(string $class): void { $node = $this->nodes[$this->position]; $node->setAttribute('class', trim($node->getAttribute('class') . ' ' . $class)); }
    public function get_updated_html(): string
    {
        $html = '';
        foreach ($this->document->getElementsByTagName('body')->item(0)->childNodes as $node) $html .= $this->document->saveHTML($node);
        return $html;
    }
}
function add_filter(string $name, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['filters'][$name][] = $callback; }
function wp_kses(string $html, array $allowed): string { return $html; }
function safecss_filter_attr(string $css): string { return $css; }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }

eval(NavigationAnchorRuntime::source());
$render = static function (array $block): string {
    $content = '<li class="wp-block-navigation-item ' . $block['attrs']['className'] . '"><a class="wp-block-navigation-item__content" href="' . $block['attrs']['url'] . '"><span class="wp-block-navigation-item__label">' . $block['attrs']['label'] . '</span></a></li>';
    foreach ($GLOBALS['filters']['render_block_core/navigation-link'] as $filter) $content = $filter($content, $block);
    return $content;
};
$rendered = $render($blocks['/contact/']);
$assert(1 === preg_match('/<svg aria-hidden="true" focusable="false" class="fa-phone[^>]*>.*<\/svg><span class="wp-block-navigation-item__label">Contact<\/span>/s', $rendered), 'The runtime renders the SVG before the visible label: ' . $rendered);
$assert(!str_contains($rendered, 'font-size:0'), 'The icon + label item keeps its label visible: ' . $rendered);
$rendered = $render($blocks['https://linkedin.com/x']);
$assert(1 === preg_match('/<svg aria-hidden="true" focusable="false" class="fa-linkedin[^>]*>.*<\/svg><span class="wp-block-navigation-item__label" style="font-size:0;line-height:0">Linkedin<\/span>/s', $rendered), 'The icon-only item still hides its label behind the icon: ' . $rendered);

echo "Navigation icon + label artwork contract passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationAnchorRuntime;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$block = static function (string $id): string {
    return '<!-- wp:group ' . json_encode(array('metadata' => array('source' => array('id' => $id)), 'anchor' => $id)) . ' --><div id="' . $id . '" data-element-id="' . $id . '"></div><!-- /wp:group -->';
};
$assert(ShellExtraction::identityMarkup($block('id1793815975744')) === ShellExtraction::identityMarkup($block('id1793816196670')), 'Render ids in nested block metadata and anchors do not split identity.');
$assert(ShellExtraction::identityMarkup($block('authored-one')) !== ShellExtraction::identityMarkup($block('authored-two')), 'Authored ids retain identity.');
$labelBlock = static fn(string $id, string $title = 'Home', string $url = '/'): string => '<!-- wp:navigation-link ' . json_encode(array('label' => '<span id="' . $id . '" data-element-id="' . $id . '">' . $title . '</span>', 'url' => $url)) . ' /-->';
$assert(ShellExtraction::identityMarkup($labelBlock('id1793815975744')) === ShellExtraction::identityMarkup($labelBlock('id1793816196670')), 'Label HTML uses the same render-id normalization.');
$assert(ShellExtraction::identityMarkup($labelBlock('id1793815975744')) !== ShellExtraction::identityMarkup($labelBlock('id1793815975744', 'Changed')), 'Real link labels retain identity.');
$assert(ShellExtraction::identityMarkup($labelBlock('id1793815975744')) !== ShellExtraction::identityMarkup($labelBlock('id1793815975744', 'Home', '/changed/')), 'Real URLs retain identity.');
$files = array();
foreach (array('index.html' => '/', 'services/index.html' => '/services/', 'blog/index.html' => '/blog/') as $path => $current) {
    $items = '';
    foreach (array('/' => 'Home', '/services/' => 'Services', '/blog/' => 'Blog') as $href => $title) {
        $active = $href === $current;
        $zIndex = array('/' => array(14, 10, 7), '/services/' => array(6, 12, 8), '/blog/' => array(5, 9, 14))[$current][array_search($href, array('/', '/services/', '/blog/'), true)];
        $items .= '<li><a href="' . ('/' === $href ? '/index.html' : $href . 'index.html') . '"><span style="position:relative;z-index:' . $zIndex . '"><span style="background-color:rgba(255, 255, 255, 0)' . ($active ? ';border-width:0px 0px 4px' : '') . '"><span style="border-color:red;border-style:solid' . ($active ? ';border-width:0px 0px 4px' : '') . ';padding:0px;z-index:1"><span style="' . ($active ? 'border-radius:0px;' : '') . 'min-height:5px">' . $title . '</span></span></span></span></a></li>';
    }
    $files[] = array('path' => $path, 'kind' => 'html', 'content' => '<!doctype html><html><head><title>' . $current . '</title></head><body><div class="data-blocks-engine-capture-desktop-document full-screen-view ' . ('/' === $current ? 'pc-desktop-mode header-exists footer-exists' : 'header-exists footer-exists pc-desktop-mode') . '" data-blocks-engine-capture-document-scope=""><header><nav><ul>' . $items . '</ul></nav></header><main><h1>' . $current . '</h1><p>Page body</p></main></div></body></html>');
}
// Hrefs are candidates, not permission to erase arbitrary inline differences.
$withoutPair = $files;
foreach ($withoutPair as &$file) $file['content'] = str_replace(array(';border-width:0px 0px 4px', 'border-radius:0px;'), '', $file['content']);
unset($file);
foreach ((new RouteStateProjector())->project($withoutPair)['files'] as $file) $assert(!str_contains($file['content'], 'data-blocks-engine-route-state'), 'Varying z-index alone does not demonstrate current state.');
$ambiguous = $files;
foreach ($ambiguous as &$file) {
    $href = '/' . $file['path'];
    $file['content'] = str_replace('</ul>', '<li><a href="' . $href . '">Duplicate self target</a></li></ul>', $file['content']);
}
unset($file);
foreach ((new RouteStateProjector())->project($ambiguous)['files'] as $file) $assert(!str_contains($file['content'], 'data-blocks-engine-route-state'), 'Multiple self-targeting peers prevent href inference.');
$explicit = $files;
foreach ($explicit as &$file) $file['content'] = str_replace('<a href="/index.html">', '<a href="/index.html" aria-current="page">', $file['content']);
unset($file);
foreach ((new RouteStateProjector())->project($explicit)['files'] as $file) $assert(!str_contains($file['content'], 'data-blocks-engine-route-state'), 'Explicit markers take priority over conflicting self-target evidence.');
$projected = (new RouteStateProjector())->project($files)['files'];
foreach ($projected as $file) {
    $assert(str_contains($file['content'], 'data-blocks-engine-route-state'), 'Inline current paint is bound to route state.');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$bootstrap = current(array_filter($plan['writes'], static fn(array $write): bool => 'functions.php' === $write['target_path']));
$assert(str_contains($bootstrap['payload']['data'], NavigationAnchorRuntime::retainedLabelSource()), 'Menu-only route bindings install the rendering filter.');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Moving inline current paint produces one header part.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], '<header'), 'No header remains in page content.');

foreach (array('label', 'url') as $difference) {
    $different = $files[0];
    $different['path'] = 'different/index.html';
    $different['content'] = str_replace(array(';border-width:0px 0px 4px', 'border-radius:0px;'), '', $different['content']);
    $different['content'] = 'label' === $difference
        ? str_replace('>Services<', '>Different services<', $different['content'])
        : str_replace('href="/services/index.html"', 'href="/different-services/index.html"', $different['content']);
    $differentPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array_merge($files, array($different))))->toWordPressSitePlanView()['wordpress_site_plan'];
    $differentHeaders = array_values(array_filter($differentPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($differentHeaders), 'A real ' . $difference . ' change does not join the shared header.');
    $assert(!in_array('different/index.html', $differentHeaders[0]['placement']['source_paths'], true), 'Changed ' . $difference . ' route is excluded from shared placement.');
    $pages = array_column($differentPlan['pages'], null, 'source_path');
    $assert(str_contains($pages['different/index.html']['canonical_block_markup'], '<header'), 'Changed ' . $difference . ' keeps its own header.');
}

class WP_HTML_Tag_Processor
{
    private DOMDocument $document;
    private array $nodes;
    private int $position = -1;
    public function __construct(string $html) {
        $this->document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $this->document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $this->nodes = iterator_to_array((new DOMXPath($this->document))->query('//body//*'));
    }
    public function next_tag(?string $tag = null): bool {
        while (isset($this->nodes[++$this->position])) if (null === $tag || strtolower($tag) === strtolower($this->nodes[$this->position]->tagName)) return true;
        return false;
    }
    public function get_attribute(string $name): ?string { $node = $this->nodes[$this->position]; return $node->hasAttribute($name) ? $node->getAttribute($name) : null; }
    public function set_attribute(string $name, string $value): void { $this->nodes[$this->position]->setAttribute($name, $value); }
    public function remove_attribute(string $name): void { $this->nodes[$this->position]->removeAttribute($name); }
    public function get_updated_html(): string {
        $html = ''; foreach ($this->document->getElementsByTagName('body')->item(0)->childNodes as $node) $html .= $this->document->saveHTML($node);
        return $html;
    }
}
$filters = array();
function add_filter(string $name, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['filters'][$name][] = $callback; }
function get_queried_object_id(): int { return 1; }
function is_front_page(): bool { return '/' === $GLOBALS['served']; }
function get_post_type(int $id): string { return 'page'; }
function get_page_uri(int $id): string { return trim($GLOBALS['served'], '/'); }
eval(NavigationAnchorRuntime::retainedLabelSource());

$assert(3 === count($plan['pages']), 'All three route bodies are retained.');
foreach (array('/' => 'Home', '/services' => 'Services', '/blog' => 'Blog') as $route => $expected) {
    $GLOBALS['served'] = $route;
    $document = new DOMDocument();
    $menuHtml = '';
    foreach ($plan['menus'] as $menu) {
        preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $menu['block_markup'], $links, PREG_SET_ORDER);
        foreach ($links as $match) {
            $attrs = json_decode($match[1], true);
            $menuHtml .= '<a href="' . htmlspecialchars($attrs['url'], ENT_QUOTES) . '">' . $attrs['label'] . '</a>';
        }
    }
    @$document->loadHTML($menuHtml);
    $output = '';
    foreach ($document->getElementsByTagName('a') as $link) {
        $html = $document->saveHTML($link);
        foreach ($GLOBALS['filters']['render_block'] as $filter) $html = $filter($html, array('attrs' => array('label' => $link->textContent)));
        $output .= $html;
    }
    $assert(2 === substr_count($output, 'border-width:0px 0px 4px'), 'Only one served item has the active underline.');
    $rendered = new DOMDocument();
    @$rendered->loadHTML($output);
    foreach ($rendered->getElementsByTagName('a') as $index => $link) {
        $html = $rendered->saveHTML($link);
        $assert((trim($link->textContent) === $expected) === str_contains($html, 'border-width:0px 0px 4px'), 'Underline follows the matching served route only.');
        $assert((trim($link->textContent) === $expected) === str_contains($html, 'border-radius:0px'), 'Active-only radius follows the matching served route only.');
        $zIndex = array('/' => array(14, 10, 7), '/services' => array(6, 12, 8), '/blog' => array(5, 9, 14))[$route][$index];
        $assert(str_contains($html, 'position:relative;z-index:' . $zIndex . '"'), 'Every item restores its route-specific stacking order, including resting items.');
    }
}

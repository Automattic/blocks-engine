<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Issue #5343: a shared component whose device captures differ only by class
// tokens keeps its per-device class evidence on the native block root, like
// capture-device-inline, so the capture overlay can swap the tokens back in.
$deviceClass = '{"desktop":"title","mobile":"title title--sm"}';
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Home</title><link rel="stylesheet" href="site.css"></head><body><main><h2 class="title" data-blocks-engine-capture-device-class="' . htmlspecialchars($deviceClass, ENT_QUOTES) . '">Hello</h2></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'site.css' => '.title{font-size:40px}.title--sm{font-size:18px}.unused{color:red}')))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
$assert(1 === preg_match_all('/<!-- wp:heading (\{.*?\}) -->(.*?)<!-- \/wp:heading -->/s', $markup, $headings) && !str_contains($markup, '<!-- wp:html'), 'One native core/heading: ' . $markup);
$attrs = json_decode($headings[1][0], true, 512, JSON_THROW_ON_ERROR);
$assert($deviceClass === ($attrs['metadata']['blocksEngineRuntimeAttributes']['data-blocks-engine-capture-device-class'] ?? null), 'Heading metadata retains the exact device-class evidence: ' . $headings[1][0]);
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === ($write['target_path'] ?? '')) $bootstrap = (string) ($write['payload']['data'] ?? '');
$assert(str_contains($bootstrap, "'data-blocks-engine-capture-device-class'"), 'Theme render filter allows device-class evidence.');

// Minimal HTML API stand-in: execute the shipped render filter.
class WP_HTML_Tag_Processor
{
    private DOMDocument $dom;
    private ?DOMElement $root = null;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
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
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['runtimeAttributesFilter'] = $callback; }
eval(Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime::source());
$rendered = $GLOBALS['runtimeAttributesFilter']($headings[2][0], array('blockName' => 'core/heading', 'attrs' => $attrs));
$root = new DOMDocument();
@$root->loadHTML('<?xml encoding="utf-8"?><html><body>' . $rendered . '</body></html>');
$h2 = $root->getElementsByTagName('h2')->item(0);
$assert($h2 instanceof DOMElement && $deviceClass === $h2->getAttribute('data-blocks-engine-capture-device-class'), 'Rendered heading root carries device-class unchanged: ' . $rendered);

$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
// The site stylesheet is pruned by usage: the token named only in device-class is live.
$assert(1 === preg_match('/\.title--sm\s*\{[^}]*font-size:\s*18px/', $css) && !str_contains($css, '.unused'), 'Emitted CSS keeps a rule matching the mobile-only token: ' . $css);
echo "Capture device-class evidence: native root, render filter, and mobile-only rule retained.\n";

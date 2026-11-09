<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$plan = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/responsive-fragments.php')->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Two routes share one responsive header.');
$header = $headers[0]['canonical_block_markup'];
$assert(1 === count($plan['menus']), 'Both device headers share an authored menu.');
$menu = $plan['menus'][0]['canonical_block_markup'] ?? $plan['menus'][0]['block_markup'];
$assert(str_contains($menu, '#section') && !str_contains($menu, '#section--'), 'Menu retains authored fragment.');
// Resolve the navigation entity as WordPress does when rendering the header.
$header = preg_replace('/<!-- wp:navigation .*? \/-->/', $menu, $header);
$header = preg_replace_callback('/<!-- wp:navigation-link (\{.*?\}) \/-->/', static function (array $match): string {
    $attrs = json_decode($match[1], true);
    return '<a href="' . htmlspecialchars($attrs['url'], ENT_QUOTES) . '">' . htmlspecialchars($attrs['label']) . '</a>';
}, $header);
$assert(!str_contains($header, '#section--'), 'Shared header links stay authored.');
$pages = array_column($plan['pages'], null, 'source_path');
$content = $pages['index.html']['canonical_block_markup'];
$assert(2 === substr_count($content, '"data-blocks-engine-capture-responsive-fragment":"section"'), 'Both target copies retain the responsive fragment contract.');
// The capture's own resolver is replay plumbing: empty span targets stay native and the script is not shipped.
$assert(!str_contains($content, '<!-- wp:html') && 2 === substr_count($content, '"anchor":"intro'), 'Empty fragment targets lower natively.');
$assert(!str_contains(json_encode($plan['assets']) . json_encode($plan['writes']), 'querySelectorAll(\\"[data-blocks-engine-capture-responsive-fragment]'), 'Capture resolver script is not shipped.');
$assert(str_contains($content, 'section--data-blocks-engine-capture-mobile'), 'Mobile target keeps the document variant id.');
$assert(!str_contains($pages['other.html']['canonical_block_markup'], 'data-blocks-engine-capture-responsive-fragment'), 'Only one route owns the target.');
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
$assert(str_contains($bootstrap, 'blocks-engine-responsive-fragments'), 'Theme delivers responsive fragment resolution.');
// Exercise the generated enqueue callback, not a separately supplied script.
function add_action(string $hook, callable $callback): void { if ('wp_enqueue_scripts' === $hook) $callback(); }
function wp_register_script(...$args): void {}
function wp_enqueue_script(...$args): void {}
function wp_add_inline_script(string $handle, string $script): void { $GLOBALS['fragmentScript'] = $script; }
preg_match("/^add_action[^\\n]*blocks-engine-responsive-fragments.*?^.*?\\} \\);$/ms", $bootstrap, $enqueue);
eval($enqueue[0]);
// Minimal HTML API stand-in, as in multi-image-srcset-box.php: execute the
// shipped render filter instead of simulating its attribute allowlist in JS.
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
    public function get_updated_html(): string { return $this->dom->saveHTML($this->root); }
}
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['runtimeAttributesFilter'] = $callback; }
eval(Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime::source());
$content = preg_replace_callback('/<!-- wp:heading (\{.*?\}) -->(.*?)<!-- \/wp:heading -->/s', static function (array $match): string {
    return $GLOBALS['runtimeAttributesFilter']($match[2], array('attrs' => json_decode($match[1], true)));
}, $content);
$assert(2 === substr_count($content, 'data-blocks-engine-capture-responsive-fragment="section"'), 'Render filter restores both target bindings.');
file_put_contents(sys_get_temp_dir() . '/responsive-fragments.json', json_encode(array('content' => $content, 'header' => $header, 'script' => $GLOBALS['fragmentScript'], 'css' => implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content')))));
echo "Responsive fragments passed\n";

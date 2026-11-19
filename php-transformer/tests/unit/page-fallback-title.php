<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$view = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Home</title></head><body><main><h1>Home</h1><a href="menu.html">Menu</a></main></body></html>',
    'menu.html' => '<html><head><title>Menu</title></head><body><main><h1 class="hero">Our menu</h1><p>Lunch choices.</p></main></body></html>',
)))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$assert(str_contains($templates['page'], '<!-- wp:post-title {"level":1,"metadata":{"blocksEngineFallbackTitle":true}} /-->'), 'New pages have a dynamic title before their content.');
$pages = array_column($plan['pages'], 'canonical_block_markup', 'slug');
$assert(str_contains($pages['menu'], 'Our menu'), 'The captured title stays in its authored content.');
$writes = array_column($plan['writes'], null, 'target_path');
$bootstrap = $writes['functions.php']['payload']['data'];
$assert(1 === preg_match("~add_filter\\( 'render_block_core/post-title', static function .*?\\n\\}, 10, 3 \\);~s", $bootstrap, $match), 'The emitted theme guards fallback titles.');

// Exercise the emitted WordPress callback using the small host API it needs.
function add_filter($hook, $callback, ...$args): void { $GLOBALS['title_callback'] = $callback; }
function get_post(int $id = 0): object { return (object) array('post_content' => 42 === $id ? $GLOBALS['body'] : '<h1>Ambient captured page</h1>'); }
class WP_Block {
    public array $context = array('postId' => 42);
}
class WP_HTML_Tag_Processor {
    private DOMDocument $dom;
    public function __construct(string $html) {
        $this->dom = new DOMDocument();
        @$this->dom->loadHTML('<html><body>' . $html . '</body></html>');
    }
    public function next_tag(string $tag): bool { return $this->dom->getElementsByTagName(strtolower($tag))->length > 0; }
}
eval($match[0]);
$render = $GLOBALS['title_callback'];
$block = array('attrs' => array('metadata' => array('blocksEngineFallbackTitle' => true)));
$instance = new WP_Block();
foreach (array('', '<p>New content</p>', '<pre>&lt;h1&gt;example&lt;/h1&gt;</pre>') as $body) {
    $GLOBALS['body'] = $body;
    $assert('<h1>New title</h1>' === $render('<h1>New title</h1>', $block, $instance), 'New content receives its title.');
}
$GLOBALS['body'] = $pages['menu'];
$assert('' === $render('<h1>Menu</h1>', $block, $instance), 'Captured H1 suppresses the fallback without changing captured content.');
$assert('<h1>Explicit</h1>' === $render('<h1>Explicit</h1>', array(), $instance), 'Explicit title blocks are not suppressed.');
echo "page fallback title: passed\n";

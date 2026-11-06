<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$html = '<!doctype html><html><head><style>.menu{list-style:none;margin:0;padding:0}.item{display:block;border-bottom:1px solid #ccc}.row{display:flex;align-items:center;justify-content:space-between;padding:8px 16px}.label{color:#333;font:16px/1.5 sans-serif;text-decoration:none}.toggle{background:none;border:0;padding:4px}.sub{list-style:none;margin:0;padding-left:16px}</style></head><body><header><nav><ul class="menu"><li class="item"><div class="row"><span class="wrap"><a class="label" href="/">Home</a></span></div></li><li class="item"><div class="row"><span class="wrap"><a class="label" href="/about">About</a></span></div></li><li class="item"><div class="row"><span class="wrap"><a class="label" href="/contact">Contact</a></span><button class="toggle" aria-expanded="false" aria-haspopup="true" aria-label="Contact"></button></div><ul class="sub"><li><a href="/subscribe">Subscribe</a></li></ul></li></ul></nav></header><main><p>Body</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(1 === preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->/s', $markup, $matches), 'Contact lowers to a submenu.');
$attrs = json_decode($matches[1], true) ?: array();
$anchor = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? array();
$assert(isset($anchor['boxes']) && count($anchor['boxes']) >= 2, 'The submenu retains its wrapper and anchor boxes.');
$assert(1 === preg_match('/blocks-engine-navigation-item-[a-f0-9]+/', (string) ($attrs['className'] ?? ''), $marker), 'The submenu receives a navigation item marker.');
$assert(str_contains((string) ($anchor['className'] ?? ''), 'label'), 'The label anchor remains the submenu anchor.');
echo "navigation submenu row toggle item box contract passed\n";

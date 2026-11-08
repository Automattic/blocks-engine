<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A captured hamburger opens a hidden panel that wraps the menu list, so the
// panel becomes the navigation. Its resting box (here 280px, absolute) belongs
// to Core's responsive container, not to the navigation root that renders the
// opener (Automattic/blocks-engine#4945).
$runtime = (string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-disclosure-trigger-runtime.js');
$html = '<!doctype html><html><head><style>.dropdown-panel[data-panel]{width:280px;position:absolute}</style>'
    . '<script data-blocks-engine-capture-disclosure-runtime="true">' . $runtime . '</script></head><body><header><nav>'
    . '<button data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0" aria-controls="data-blocks-engine-capture-dialog-0"></button>'
    . '<div hidden><div class="dropdown-panel" data-panel><ul id="menu-items"><li><a href="/news/index.html">News</a></li><li><a href="/contact/index.html">Contact</a></li></ul></div></div>'
    . '</nav></header></body></html>';
$markup = (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(1 === preg_match('/<!-- wp:navigation (\{.*?\}) (?:\/)?-->/', $markup, $match), 'The menu compiles to native navigation.');
$attrs = json_decode($match[1], true);
$classes = static fn(string $value): array => preg_split('/\s+/', trim($value)) ?: array();
$root = $classes((string) ($attrs['className'] ?? ''));
$container = $classes((string) ($attrs['metadata']['blocksEngineNavigationContainer']['className'] ?? ''));
$assert(in_array('blocks-engine-native-responsive-navigation', $root, true), 'The captured opener promotes a native responsive menu.');
$panel = array_values(array_merge(array('dropdown-panel'), preg_grep('/^blocks-engine-attribute-/', $container)));
$assert(count($panel) > 1 && array() === array_diff($panel, $container), 'The responsive container carries the panel class and its attribute class: ' . implode(' ', $container));
$assert(array() === array_intersect($panel, $root), 'The navigation root carries none of them: ' . implode(' ', $root));
echo "ok\n";

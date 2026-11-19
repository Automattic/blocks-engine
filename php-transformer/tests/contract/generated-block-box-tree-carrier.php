<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
};

// A generated block (a drawing surface) builds its markup by hand and
// declares no style supports, so a style attribute is never serialized: on an
// evidence route the observed background and border must stay in the box-tree
// carrier. Stored as attributes, they paint nothing, and a colour equal to a
// palette preset stays a literal the palette cannot reach
// (literal_preset_color_count).
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 64, JSON_THROW_ON_ERROR);
$html = '<!doctype html><html><head><title>Sketch</title><style>h1{color:#880000}canvas{background-color:#880000;border:1px solid #cccccc}p{color:#cccccc}</style></head><body><main><h1>Sketch</h1><p>Draw below.</p><canvas id="board" width="400" height="200"></canvas></main></body></html>';
$nodes = $stack = array();
$count = 0;
$canvas = null;
$html = (string) preg_replace_callback('/<(\/?)([a-z][a-z0-9]*)([^>]*)>/i', static function (array $match) use (&$nodes, &$stack, &$count, &$canvas): string {
    if ('/' === $match[1]) {
        array_pop($stack);
        return $match[0];
    }
    $id = 'n' . ++$count;
    $nodes[$id] = array('children' => array());
    if (array() !== $stack) $nodes[end($stack)]['children'][] = $id;
    if ('canvas' === strtolower($match[2])) $canvas = $id;
    $stack[] = $id;
    return '<' . $match[2] . ' data-blocks-engine-node-id="' . $id . '"' . $match[3] . '>';
}, $html);
foreach ($nodes as $id => &$node) {
    $node['viewports'] = $template['nodes']['n7']['viewports'];
    if ($canvas !== $id) continue;
    foreach ($node['viewports'] as &$viewport) {
        $viewport['border_box'] = array('x' => 0, 'y' => 80, 'width' => 402, 'height' => 202);
        $viewport['used_styles'] = array_replace($viewport['used_styles'], array('display' => 'inline', 'background-color' => 'rgb(136, 0, 0)', 'border-top' => '1px solid rgb(204, 204, 204)', 'border-right' => '1px solid rgb(204, 204, 204)', 'border-bottom' => '1px solid rgb(204, 204, 204)', 'border-left' => '1px solid rgb(204, 204, 204)', 'width' => '400px', 'height' => '200px'));
    }
    unset($viewport);
}
unset($node);
$tree = array('schema' => $template['schema'], 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html), 'viewports' => $template['viewports'], 'nodes' => $nodes);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR))))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(array() !== $plan, 'The site plan compiles.');
$page = array_column($plan['pages'], null, 'source_path')['index.html'] ?? array();
$assert(!empty($page['document_metadata']['engine_owned_styles']), 'The page is an evidence route.');
$palette = array_column($plan['theme']['design_system']['palette'] ?? array(), 'slug', 'color');
$assert(isset($palette['#880000']) && isset($palette['#cccccc']), 'The palette holds the surface colours.');

$markup = (string) ($page['canonical_block_markup'] ?? '');
$assert(1 === preg_match('/<!-- wp:([a-z0-9-]+\/canvas) (\{.*?\}) -->/', $markup, $block), 'The canvas is a generated drawing-surface block.');
$attributes = json_decode($block[2], true);
$assert(!isset($attributes['style']), 'The generated block stores no style attribute it cannot serialize: ' . $block[2]);
$carrier = null;
foreach (preg_split('/\s+/', (string) ($attributes['className'] ?? '')) ?: array() as $class) if (str_starts_with($class, 'be-inline-geometry-')) $carrier = $class;
$assert(null !== $carrier, 'The generated block has a box-tree carrier class.');
$css = implode("\n", array_map(static fn (array $write): string => (string) ($write['payload']['data'] ?? ''), array_filter($plan['writes'], static fn (array $write): bool => 'utf8' === ($write['payload']['encoding'] ?? null) && str_ends_with($write['target_path'], '.css'))));
$assert(1 === preg_match('/\.' . preg_quote($carrier, '/') . '(?:\.' . preg_quote($carrier, '/') . ')?\{([^}]*)\}/', $css, $rule), 'The carrier rule is shipped.');
$assert(1 === preg_match('/background-color:[^;]*(?:rgb\(136, 0, 0\)|#880000|--wp--preset--color--)/i', $rule[1]), 'The carrier paints the observed background: ' . $rule[1]);
$assert(1 === preg_match('/border-color:[^;]*(?:rgb\(204, 204, 204\)|#cccccc|--wp--preset--color--)/i', $rule[1]), 'The carrier paints the observed border.');
$assert(0 === ($plan['theme']['design_system']['metrics']['literal_preset_color_count'] ?? null), 'No block colour attribute stays a literal equal to a preset.');
$assert(array() === ($plan['theme']['design_system']['literal_preset_colors'] ?? null), 'The report lists no literal preset colour.');

<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #6802: a scroll-state wrapper is a hand-built core/group. On an evidence
// route the author stylesheets are retired, so without the box-tree carrier
// every converted group gets, a fixed header rendered static, unpadded and
// transparent. The carrier must not set a property the runtime toggles on
// `:scope`, whose !important rule would otherwise pin it against the toggle.
$config = '{"thresholdPx":2,"addClasses":["shrink"],"removeClasses":[],"styleTargets":[{"selector":":scope","properties":{"height":{"rest":"80px","scrolled":"56px"}}}]}';
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . 'body{margin:0}.site-header{position:fixed;top:0;left:0;right:0;z-index:12;height:80px;padding:16px 24px;background:rgb(20, 30, 40)}'
    . '</style></head><body data-blocks-engine-node-id="n4">'
    . '<header class="site-header" data-blocks-engine-node-id="hd" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . htmlspecialchars($config, ENT_QUOTES) . '">'
    . '<p data-blocks-engine-node-id="p">Brand</p></header>'
    . '<main data-blocks-engine-node-id="m"><h1 data-blocks-engine-node-id="h">Welcome</h1></main></body></html>';
$template = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
foreach ($template as $property => $value) {
    if (str_starts_with($property, 'margin-') || str_starts_with($property, 'padding-')) $template[$property] = '0px';
}
$widths = array('desktop' => 1440, 'mobile' => 402);
$node = static function (array $children, callable $observe) use ($template, $widths): array {
    $viewports = array();
    foreach ($widths as $key => $width) {
        [$box, $styles] = $observe($width);
        $viewports[$key] = array('border_box' => array_combine(array('x', 'y', 'width', 'height'), $box), 'used_styles' => array_replace($template, $styles));
    }
    return array('children' => $children, 'viewports' => $viewports);
};
$nodes = array(
    'n1' => $node(array('n2', 'n4'), static fn(int $w): array => array(array(0, 0, $w, 900), array('width' => $w . 'px', 'height' => '900px'))),
    'n2' => $node(array(), static fn(int $w): array => array(array(0, 0, 0, 0), array('display' => 'none'))),
    'n4' => $node(array('hd', 'm'), static fn(int $w): array => array(array(0, 0, $w, 900), array('width' => $w . 'px'))),
    'hd' => $node(array('p'), static fn(int $w): array => array(array(0, 0, $w, 80), array(
        'position' => 'fixed', 'top' => '0px', 'left' => '0px', 'right' => '0px', 'z-index' => '12',
        'width' => $w . 'px', 'height' => '80px',
        'padding-top' => '16px', 'padding-bottom' => '16px', 'padding-left' => '24px', 'padding-right' => '24px',
        'background-color' => 'rgb(20, 30, 40)',
    ))),
    'p' => $node(array(), static fn(int $w): array => array(array(24, 16, $w - 48, 20), array('width' => ($w - 48) . 'px', 'height' => '20px'))),
    'm' => $node(array('h'), static fn(int $w): array => array(array(0, 0, $w, 40), array('width' => $w . 'px'))),
    'h' => $node(array(), static fn(int $w): array => array(array(0, 0, $w, 40), array('width' => $w . 'px', 'height' => '40px', 'font-size' => '36px'))),
);
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844)),
    'nodes' => $nodes,
);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$result = $compiled->toArray();
$assert(!in_array('box_tree_styles_invalid', array_column($result['diagnostics'], 'code'), true), 'The box-tree sidecar is accepted.');
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$planAssets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($planAssets[$row['target_path']]['content'] ?? '') . "\n";

$assert(1 === preg_match('/<!-- wp:group (\{.*?"blocksEngineScrollState".*?\}) -->/', $result['serialized_blocks'], $match), 'The header converts to a scroll-state group.');
$classes = preg_split('/\s+/', (string) (json_decode($match[1], true)['className'] ?? '')) ?: array();

// The declarations the route stylesheets give the header's own classes, outside @media.
$declarations = array();
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $ancestors) use ($classes, &$declarations): void {
    foreach ($ancestors as $ancestor) {
        if (preg_match('/^@media\b/i', trim((string) $ancestor))) return;
    }
    foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
        // A carrier rule repeats its class for specificity (`.c.c`).
        $compound = preg_split('/(?=\.)/', trim($arm), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $own = array() !== $compound && array() === array_diff($compound, array_map(static fn(string $class): string => '.' . $class, $classes));
        if ($own) $declarations = array_replace($declarations, SourceDeclarations::parse($body));
    }
});
$value = static fn(string $property): string => strtolower(trim(str_replace('!important', '', (string) ($declarations[$property] ?? ''))));

$assert('fixed' === $value('position'), 'The header carrier keeps position:fixed (got ' . var_export($value('position'), true) . ').');
$assert('0px' === $value('top') || '0' === $value('top'), 'The header carrier keeps top.');
$assert('12' === $value('z-index'), 'The header carrier keeps z-index.');
$assert(!array_key_exists('height', $declarations), 'The header carrier leaves the toggled height to the scroll-state runtime.');

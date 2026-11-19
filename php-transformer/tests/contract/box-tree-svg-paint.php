<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// An evidence route does not ship the author stylesheet (ADR 0005), so SVG
// artwork kept as source markup keeps its paint only through the observed
// fill and stroke. A control glyph whose shapes the author painted grey, with
// a light count badge, otherwise renders in the initial black fill.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<header data-blocks-engine-node-id="n20"><nav aria-label="Main" data-blocks-engine-node-id="n21"><ul class="menu" data-blocks-engine-node-id="n22">'
        . '<li class="menu-item" data-blocks-engine-node-id="n23"><a href="/shop" data-blocks-engine-node-id="n24">Shop</a></li></ul></nav>'
        . '<button type="button" class="bag" aria-label="Bag" data-blocks-engine-node-id="n31"><span class="bag-icon" data-blocks-engine-node-id="n32">'
        . '<svg aria-hidden="true" viewBox="0 0 20 24" width="100%" height="100%" data-blocks-engine-node-id="n33">'
        . '<path d="M0 6h20v18H0z" data-blocks-engine-node-id="n34"></path>'
        . '<path class="handle" d="M5 6a5 5 0 0 1 10 0" data-blocks-engine-node-id="n35"></path>'
        . '<circle class="badge" cx="16" cy="4" r="4" data-blocks-engine-node-id="n36"></circle>'
        . '</svg></span></button></header>',
    $html
);
// No author rule is given: the observed used values are the only paint source.
$tree['source_hash'] = hash('sha256', $html);
$template = $tree['nodes']['n7'];
unset($tree['nodes']['n7']);
$unpainted = array(
    'fill' => 'rgb(0, 0, 0)', 'fill-opacity' => '1', 'fill-rule' => 'nonzero', 'stroke' => 'none', 'stroke-width' => '1px',
    'stroke-opacity' => '1', 'stroke-linecap' => 'butt', 'stroke-linejoin' => 'miter', 'stroke-dasharray' => 'none', 'stroke-dashoffset' => '0px',
);
$build = static function (bool $paint) use ($tree, $template, $unpainted): array {
    $observed = static function (array $styles, array $children = array()) use ($template, $unpainted, $paint): array {
        $node = $template;
        foreach ($node['viewports'] as $key => $_) {
            $node['viewports'][$key]['used_styles'] = array_replace($node['viewports'][$key]['used_styles'], $paint ? $unpainted : array(), $paint ? $styles : array_diff_key($styles, $unpainted));
        }
        $node['children'] = $children;
        return $node;
    };
    if ($paint) {
        foreach ($tree['nodes'] as $id => $node) {
            foreach ($node['viewports'] as $key => $_) $tree['nodes'][$id]['viewports'][$key]['used_styles'] += $unpainted;
        }
    }
    $tree['nodes']['n5']['children'] = array('n6', 'n20');
    $tree['nodes']['n20'] = $observed(array('display' => 'flex'), array('n21', 'n31'));
    $tree['nodes']['n21'] = $observed(array(), array('n22'));
    $tree['nodes']['n22'] = $observed(array('display' => 'flex'), array('n23'));
    $tree['nodes']['n23'] = $observed(array('display' => 'list-item'), array('n24'));
    $tree['nodes']['n24'] = $observed(array('display' => 'inline'));
    $tree['nodes']['n31'] = $observed(array('display' => 'block', 'width' => '20px'), array('n32'));
    $tree['nodes']['n32'] = $observed(array('position' => 'relative', 'width' => '20px', 'height' => '0px', 'padding-bottom' => '24px'), array('n33'));
    // The artwork's own fill reaches the body shape by inheritance.
    $tree['nodes']['n33'] = $observed(array('position' => 'absolute', 'top' => '0px', 'left' => '0px', 'width' => '20px', 'height' => '24px', 'fill' => 'rgb(51, 51, 51)'), array('n34', 'n35', 'n36'));
    $tree['nodes']['n34'] = $observed(array('display' => 'inline', 'fill' => 'rgb(51, 51, 51)'));
    $tree['nodes']['n35'] = $observed(array('display' => 'inline', 'fill' => 'none', 'stroke' => 'rgb(51, 51, 51)', 'stroke-width' => '2px', 'stroke-linecap' => 'round'));
    $tree['nodes']['n36'] = $observed(array('display' => 'inline', 'fill' => 'rgb(248, 244, 240)', 'fill-opacity' => '0.9'));
    return $tree;
};
$compile = static function (array $tree) use ($html): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    $codes = array_values(array_filter(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $result['diagnostics'] ?? array()), static fn(string $code): bool => str_starts_with($code, 'box_tree_')));
    return array($result['serialized_blocks'], $css, $codes);
};
[$markup, $css, $codes] = $compile($build(true));
$assert = static function (bool $ok, string $message) use (&$markup, &$css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};
$rule = static function (string $carrier) use (&$css): string {
    return 1 === preg_match('/\.' . preg_quote($carrier, '/') . '\.' . preg_quote($carrier, '/') . '\{([^}]*)\}/', $css, $match) ? $match[1] : '';
};
$carrier = static function (string $pattern) use (&$markup): string {
    return 1 === preg_match($pattern, $markup, $match) ? $match[1] : '';
};

$assert(array() === $codes, 'Evidence carrying observed SVG paint is accepted.');
$artwork = $carrier('/<svg [^>]*class="(be-inline-geometry-[0-9a-f]+)"/');
$assert('' !== $artwork && str_contains($rule($artwork), 'fill:rgb(51, 51, 51)'), 'The artwork restates the fill it was observed with.');
$assert(1 === preg_match('/<path d="M0 6h20v18H0z"( class="[^"]*")?/', $markup, $shape) && !preg_match('/fill/', $rule(preg_replace('/^ class="|"$/', '', $shape[1] ?? ''))), 'A shape that inherits the artwork fill does not restate it.');
$handle = $carrier('/<path class="handle (be-inline-geometry-[0-9a-f]+)"/');
$declared = $rule($handle);
$assert('' !== $handle && str_contains($declared, 'fill:none') && str_contains($declared, 'stroke:rgb(51, 51, 51)')
    && str_contains($declared, 'stroke-width:2px') && str_contains($declared, 'stroke-linecap:round'), 'A stroked shape restates its stroke.');
$badge = $carrier('/<circle class="badge (be-inline-geometry-[0-9a-f]+)"/');
$declared = $rule($badge);
$assert('' !== $badge && str_contains($declared, 'fill:rgb(248, 244, 240)') && str_contains($declared, 'fill-opacity:0.9'), 'A light badge shape restates its fill.');
$assert(!preg_match('/(?<![-\w])fill:rgb\(0, 0, 0\)/', $css) && !str_contains($css, 'stroke:none'), 'Paint equal to what an element inherits is not restated.');

// Evidence captured before producers recorded paint stays valid.
[$markup, $css, $codes] = $compile($build(false));
$assert(array() === $codes, 'Evidence without SVG paint is still accepted.');
$assert(!preg_match('/(?<![-\w])(?:fill|stroke)(?:-[a-z]+)?:/', $css), 'Without observed paint no paint is restated.');

<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #6584: a flex-item link lowered to <p class="blocks-engine-synthetic-paragraph"><a>
// carries its captured `margin:0 12px` on the source anchor, not its synthetic
// paragraph. The margin must apply once per side, including media variants.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . 'body{margin:0}.bar{display:flex;padding:20px}.links{display:flex;margin:0 auto}.links a{margin:0 12px}'
    . '</style></head><body data-blocks-engine-node-id="n4"><header class="bar" data-blocks-engine-node-id="h"><div class="links" data-blocks-engine-node-id="l">'
    . '<a href="#" data-blocks-engine-node-id="a1">Home</a><a href="#" data-blocks-engine-node-id="a2">Services</a><a href="#" data-blocks-engine-node-id="a3">Team</a>'
    . '</div></header><main data-blocks-engine-node-id="m"><p data-blocks-engine-node-id="p">Body</p></main></body></html>';
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
$pad = array('padding-top' => '20px', 'padding-right' => '20px', 'padding-bottom' => '20px', 'padding-left' => '20px');
$nodes = array(
    'n1' => $node(array('n2', 'n4'), static fn(int $w): array => array(array(0, 0, $w, 900), array('width' => $w . 'px', 'height' => '900px'))),
    'n2' => $node(array(), static fn(int $w): array => array(array(0, 0, 0, 0), array('display' => 'none'))),
    'n4' => $node(array('h', 'm'), static fn(int $w): array => array(array(0, 0, $w, 108), array('width' => $w . 'px'))),
    'h' => $node(array('l'), static fn(int $w): array => array(array(0, 0, $w, 58), array('display' => 'flex', 'width' => ($w - 40) . 'px', 'height' => '18px') + $pad)),
    // The source: `.links` is 213px wide, centered by its auto margins.
    'l' => $node(array('a1', 'a2', 'a3'), static fn(int $w): array => array(array(($w - 213) / 2, 20, 213, 18), array('display' => 'flex', 'margin-left' => (($w - 253) / 2) . 'px', 'margin-right' => (($w - 253) / 2) . 'px', 'width' => '213px', 'height' => '18px'))),
    'm' => $node(array('p'), static fn(int $w): array => array(array(0, 58, $w, 50), array())),
    'p' => $node(array(), static fn(int $w): array => array(array(0, 74, $w, 18), array('margin-top' => '16px', 'margin-bottom' => '16px'))),
);
$sourceX = array(625, 693, 775);
foreach (array(44, 58, 39) as $index => $width) {
    $x = $sourceX[$index];
    $nodes['a' . ($index + 1)] = $node(array(), static fn(int $w): array => array(
        array($x - 613 + ($w - 213) / 2, 20, $width, 18),
        array('display' => 'block', 'margin-left' => '12px', 'margin-right' => '12px', 'width' => $width . 'px', 'height' => '18px', 'color' => 'rgb(0, 0, 238)', 'text-decoration-line' => 'underline'),
    ));
}
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

$verify = static function (array $tree) use ($html, $assert, $widths): void {
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
    $result = $compiled->toArray();
    $assert(!in_array('box_tree_styles_invalid', array_column($result['diagnostics'], 'code'), true), 'The box-tree sidecar is accepted.');
    // The stylesheets the page actually loads: the evidence route retires author rules.
    $plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
    $planAssets = array_column($plan['assets'], null, 'target_path');
    $css = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($planAssets[$row['target_path']]['content'] ?? '') . "\n";
    $assert(!str_contains($css, '.links a'), 'The author link margin rule is retired on the evidence route.');
    $dom = new DOMDocument();
    @$dom->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>');
    $anchors = array();
    foreach ($dom->getElementsByTagName('a') as $anchor) $anchors[] = $anchor;
    $assert(3 === count($anchors), 'All three links survive.');

    /** The declared value of $property that wins on $element at $width, ignoring inheritance. */
    $cascade = static function (DOMElement $element, string $property, int $width) use ($css): ?string {
        $winner = null;
        $rank = array(-1, -1);
        $order = 0;
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $ancestors) use ($element, $property, $width, &$winner, &$rank, &$order): void {
            $order++;
            foreach ($ancestors as $ancestor) {
                if (!preg_match('/^@media\s*\((max|min)-width:\s*(\d+(?:\.\d+)?)px\)$/i', trim((string) $ancestor), $m)) return;
                if (('max' === strtolower($m[1])) !== ($width <= (float) $m[2])) return;
            }
            $declarations = SourceDeclarations::parse($body);
            $value = $declarations[$property] ?? null;
            if (null === $value && isset($declarations['margin']) && in_array($property, array('margin-left', 'margin-right'), true)) {
                // Horizontal sides of the shorthand: 1 value -> it, 2-3 -> second, 4 -> second/fourth.
                $parts = preg_split('/\s+/', trim($declarations['margin'])) ?: array();
                $value = $parts[4 === count($parts) && 'margin-left' === $property ? 3 : (1 === count($parts) ? 0 : 1)];
            }
            if (null === $value) return;
            foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
                $parsed = CssSelectorMatcher::parse($arm);
                if (!CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
                $specificity = CssSelectorMatcher::specificity($parsed);
                if (array($specificity, $order) >= $rank) {
                    $rank = array($specificity, $order);
                    $winner = $value;
                }
            }
        });
        $inline = SourceDeclarations::parse($element->getAttribute('style'))[$property] ?? null;
        return $inline ?? $winner;
    };
    $px = static fn(?string $value): float => null === $value || !preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m) ? 0.0 : (float) $m[1];

    foreach ($widths as $key => $width) {
        foreach ($anchors as $anchor) {
            $carrier = $anchor->parentNode;
            $assert($carrier instanceof DOMElement && str_contains($carrier->getAttribute('class'), 'blocks-engine-synthetic-paragraph'), "$key: the link keeps its synthetic paragraph carrier");
            $boxed = 'contents' !== ($cascade($carrier, 'display', $width) ?? 'contents');
            $assert(!$boxed, "$key: the synthetic paragraph generates no box");
            $assert($cascade($anchor, 'display', $width) === $tree['nodes']['a1']['viewports'][$key]['used_styles']['display'], "$key: the source anchor preserves captured display ownership");
            foreach (array('margin-left', 'margin-right') as $side) {
                $carrierMargin = $px($cascade($carrier, $side, $width));
                $anchorValue = $cascade($anchor, $side, $width);
                $anchorMargin = 'inherit' === $anchorValue ? $carrierMargin : $px($anchorValue);
                $applied = ($boxed ? $carrierMargin : 0.0) + $anchorMargin;
                $assert(12.0 === $applied, "$key: {$anchor->textContent} $side applies 12px once (carrier " . ($boxed ? 'boxed' : 'contents') . ", applied {$applied}px)");
            }
        }
    }
};
$verify($tree);

// A narrower capture changes the source anchor's display, not its wrapper's.
foreach (array('a1', 'a2', 'a3') as $id) {
    $tree['nodes'][$id]['viewports']['mobile']['used_styles']['display'] = 'contents';
}
$verify($tree);

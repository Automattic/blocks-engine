<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #5797: an inline link before a heading (a product page's category eyebrow)
// is lowered to <p class="blocks-engine-synthetic-paragraph
// blocks-engine-inline-anchor-carrier"><a>. That paragraph is the RichText
// root the editor focuses to edit the link text, so it must keep an inline
// box on an evidence route: a box-tree carrier that made it display:contents
// left it unfocusable, and text typed into it never reached the saved post.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . 'body{margin:0}.eyebrow{color:rgb(200, 150, 50);text-transform:uppercase;letter-spacing:3px;font-size:11px}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m"><div class="info" data-blocks-engine-node-id="d">'
    . '<a class="eyebrow" href="/shop/interior/" data-blocks-engine-node-id="a">Interior</a><h1 data-blocks-engine-node-id="h">Alcantara Steering Wheel</h1>'
    . '</div></main></body></html>';
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
    'n4' => $node(array('m'), static fn(int $w): array => array(array(0, 0, $w, 60), array('width' => $w . 'px'))),
    'm' => $node(array('d'), static fn(int $w): array => array(array(0, 0, $w, 60), array('width' => $w . 'px'))),
    'd' => $node(array('a', 'h'), static fn(int $w): array => array(array(0, 0, $w, 60), array('width' => $w . 'px'))),
    'a' => $node(array(), static fn(int $w): array => array(array(0, 0, 76, 15), array(
        'display' => 'inline', 'width' => 'auto', 'height' => 'auto', 'color' => 'rgb(200, 150, 50)', 'font-size' => '11px',
        'letter-spacing' => '3px', 'text-transform' => 'uppercase', 'text-decoration-line' => 'none', 'cursor' => 'pointer',
    ))),
    'h' => $node(array(), static fn(int $w): array => array(array(0, 15, $w, 40), array('width' => $w . 'px', 'height' => '40px', 'font-size' => '36px'))),
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

$dom = new DOMDocument();
@$dom->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>');
$anchor = null;
foreach ($dom->getElementsByTagName('a') as $candidate) {
    if ('Interior' === trim($candidate->textContent)) $anchor = $candidate;
}
$assert($anchor instanceof DOMElement, 'The eyebrow link survives.');
$carrier = $anchor->parentNode;
$classes = $carrier instanceof DOMElement ? preg_split('/\s+/', $carrier->getAttribute('class')) : array();
$assert($carrier instanceof DOMElement && 'p' === $carrier->tagName && in_array('blocks-engine-inline-anchor-carrier', $classes, true), 'The link is carried by an inline anchor carrier paragraph.');

/**
 * The display winning on $element at $width among $layered (true: the
 * blocks-engine-reset layer) or unlayered rules; unlayered rules beat layered.
 */
$display = static function (DOMElement $element, int $width, bool $layered) use ($css): ?string {
    $winner = null;
    $rank = array(-1, -1);
    $order = 0;
    (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $ancestors) use ($element, $width, $layered, &$winner, &$rank, &$order): void {
        $order++;
        $inLayer = false;
        foreach ($ancestors as $ancestor) {
            $ancestor = trim((string) $ancestor);
            if (preg_match('/^@layer\b/i', $ancestor)) { $inLayer = true; continue; }
            if (!preg_match('/^@media\s*\((max|min)-width:\s*(\d+(?:\.\d+)?)px\)$/i', $ancestor, $m)) return;
            if (('max' === strtolower($m[1])) !== ($width <= (float) $m[2])) return;
        }
        if ($inLayer !== $layered) return;
        $value = SourceDeclarations::parse($body)['display'] ?? null;
        if (null === $value) return;
        foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
            $parsed = CssSelectorMatcher::parse($arm);
            if (!CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
            $specificity = CssSelectorMatcher::specificity($parsed);
            if (array($specificity, $order) >= $rank) {
                $rank = array($specificity, $order);
                $winner = trim($value);
            }
        }
    });
    return $winner;
};

foreach ($widths as $key => $width) {
    $resolved = $display($carrier, $width, false) ?? $display($carrier, $width, true);
    $assert('inline' === $resolved, "$key: the inline anchor carrier keeps a focusable inline box (resolved display: " . var_export($resolved, true) . ')');
}

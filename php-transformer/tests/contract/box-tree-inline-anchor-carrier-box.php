<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #5901: a product page's category link (`<a class="eyebrow">Interior</a>`)
// sits inline in block flow before the product's <h1>. It lowers to a
// synthetic paragraph marked as an inline anchor carrier: that paragraph is
// the RichText root, and only a paragraph with a box can be hit and focused.
// The box-tree carrier restating the link's captured styles made the
// paragraph boxless again (`display:contents` at carrier specificity), so a
// real click on the link selected the enclosing group in the editor.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . 'body{margin:0}.eyebrow{font-size:11px;letter-spacing:.3em;text-transform:uppercase;text-decoration:none}h1{margin:16px 0 0}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m"><div data-blocks-engine-node-id="d">'
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
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4'), static fn(int $w): array => array(array(0, 0, $w, 900), array('width' => $w . 'px', 'height' => '900px'))),
        'n2' => $node(array(), static fn(int $w): array => array(array(0, 0, 0, 0), array('display' => 'none'))),
        'n4' => $node(array('m'), static fn(int $w): array => array(array(0, 0, $w, 74), array('width' => $w . 'px'))),
        'm' => $node(array('d'), static fn(int $w): array => array(array(0, 0, $w, 74), array())),
        'd' => $node(array('a', 'h'), static fn(int $w): array => array(array(0, 0, $w, 74), array())),
        'a' => $node(array(), static fn(int $w): array => array(array(0, 4, 76, 15), array('display' => 'inline', 'font-size' => '11px', 'letter-spacing' => '3.3px', 'text-transform' => 'uppercase', 'color' => 'rgb(200, 150, 80)', 'text-decoration-line' => 'none', 'width' => 'auto', 'height' => 'auto'))),
        'h' => $node(array(), static fn(int $w): array => array(array(0, 40, $w, 34), array('margin-top' => '16px', 'font-size' => '32px'))),
    ),
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
$assert($anchor instanceof DOMElement, 'The category link survives.');
$carrier = $anchor->parentNode;
$assert($carrier instanceof DOMElement && 'p' === $carrier->tagName && in_array('blocks-engine-inline-anchor-carrier', preg_split('/\s+/', $carrier->getAttribute('class')) ?: array(), true), 'The link lowers to an inline anchor carrier paragraph.');

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
        $value = SourceDeclarations::parse($body)[$property] ?? null;
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
    return SourceDeclarations::parse($element->getAttribute('style'))[$property] ?? $winner;
};

foreach ($widths as $key => $width) {
    $assert('inline' === $cascade($carrier, 'display', $width), "$key: the inline anchor carrier keeps an inline box the editor can hit and focus");
    $assert('inline' === $cascade($anchor, 'display', $width), "$key: the link keeps its captured inline display");
}

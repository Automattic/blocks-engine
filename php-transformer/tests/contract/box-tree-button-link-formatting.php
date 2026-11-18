<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

// A brand lockup link is a flex container: its mark and wordmark are two flex
// items, which a browser lays out (and reads, in innerText) as separate boxes.
// core/button saves the link inside a `.wp-block-button` wrapper, and the
// box-tree carrier lands on that wrapper. Unless the link restates the
// observed formatting context, core leaves it inline-block and the two spans
// collapse into one run of text: "IMBCatering" instead of "IMB Catering".
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$base = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$template = $base['nodes']['n1'];

$authorCss = '.bar{display:flex;justify-content:space-between;align-items:center}.menu{display:flex;gap:2rem}.brand{display:flex;align-items:center;gap:.625rem}.mark{display:flex;width:2.75rem;height:2.75rem;border:1px solid #c9a227;border-radius:9999px;padding:2px}.logo{font-family:serif;font-size:13px;color:#c9a227}.word{font-size:1.125rem;font-weight:800}a{color:inherit;text-decoration:inherit}';
$compile = static function (string $mobileDisplay) use ($base, $template, $authorCss): array {
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Brand</title><link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"></head>'
        . '<body data-blocks-engine-node-id="body"><header data-blocks-engine-node-id="header"><div class="bar" data-blocks-engine-node-id="bar">'
        . '<a href="/" class="brand" data-blocks-engine-node-id="brand"><span class="mark" data-blocks-engine-node-id="mark"><span class="logo" data-blocks-engine-node-id="logo">IMB</span></span><span class="word" data-blocks-engine-node-id="word">Catering</span></a>'
        . '<nav class="menu" data-blocks-engine-node-id="menu"><a href="/about" data-blocks-engine-node-id="about">About</a><a href="/menu" data-blocks-engine-node-id="food">Menu</a></nav></div></header></body></html>';
    $styles = array(
        'brand' => array('display' => 'flex', 'flex-direction' => 'row', 'flex-wrap' => 'nowrap', 'justify-content' => 'normal', 'align-items' => 'center', 'row-gap' => '10px', 'column-gap' => '10px', 'width' => '129px', 'height' => '44px'),
        'mark' => array('display' => 'flex', 'width' => '44px', 'height' => '44px'),
        'logo' => array('display' => 'block'),
        'word' => array('display' => 'block'),
    );
    $tree = $base;
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    foreach (HtmlSerialization::parseDocument($html)->getElementsByTagName('*') as $element) {
        $id = $element->getAttribute('data-blocks-engine-node-id');
        $node = $template;
        $node['children'] = array();
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) $node['children'][] = $child->getAttribute('data-blocks-engine-node-id');
        }
        foreach ($node['viewports'] as $viewport => &$observation) {
            $observation['used_styles']['mix-blend-mode'] = 'normal';
            $observation['used_styles'] = array_replace($observation['used_styles'], $styles[$id] ?? array());
            if ('brand' === $id && 'mobile' === $viewport) $observation['used_styles']['display'] = $mobileDisplay;
        }
        unset($observation);
        $tree['nodes'][$id] = $node;
    }
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'author.css' => $authorCss, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column(array_filter($compiled['assets'], static fn(array $a): bool => 'css' === ($a['kind'] ?? null)), 'content'));
    return array($compiled['serialized_blocks'], $css);
};

[$blocks, $css] = $compile('flex');
$assert(str_contains($blocks, '<!-- wp:button') && str_contains($blocks, '>IMB</span>') && str_contains($blocks, '>Catering</span>'), 'The brand link becomes a core/button holding both label spans.');
$assert(1 === preg_match('/class="wp-block-button [^"]*\b(be-inline-geometry-[a-f0-9]{16})\b/', $blocks, $carrier), 'The core/button wrapper carries the box-tree carrier.');
$link = '.' . $carrier[1] . '.' . $carrier[1] . '>.wp-block-button__link{';
$at = strpos($css, $link);
$assert(false !== $at, 'The carrier restates the observed formatting context on the link that holds the label.');
$rule = substr($css, $at, (int) strpos($css, '}', $at) - $at);
$assert(str_contains($rule, 'display:flex') && str_contains($rule, 'column-gap:10px') && str_contains($rule, 'align-items:center'), 'The link rule lays the mark and wordmark out as gapped flex items: ' . $rule);
$assert(!str_contains($rule, 'width:') && !str_contains($rule, 'height:'), 'The wrapper keeps the source box size; the link only restates the formatting context: ' . $rule);

// A control that is not a flex or grid container at every rendered viewport
// keeps core's link display there rather than a stale wider value.
[$blocks, $css] = $compile('block');
$assert(!str_contains($css, '>.wp-block-button__link{display:flex'), 'A control that stops being a flex container does not freeze flex onto the link.');
[$blocks, $css] = $compile('none');
$assert(str_contains($css, '>.wp-block-button__link{display:flex'), 'A control hidden at a viewport still restates its formatting context where it renders.');

echo "Box-tree button link formatting passed\n";

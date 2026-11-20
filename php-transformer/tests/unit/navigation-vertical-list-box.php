<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// Reduced page for #6887: the list, not the landmark, owns the painted box.
$source = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-vertical-list-box.html');
// Type-qualified author selectors must paint the native root too, rather
// than relying on Core copying the list class onto both nav and ul.
foreach (array($source, str_replace('.list{', 'ul.list{', $source)) as $page) {
    $result = (new HtmlTransformer())->transform($page)->toArray();
    $markup = $result['serialized_blocks'];
    $native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup);
    $css = file_get_contents(dirname(__DIR__) . '/fixtures/core-navigation-style.css') . "\n"
        . implode("\n", array_column($result['assets'], 'content'));
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($native);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $cascade = new StaticCssCascade($dom, $css);
    $nav = $dom->getElementsByTagName('nav')->item(0);
    if (!$nav instanceof DOMElement) throw new RuntimeException('Missing native navigation');
    $values = $cascade->resolve($nav, array('border', 'border-radius', 'background', 'width'), array());
    foreach (array('border' => '1px solid rgba(255,222,22,.75)', 'border-radius' => '11px', 'background' => 'rgba(204,180,28,.07)', 'width' => '284px') as $property => $expected) {
        if ($expected !== ($values[$property] ?? '')) throw new RuntimeException('Rendered nav lost ' . $property);
    }
    if (!str_contains($markup, '"orientation":"vertical","flexWrap":"nowrap"')) throw new RuntimeException('Block list must stay vertical and non-wrapping');
    if (20 !== $dom->getElementsByTagName('a')->length) throw new RuntimeException('All twenty links must remain editable');
    foreach ($dom->getElementsByTagName('a') as $anchor) {
        $values = $cascade->resolve($anchor, array('padding'), array());
        if ('3px 13px' !== ($values['padding'] ?? '')) throw new RuntimeException('Link lost author padding');
    }
}
if (isset($argv[1])) {
    // Model the layout CSS WordPress generates from the asserted block attrs.
    $css .= "\n.wp-block-navigation{display:flex;flex-direction:column;flex-wrap:nowrap;align-items:center}";
    file_put_contents($argv[1], json_encode(array('source' => $source, 'native' => $native, 'css' => $css), JSON_THROW_ON_ERROR));
}
echo "Vertical list navigation keeps its painted box and padded links\n";

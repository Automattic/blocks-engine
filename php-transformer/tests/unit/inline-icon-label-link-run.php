<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$icon = '<svg viewBox="0 0 16 16"><rect x="1" y="3" width="14" height="10" fill="none" stroke="currentColor"/></svg>';
$css = '<style>body{margin:0;font-family:sans-serif;color:#2a493b}main{padding:24px}.row svg{width:1em;height:1em;margin-right:4px;vertical-align:-2px}.row span{font-weight:600;margin-right:1rem}</style>';
$source = '<!doctype html><html><head><meta charset="utf-8"><title>Icon row</title>' . $css . '</head><body><main><h2>For more information</h2>'
    . '<div class="row">' . $icon . '<span>PHONE</span> <a href="tel:+46403210">+46 40 32 10</a></div>'
    . '<div class="row">' . $icon . '<span>E-MAIL</span> <a href="mailto:a@example.com">a@example.com</a></div>'
    . '</main></body></html>';
$flatten = static function (array $blocks) use (&$flatten): array {
    $all = array();
    foreach ( $blocks as $block ) {
        $all[] = $block;
        $all = array_merge($all, $flatten($block['innerBlocks'] ?? array()));
    }
    return $all;
};
$result = (new HtmlTransformer())->transform($source)->toArray();
$blocks = $flatten($result['blocks']);
$paragraphs = array_values(array_filter($blocks, static fn (array $block): bool => 'core/paragraph' === $block['blockName']));
$failures = array();
if ( 2 !== count($paragraphs) ) {
    $failures[] = 'Each contact row must be one paragraph.';
}
foreach ( array('tel:+46403210', 'mailto:a@example.com') as $index => $href ) {
    $content = $paragraphs[$index]['attrs']['content'] ?? '';
    if ( ! preg_match('/<img\b[^>]*>.*<(?:span|mark)\b[^>]*>.*<\/(?:span|mark)> <a\b/s', $content)
        || ! str_contains($content, 'href="' . $href . '"') ) {
        $failures[] = 'The inline image, editable label, separating space and link must share a RichText run: ' . $content;
    }
}
foreach ( $blocks as $block ) {
    if ( in_array($block['blockName'], array('core/image', 'core/html'), true) ) {
        $failures[] = 'Contact rows must not split off artwork or become HTML islands.';
    }
}
if ( 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
    $failures[] = 'Contact rows must save as valid native blocks.';
}

// Artwork alone remains media; block-level artwork and real block children
// must not be folded into the new phrasing run.
foreach ( array(
    '<div>' . $icon . '</div>',
    '<style>svg{display:block}</style><div>' . $icon . '<span>PHONE</span> <a href="tel:123">123</a></div>',
    '<div>' . $icon . '<p>PHONE</p><p>123</p></div>',
) as $source ) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    foreach ( $flatten($result['blocks']) as $block ) {
        if ( 'core/paragraph' === $block['blockName'] && str_contains($block['attrs']['content'] ?? '', '<img') && str_contains($block['attrs']['content'] ?? '', 'PHONE') ) {
            $failures[] = 'Non-phrasing artwork must retain its separate media block.';
        }
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Inline icon label link runs passed.\n";

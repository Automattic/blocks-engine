<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// One captured box is one rendered box (#6448). A source <button> becomes a
// core/buttons wrapper around a core/button, both made from the same source
// node. Restyling both from its box-tree evidence nested the box in itself:
// the Wix skip link's `position:absolute; left:50%` (720px at 1440) applied
// once to the wrapper and again to the button inside it, so the button sat
// at 1440px and the page scrolled sideways at every width.
// Evidence: n5 <main> (positioned container), n6 the <button>.
$html = str_replace(
    '<h1 data-blocks-engine-node-id="n6">Hello</h1>',
    '<button class="skip" id="skip" data-blocks-engine-node-id="n6">Skip to content</button>',
    (string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html')
);
$tree = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n5']['viewports']['desktop']['used_styles']['position'] = 'relative';
foreach (array('desktop' => 720, 'mobile' => 195) as $viewport => $left) {
    $tree['nodes']['n6']['viewports'][$viewport]['border_box'] = array('x' => $left, 'y' => 60, 'width' => 48, 'height' => 0);
    $tree['nodes']['n6']['viewports'][$viewport]['used_styles'] = array_replace($tree['nodes']['n6']['viewports'][$viewport]['used_styles'], array(
        'display' => 'block', 'position' => 'absolute', 'box-sizing' => 'border-box', 'width' => '48px',
        'top' => '60px', 'left' => $left . 'px', 'z-index' => '9999', 'opacity' => '0',
        'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    ));
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$markup = '';
array_walk_recursive($result, static function (mixed $value) use (&$markup): void {
    if (is_string($value) && str_contains($value, '<!-- wp:buttons')) $markup .= $value;
});

function single_box_assert(bool $condition, string $message, string $detail): void
{
    if (!$condition) throw new RuntimeException($message . "\n" . $detail);
    echo 'PASS: ' . $message . "\n";
}

single_box_assert('' !== $markup && str_contains($markup, 'wp-block-button '), 'The source button converts to core/buttons around core/button', $markup);
preg_match_all('/\.(be-inline-geometry-[a-f0-9]+)\.\1\{[^}]*left:720px/', $css, $offsets);
$offsetCarriers = array_values(array_unique($offsets[1]));
single_box_assert(1 === count($offsetCarriers), 'Exactly one carrier restates the captured offset', $css);
preg_match('/<div[^>]*class="wp-block-buttons[^"]*"/', $markup, $wrapper);
single_box_assert(isset($wrapper[0]) && !str_contains($wrapper[0], 'be-inline-geometry-'), 'The core/buttons wrapper does not restate the box its button carries', $markup);
single_box_assert((bool) preg_match('/<div[^>]*class="wp-block-button [^"]*' . preg_quote($offsetCarriers[0], '/') . '/', $markup), 'The core/button carries the captured box', $markup);
echo "Box-tree single box carrier contract passed\n";

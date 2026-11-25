<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$classes = static fn (array $block): array => preg_split('/\s+/', trim((string) ($block['attrs']['className'] ?? ''))) ?: array();

// A paint-free layer pinned over a hero, holding a fill image and a gradient
// overlay, is the image's figure followed by the overlay: no Group remains
// that an editor has to click through to reach the image.
$css = '<style>.relative{position:relative}.absolute{position:absolute}.inset-0{inset:0}.overflow-hidden{overflow:hidden}'
    . '.bg-primary{background-color:#123}.h-full{height:100%}.w-full{width:100%}.object-cover{object-fit:cover}.opacity-30{opacity:.3}'
    . '.bg-gradient{background-image:linear-gradient(to right,#123,transparent)}.py-20{padding-top:5rem;padding-bottom:5rem}</style>';
$hero = static fn (string $layer, string $image = '<img src="https://example.com/hero.jpg" alt="" class="h-full w-full object-cover opacity-30">', string $overlay = '<div class="absolute inset-0 bg-gradient"></div>'): string =>
    $css . '<section class="relative overflow-hidden bg-primary"><div class="' . $layer . '">' . $image . $overlay . '</div>'
    . '<div class="relative py-20"><h1>Frequently Asked Questions</h1><p>Everything you need to know.</p></div></section>';

$result = $transform($hero('absolute inset-0'));
$section = $result['blocks'][0];
$names = array_map(static fn (array $block): string => (string) $block['blockName'], $section['innerBlocks']);
$assert(array('core/image', 'core/group', 'core/group') === $names, 'The layer lowers as the image figure beside the overlay and content: ' . implode(', ', $names));
$figure = $section['innerBlocks'][0];
$assert(in_array('absolute', $classes($figure), true) && in_array('inset-0', $classes($figure), true), 'The figure takes the layer box.');
$assert(!in_array('blocks-engine-layer-fill-image', $classes($figure), true), 'No host Group remains for the editor to put in flow.');
$assert(1 === preg_match('/<figure class="wp-block-image[^"]*\babsolute inset-0\b[^"]*"/', $result['serialized_blocks']) && !str_contains($result['serialized_blocks'], 'blocks-engine-layer-fill-image'), 'The saved figure markup carries the layer box too.');
$assert(in_array('bg-gradient', $classes($section['innerBlocks'][1]), true), 'The overlay follows the figure, pinned to the same box.');
$assert(str_contains($result['serialized_blocks'], 'opacity') || str_contains(implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content')), 'opacity:.3'), 'The image keeps its own rules.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'The spliced layer remains editor-valid.');

// Anything that gives the layer a box, paint or stacking of its own, or an
// image or overlay that does not fill it, keeps the layer Group.
$css .= '<style>.z-10{z-index:10}.p-4{padding:1rem}.layer-img > img{border-radius:8px}</style>';
foreach (array(
    'painted layer' => $hero('absolute inset-0 bg-primary'),
    'stacking layer' => $hero('absolute inset-0 z-10'),
    'padded layer' => $hero('absolute inset-0 p-4'),
    'partial layer' => '<style>.top-0{top:0}</style>' . $hero('absolute top-0'),
    'in-flow layer' => $hero('relative'),
    'image not filling' => $hero('absolute inset-0', '<img src="https://example.com/hero.jpg" alt="" class="w-full object-cover">'),
    'overlay not pinned' => $hero('absolute inset-0', '<img src="https://example.com/hero.jpg" alt="" class="h-full w-full object-cover">', '<div class="bg-gradient"><p>Caption</p></div>'),
    'structural selector' => $hero('absolute inset-0 layer-img'),
) as $label => $html) {
    $kept = $transform($css . $html)['blocks'][0]['innerBlocks'][0] ?? array();
    $assert('core/group' === ($kept['blockName'] ?? null), 'An unsafe layer keeps its own Group: ' . $label);
}

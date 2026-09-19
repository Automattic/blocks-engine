<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transformer = new HtmlTransformer();

$spacing = $transformer->transform(
    '<div class="mt-1.5">x</div>',
    array( 'static_css' => '.mt-1\\.5{margin-top:0.375rem}' )
)->toArray();
$assert(
    str_contains((string) ($spacing['serialized_blocks'] ?? ''), 'class="wp-block-group mt-1.5"')
        || str_contains((string) ($spacing['serialized_blocks'] ?? ''), 'class="mt-1.5'),
    'a decimal class token is emitted on the block',
    (string) ($spacing['serialized_blocks'] ?? '')
);
$projectedSpacing = (string) ( $spacing['source_reports']['author_stylesheet_projections'][0]['content'] ?? '' );
$assert(
    str_contains($projectedSpacing, '.mt-1\\.5') && str_contains($projectedSpacing, 'margin-top:0.375rem'),
    'the declaration behind an escaped class selector is projected',
    $projectedSpacing
);

$gridCss = '.grid{display:grid}.gap-5{gap:1.25rem}@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
$gridHtml = '<form method="post" action="#" class="grid gap-5 sm:grid-cols-2"><input name="a"><input name="b"><button type="submit">Send</button></form>';
$grid = $transformer->transform($gridHtml, array( 'static_css' => $gridCss ))->toArray();
$gridFallback = $grid['fallbacks'][0] ?? array();
$gridNode = array_column($gridFallback['layout_graph']['nodes'] ?? array(), null, 'id')['form'] ?? array();
$gridVariant = $gridFallback['layout_graph']['variants'][0] ?? array();
$assert(
    array( 'grid', 'gap-5', 'sm:grid-cols-2' ) === ($gridNode['source']['classes'] ?? null),
    'layout-graph source classes retain a colon class token',
    json_encode($gridNode['source']['classes'] ?? null)
);
$assert(
    'media' === ($gridVariant['condition']['kind'] ?? null)
        && str_contains((string) ($gridVariant['condition']['query'] ?? ''), 'width')
        && isset($gridVariant['layout_patch']['columns']),
    'a media-scoped layout rule reaches layout_graph as a variant',
    json_encode($gridVariant)
);

$layeredCss = '@layer utilities{.grid{display:grid}.gap-5{gap:1.25rem}.mt-1\\.5{margin-top:0.375rem}@media (width>=40rem){.sm\\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}}';
$layeredHtml = '<form method="post" action="#"><div class="grid gap-5 sm:grid-cols-2"><label class="block"><span>Message</span><textarea name="m" class="mt-1.5 w-full"></textarea></label></div><button type="submit">Send</button></form>';
$layered = $transformer->transform($layeredHtml, array( 'static_css' => $layeredCss ))->toArray();
$layeredFallback = $layered['fallbacks'][0] ?? array();
$layeredNodes = array_column($layeredFallback['layout_graph']['nodes'] ?? array(), null, 'id');
$layeredWrapper = $layeredNodes['wrapper-0'] ?? array();
$layeredVariants = array_values(array_filter(
    $layeredFallback['layout_graph']['variants'] ?? array(),
    static fn (array $variant): bool => 'wrapper-0' === ($variant['node'] ?? null)
));
$assert(
    array( 'grid', 'gap-5', 'sm:grid-cols-2' ) === ($layeredWrapper['source']['classes'] ?? null),
    'layout-graph source classes retain a colon class token on the layout wrapper',
    json_encode($layeredWrapper['source']['classes'] ?? null)
);
$assert(
    str_contains((string) ($layeredFallback['controls'][0]['class'] ?? ''), 'mt-1.5'),
    'control metadata retains a decimal class token',
    json_encode($layeredFallback['controls'][0]['class'] ?? null)
);
$assert(
    array() !== $layeredVariants
        && 'media' === ($layeredVariants[0]['condition']['kind'] ?? null)
        && isset($layeredVariants[0]['layout_patch']['columns']),
    'a layered media-scoped layout rule still reaches layout_graph as a variant',
    json_encode(array( 'wrapper' => $layeredWrapper, 'variants' => $layeredVariants ))
);
$assert(
    'flex' !== ($layeredWrapper['layout']['display'] ?? null) && 'grid' !== ($layeredWrapper['layout']['display'] ?? null),
    'layered unconditional display is not emitted as unlayered base structure',
    json_encode($layeredWrapper['layout'] ?? null)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "escaped class token fidelity: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "escaped class token fidelity: {$passes} passed\n");

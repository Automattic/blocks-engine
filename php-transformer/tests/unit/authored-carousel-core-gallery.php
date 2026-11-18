<?php
declare(strict_types=1);

// A site import publishes an authored carousel of images as a core Gallery an
// editor changes with core tools, not as the generated carousel block. The
// theme runtime renders the carousel's navigable stage around the gallery's
// images, so the front end keeps the source slideshow (issue #6451).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\AuthoredCarouselGalleryContract;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) $failures[] = $message;
};
$html = '<!DOCTYPE html><html><head></head><body><div><div class="sqs-gallery sqs-gallery-design-stacked">'
    . '<div class="slide content-fill sqs-gallery-design-stacked-slide loaded sqs-active-slide"><img src="/media/one.webp"></div>'
    . '<div class="slide content-fill sqs-gallery-design-stacked-slide loaded"><img src="/media/two.webp"></div>'
    . '</div><a aria-label="Next Slide"></a></div></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$markup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
$flatten = static function (array $blocks) use (&$flatten): array {
    $all = array();
    foreach ($blocks as $block) $all = array_merge($all, array($block), $flatten($block['innerBlocks'] ?? array()));
    return $all;
};
$tree = $flatten((new Runtime())->parseBlocks($markup));
$names = array_values(array_unique(array_filter(array_column($tree, 'blockName'))));
$assert(array() === array_filter($names, static fn (string $name): bool => ! str_starts_with($name, 'core/')), 'the imported page is built from core blocks only: ' . json_encode($names));
$galleries = array_values(array_filter($tree, static fn (array $block): bool => null !== AuthoredCarouselGalleryContract::carouselAttributes($block)));
$assert(1 === count($galleries) && 'core/gallery' === ($galleries[0]['blockName'] ?? null), 'the slideshow is one core gallery recording its carousel stage');
$assert(array('core/image', 'core/image') === array_column($galleries[0]['innerBlocks'] ?? array(), 'blockName'), 'each slide stays an editable core image');
$attributes = AuthoredCarouselGalleryContract::carouselAttributes($galleries[0] ?? array()) ?? array();
$assert(2 === ($attributes['slideCount'] ?? null), 'the recorded carousel keeps both slides');
$rendered = AuthoredCarouselGalleryContract::renderMarkup($markup);
$assert(str_contains($rendered, 'data-wp-interactive="blocks-engine/carousel"') && str_contains($rendered, 'blocks-engine-authored-carousel__track'), 'the front end renders the navigable carousel stage');
$assert(2 === substr_count($rendered, 'class="wp-block-image ') && ! str_contains($rendered, 'wp-block-gallery'), 'the stage holds the gallery images in place of the gallery figure');
$assert(str_contains(json_encode($plan), 'render_block_core\/gallery'), 'the theme runtime renders the recorded carousel stage');
$blockTypes = array_map(static fn (array $block): string => (string) ($block['block_json']['name'] ?? ''), $result['source_reports']['companion_plugin_payload']['blocks'] ?? array());
$assert(array() !== array_filter($blockTypes, static fn (string $name): bool => str_ends_with($name, '/' . AuthoredCarouselGalleryContract::LOCAL_NAME)), 'the carousel behavior and styles still ship for the runtime to load');
if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Authored image carousel publishes as a core gallery\n";

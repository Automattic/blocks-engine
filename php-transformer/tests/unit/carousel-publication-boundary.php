<?php
declare(strict_types=1);

// Core publication must not hide the stage from site-wide style/shell planning.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\AuthoredCarouselGalleryContract;

$html = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><section class="slideshow"><div class="slides"><div class="slide is-active"><h2>First story</h2></div><div class="slide"><h2>Second story</h2></div></div><button aria-label="Previous slide"></button><button aria-label="Next slide"></button></section></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'other.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><h2>Unrelated page</h2></main></body></html>',
    'site.css' => '.slideshow{position:relative}.slides{min-height:50vh}.slide h2{color:#123456}main h2{color:#654321}',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$original = (string) $result['serialized_blocks'];
if (!str_contains($original, '/authored-carousel') || str_contains($original, '"blocksEngineCarousel"')) {
    throw new RuntimeException('Compilation must retain the stage DOM until site-wide planning finishes.');
}
$hosts = array_filter($plan['pages'], static fn (array $page): bool => str_contains($page['canonical_block_markup'], '"blocksEngineCarousel"'));
if (1 !== count($hosts)) throw new RuntimeException('Only the carousel page should publish a core host.');
$markup = array_values($hosts)[0]['canonical_block_markup'];
if (str_contains($markup, '/authored-carousel')) throw new RuntimeException('Published page must use core blocks.');
$rendered = AuthoredCarouselGalleryContract::renderMarkup($markup);
if (!str_contains($rendered, '--blocks-engine-carousel-stage-min-height:50vh') || !str_contains($rendered, 'First story') || !str_contains($rendered, 'Second story')) {
    throw new RuntimeException('Publication must preserve the planned stage geometry and editable content.');
}
echo "Carousel publication retains stage DOM through site planning\n";

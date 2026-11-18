<?php
declare(strict_types=1);

// A site import publishes an authored slideshow whose slides pair an image
// with text or a link as a core Group of those slides, not as the generated
// carousel block (issue #6451). The editor lays the stacked slides out in
// flow, and the theme runtime renders the navigable stage around them.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\AuthoredCarouselGalleryContract;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) $failures[] = $message;
};
$slide = static fn (string $n, string $active): string => '<div class="slide' . $active . '"><img src="/media/' . $n . '.webp" alt="Slide ' . $n . '"><h3>Slide ' . $n . '</h3><a href="/take-action">Join us</a></div>';
$html = '<!DOCTYPE html><html><head></head><body><div class="slideshow"><div class="slides">' . $slide('one', ' is-active') . $slide('two', '')
    . '</div><button aria-label="Previous slide"></button><button aria-label="Next slide"></button></div></body></html>';
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
$hosts = array_values(array_filter($tree, static fn (array $block): bool => null !== AuthoredCarouselGalleryContract::carouselAttributes($block)));
$host = $hosts[0] ?? array();
$assert(1 === count($hosts) && 'core/group' === ($host['blockName'] ?? null), 'the slideshow is one core group recording its carousel stage');
$assert(array('core/group', 'core/group') === array_column($host['innerBlocks'] ?? array(), 'blockName'), 'each slide stays an editable core group');
$attributes = AuthoredCarouselGalleryContract::carouselAttributes($host) ?? array();
$assert('slideshow' === ($attributes['presentation'] ?? null) && 2 === ($attributes['slideCount'] ?? null), 'the recorded slideshow keeps both slides');
$assert(str_contains((string) ($host['attrs']['className'] ?? ''), AuthoredCarouselGalleryContract::EDITOR_HOST_CLASS), 'the editor host is marked for the in-flow slide layout');
$editorCss = '';
foreach ($result['source_reports']['companion_plugin_payload']['blocks'] ?? array() as $block) {
    if (str_ends_with((string) ($block['block_json']['name'] ?? ''), '/' . AuthoredCarouselGalleryContract::LOCAL_NAME)) $editorCss .= (string) ($block['assets']['editor.css'] ?? '');
}
$assert(str_contains($editorCss, '.' . AuthoredCarouselGalleryContract::EDITOR_HOST_CLASS . '>.wp-block{position:relative!important'), 'the carousel editor style lays the host slides out in flow');
$rendered = AuthoredCarouselGalleryContract::renderMarkup($markup);
$assert(str_contains($rendered, 'data-wp-interactive="blocks-engine/carousel"') && str_contains($rendered, 'blocks-engine-authored-carousel__track'), 'the front end renders the navigable carousel stage');
$assert(! str_contains($rendered, AuthoredCarouselGalleryContract::EDITOR_HOST_CLASS) && str_contains($rendered, 'Slide two') && str_contains($rendered, 'href="/take-action"'), 'the stage holds the slides in place of the group wrapper');
$assert(str_contains(json_encode($plan), 'render_block_core\/group'), 'the theme runtime renders a group host through its recorded stage');
if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Authored mixed-slide carousel publishes as a core group\n";

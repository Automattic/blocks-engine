<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html><html><head><title>Background carousel</title><style>
.slider{position:absolute;inset:0;min-height:100vh}
.slider-mask{height:900px;overflow:hidden}
.slide{width:1440px;height:900px}
.slide-a{background-image:linear-gradient(#ffffff12,#ffffff12),url("/media/a.avif");background-size:auto,cover}
.slide-b{background-image:linear-gradient(#000a7c0a,#000a7c0a),url("/media/b.avif");background-size:auto,cover}
</style></head><body><main>
<div class="slider" role="region" aria-label="carousel">
<div class="slider-mask">
<div class="slide-a slide" aria-label="1 of 2" role="group" style="opacity:1;z-index:2"></div>
<div class="slide-b slide" aria-label="2 of 2" role="group" aria-hidden="true"></div>
<div aria-live="off" aria-atomic="true" class="slider-status">Slide 1 of 2.</div>
</div>
<div class="arrow-prev" role="button" tabindex="0" aria-label="previous slide"></div>
<div class="arrow-next" role="button" tabindex="0" aria-label="next slide"></div>
</div></main></body></html>
HTML;
$find = static function (array $blocks, string $name) use (&$find): array {
    $found = array();
    foreach ($blocks as $block) {
        if (str_ends_with($block['blockName'] ?? '', $name)) $found[] = $block;
        $found = array_merge($found, $find($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$carousels = $find($result['blocks'] ?? array(), '/authored-carousel');
$assert(1 === count($carousels), 'one authored carousel');
$assert(array() === ($result['fallbacks'] ?? array()), 'no fallbacks');
if (1 === count($carousels)) {
    $carousel = $carousels[0];
    $assert(2 === count($carousel['innerBlocks']), 'two painted slides, not the live region');
    $assert(2 === ($carousel['attrs']['slideCount'] ?? null), 'runtime slide count excludes status');
    $assert('slideshow' === ($carousel['attrs']['presentation'] ?? null), 'one slide on stage');
    $assert(900 === ($carousel['attrs']['viewportHeight'] ?? null), 'stage retains captured height');
    $assert(false === ($carousel['attrs']['showStatus'] ?? null), 'runtime-only source status stays visually hidden');
    foreach (array('a', 'b') as $index => $image) {
        $slide = $carousel['innerBlocks'][$index] ?? array();
        $json = json_encode($slide, JSON_UNESCAPED_SLASHES);
        $assert(str_contains($json, '/media/' . $image . '.avif'), 'retains background ' . $image);
        $assert('core/cover' === ($slide['blockName'] ?? '') || ('core/group' === ($slide['blockName'] ?? '') && (str_contains($json, 'backgroundImage') || 1 === count($find(array($slide), 'core/image')))), 'background is owner-editable native media');
        $assert(str_contains($json, '900'), 'slide retains its painted height');
    }
}
$assert(!str_contains($result['serialized_blocks'] ?? '', 'Slide 1 of 2.'), 'source status is not visible content');
if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Background carousel slides tests passed\n";

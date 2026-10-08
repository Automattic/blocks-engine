<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$fixture = require dirname(__DIR__) . '/fixtures/structural-carousel.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};
$collect = static function (array $blocks) use (&$collect): array {
    $found = array();
    foreach ($blocks as $block) {
        if ('custom/authored-carousel' === ($block['blockName'] ?? null)) {
            $found[] = $block;
        }
        $found = array_merge($found, $collect($block['innerBlocks'] ?? array()));
    }
    return $found;
};
$source = $fixture();
$result = (new HtmlTransformer())->transform('<style>' . $source['css'] . '</style>' . $source['html'])->toArray();
$carousels = $collect($result['blocks']);
$assert(1 === count($carousels), 'homogeneous image slides and labelled role-button arrows compile to one carousel');
$carousel = $carousels[0];
$images = static function (array $blocks) use (&$images): array {
    $found = array();
    foreach ($blocks as $block) {
        if ('core/image' === ($block['blockName'] ?? null)) {
            $found[] = $block;
        }
        $found = array_merge($found, $images($block['innerBlocks'] ?? array()));
    }
    return $found;
};
$nativeImages = $images($carousel['innerBlocks']);
$assert(20 === count($carousel['innerBlocks']) && 20 === count($nativeImages), 'all twenty slides stay editable native images inside authored holders');
$assert(array_map(static fn (int $i): string => 'Frame ' . $i, range(0, 19)) === array_column(array_column($nativeImages, 'attrs'), 'alt'), 'native image source order survives');
$assert('slideshow' === $carousel['attrs']['presentation'] && 1 === $carousel['attrs']['itemsPerView'] && 7 === $carousel['attrs']['initialSlide'], 'resolved single-visible-slide geometry selects slideshow and preserves active index');
$assert(0 === $carousel['attrs']['autoplayInterval'] && !$carousel['attrs']['showDots'], 'unobserved playback and pagination are not invented');
$markup = $result['serialized_blocks'];
$assert(str_contains($markup, 'Gallery heading survives') && str_contains($markup, 'Following gallery copy survives.'), 'smallest carousel ownership preserves sibling heading and copy');
$assert(!str_contains($carousel['innerHTML'], 'Gallery heading survives') && 2 === substr_count($carousel['innerHTML'], '<svg'), 'source SVG arrow artwork belongs to two rebuilt native buttons');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'structural carousel is editor valid');
$roundTrip = (new Runtime())->parseBlocks((new Runtime())->serializeBlocks(array($carousel)))[0];
$assert($carousel['attrs'] === $roundTrip['attrs'] && (new Runtime())->serializeBlocks(array($carousel)) === (new Runtime())->serializeBlocks(array($roundTrip)), 'carousel attributes and native image markup survive serialization');

foreach (array($fixture('placeholder'), $fixture('frame-item', 'img'), $fixture('frame-item', 'button', 1)) as $negative) {
    $output = (new HtmlTransformer())->transform('<style>' . $negative['css'] . '</style>' . $negative['html'])->toArray();
    $assert(array() === $collect($output['blocks']), 'placeholder, decorative controls and singleton collections are not carousels');
}
$hiddenControls = (new HtmlTransformer())->transform('<style>' . $source['css'] . '</style>' . str_replace('class="frame-actions"', 'class="frame-actions" hidden', $source['html']))->toArray();
$assert(array() === $collect($hiddenControls['blocks']), 'a hidden arrow cluster cannot supply controls to an otherwise static collection');
foreach (array('class="expanded-gallery" role="dialog"', 'hidden', 'aria-hidden="true"') as $duplicateState) {
    $output = (new HtmlTransformer())->transform('<style>' . $source['css'] . '</style>' . str_replace('</div><div class="frame-actions">', '</div><div ' . $duplicateState . '><div class="frame-window">' . str_repeat('<div class="frame-item"><img src="duplicate.jpg"></div>', 30) . '</div></div><div class="frame-actions">', $source['html']))->toArray();
    $assert(20 === count($collect($output['blocks'])[0]['innerBlocks'] ?? array()), 'expanded or explicitly hidden duplicate list cannot replace the primary rail');
}
$transition = (new HtmlTransformer())->transform('<style>' . $source['css'] . '.frame-item.incoming{display:block}</style>' . preg_replace('/class="frame-item"/', 'class="frame-item incoming"', $source['html'], 1))->toArray();
$assert('slideshow' === ($collect($transition['blocks'])[0]['attrs']['presentation'] ?? '') && 7 === ($collect($transition['blocks'])[0]['attrs']['initialSlide'] ?? null), 'mid-transition incoming neighbour does not turn a single-active stage into a multi-item track');
$outsideArrows = str_replace('<div class="frame-carousel"', '<div class="frame-boundary"><div class="frame-carousel"', $source['html']);
$outsideArrows = str_replace('<div class="frame-actions">', '</div><div class="frame-actions">', $outsideArrows);
$outside = (new HtmlTransformer())->transform('<style>' . $source['css'] . '</style>' . $outsideArrows)->toArray();
$assert(1 === count($collect($outside['blocks'])) && str_contains($outside['serialized_blocks'], 'Gallery heading survives'), 'unlabelled smallest wrapper owns a labelled slide root and sibling arrows without claiming the section heading');
echo "Structural carousel regression passed\n";

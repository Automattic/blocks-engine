<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// An art-directed picture inside a device-only copy of a dual-device capture
// (issue #6741): the capture records the rendition the browser rendered, so
// the picture lowers to native core/image with that rendition.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$compile = static function (?string $current, string $documentClass): array {
    $attribute = null === $current ? '' : ' ' . CaptureEvidence::CURRENT_SOURCE . '="' . $current . '"';
    $html = '<main><div class="' . $documentClass . '"><wow-image><picture>'
        . '<source media="(min-width: 320px) and (max-width: 750px)" srcset="/media/IMG_7554_JPG-4.avif">'
        . '<source media="(min-width: 320px) and (max-width: 1000px)" srcset="/media/IMG_7554_JPG-2.jpg">'
        . '<img src="/media/IMG_7554_JPG-4.avif"' . $attribute . ' style="object-fit: cover;object-position: 49% 25%">'
        . '</picture></wow-image></div></main>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $images = array();
    $names = array();
    $walk = static function (array $block) use (&$walk, &$names, &$images): void {
        $names[] = (string) ($block['blockName'] ?? '');
        if ('core/image' === ($block['blockName'] ?? '')) $images[] = $block['attrs'] ?? array();
        foreach ($block['innerBlocks'] ?? array() as $child) $walk($child);
    };
    foreach ($result['blocks'] as $block) $walk($block);
    return array($names, $images, (string) $result['serialized_blocks']);
};
$responsive = static fn (array $names): bool => array() !== array_filter($names, static fn (string $name): bool => str_ends_with($name, '/responsive-media'));

foreach (array(CaptureEvidence::DESKTOP_DOCUMENT_CLASS, CaptureEvidence::MOBILE_DOCUMENT_CLASS) as $documentClass) {
    foreach (array('/media/IMG_7554_JPG-2.jpg', '/media/IMG_7554_JPG-4.avif') as $current) {
        [$names, $images, $markup] = $compile($current, $documentClass);
        $assert(!$responsive($names), $documentClass . ' ' . $current . ': captured rendition must not stay raw responsive media, got ' . implode(', ', $names));
        $assert(1 === count($images), $documentClass . ' ' . $current . ': expected one core/image');
        $assert($current === ($images[0]['url'] ?? null), $documentClass . ' ' . $current . ': core/image must use the rendered rendition');
        $assert(!isset($images[0]['metadata']['blocksEngineImageSelection']['sources']), $documentClass . ' ' . $current . ': resolved sources must not replay');
        $assert(!str_contains($markup, CaptureEvidence::CURRENT_SOURCE), $documentClass . ' ' . $current . ': capture evidence must not be emitted');
    }
    [$names, , $markup] = $compile(null, $documentClass);
    $assert($responsive($names), $documentClass . ': without captured rendition the art-directed picture stays responsive media');
}
echo "Picture captured current source passed\n";

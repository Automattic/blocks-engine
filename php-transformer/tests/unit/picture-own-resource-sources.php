<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Sources offering exactly the image's own file select nothing, whatever media
// or type they declare, so a hosted image stays a native core/image.
foreach (array('<source media="(max-width: 750px)" srcset="/media/a.avif">', '<source type="image/webp" srcset="/media/a.avif">') as $source) {
    $html = '<main><photo-frame data-image-info="{}"><picture>' . $source . '<img src="/media/a.avif" alt="A"></picture></photo-frame></main>';
    $names = array();
    $walk = static function (array $block) use (&$walk, &$names): void {
        $names[] = (string) ($block['blockName'] ?? '');
        foreach ($block['innerBlocks'] ?? array() as $child) $walk($child);
    };
    foreach ((new HtmlTransformer())->transform($html)->toArray()['blocks'] as $block) $walk($block);
    if (!in_array('core/image', $names, true) || array() !== array_filter($names, static fn(string $name): bool => str_ends_with($name, '/responsive-media'))) {
        throw new RuntimeException($source . ': expected a native image, got ' . implode(', ', $names));
    }
}
echo "Picture own-resource sources passed\n";

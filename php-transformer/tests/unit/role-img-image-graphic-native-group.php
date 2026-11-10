<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * An element with `role="img"` whose content is only images is one named
 * graphic, not phrasing content. Beside a sibling text span it used to be
 * lowered into the parent's RichText, where its images failed the inline image
 * gate and the whole parent became core/html. Inside a link tile the same
 * RichText rejection fell through to fallback reduction, which turned the tile
 * into a core/button whose label held the images.
 *
 * The graphic is now a core/group of core/image blocks carrying the accessible
 * name through the Group's ariaLabel support; the sibling text still converts.
 */

$collect = static function (array $blocks, string $name) use (&$collect): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        array_push($found, ...$collect($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};

$failures = array();
$mosaic = '<span class="mosaic" role="img" aria-label="Plates"><img src="a.png" alt=""><img src="b.png" alt=""></span>';

foreach ( array(
    'unnamed graphic beside text'      => array( '<main><div><span role="img"><img src="a.png" alt=""></span><span>04</span></div></main>', 1, '' ),
    'named single-image graphic'       => array( '<main><div><span role="img" aria-label="Plates"><img src="a.png" alt=""></span><span>04</span></div></main>', 1, 'Plates' ),
    'named mosaic beside text'         => array( '<main><div class="card-image">' . $mosaic . '<span>04</span></div></main>', 2, 'Plates' ),
    'named mosaic in a link tile'      => array( '<main><a class="tile" href="/x/">' . $mosaic . '<span class="overlay"></span><span class="index">04</span><span class="copy"><strong>Title</strong><small>Sub</small></span></a></main>', 2, 'Plates' ),
) as $name => [ $source, $imageCount, $label ] ) {
    $result = ( new HtmlTransformer() )->transform($source)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $blocks = $result['blocks'] ?? array();

    if ( str_contains($markup, '<!-- wp:html') ) {
        $failures[] = $name . ': emitted core/html: ' . $markup;
    }
    if ( array() !== $collect($blocks, 'core/button') ) {
        $failures[] = $name . ': emitted a core/button: ' . $markup;
    }
    if ( $imageCount !== count($collect($blocks, 'core/image')) ) {
        $failures[] = $name . ': expected ' . $imageCount . ' core/image blocks: ' . $markup;
    }

    $graphic = null;
    foreach ( $collect($blocks, 'core/group') as $group ) {
        $children = $group['innerBlocks'] ?? array();
        if ( array() !== $children && array() === array_filter($children, static fn (array $child): bool => 'core/image' !== ($child['blockName'] ?? '')) ) {
            $graphic = $group;
        }
    }
    if ( null === $graphic || $imageCount !== count($graphic['innerBlocks']) ) {
        $failures[] = $name . ': expected one core/group holding the graphic images: ' . $markup;
    } elseif ( $label !== (string) ($graphic['attrs']['ariaLabel'] ?? '') ) {
        $failures[] = $name . ': expected the group ariaLabel "' . $label . '": ' . $markup;
    } elseif ( '' !== $label && 1 !== preg_match('#<div class="wp-block-group[^"]*" aria-label="' . preg_quote($label, '#') . '">#', $markup) ) {
        $failures[] = $name . ': expected the saved group to carry aria-label: ' . $markup;
    }

    $texts = implode(' ', array_map(static fn (array $paragraph): string => strip_tags((string) ($paragraph['innerHTML'] ?? '')), $collect($blocks, 'core/paragraph')));
    if ( ! str_contains($texts, '04') ) {
        $failures[] = $name . ': lost the sibling "04" text: ' . $markup;
    }
    if ( 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = $name . ': block validity did not pass: ' . json_encode($result['source_reports']['wp_block_validity'] ?? null);
    }
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "role-img-image-graphic-native-group: ok\n";

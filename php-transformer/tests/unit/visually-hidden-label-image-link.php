<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$flatten = static function (array $blocks) use (&$flatten): array {
    $result = array();
    foreach ( $blocks as $block ) {
        $result[] = $block;
        array_push($result, ...$flatten($block['innerBlocks'] ?? array()));
    }
    return $result;
};
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

// The six rows of #3915, both with and without a destination.
$rows = array(
    array('', ''),
    array(' role="button"', ''),
    array(' style="overflow:hidden"', ''),
    array('', '<span class="v6-visually-hidden">View fullsize</span>'),
    array(' role="button"', '<span hidden>View fullsize</span>'),
    array(' role="button" aria-label="Photo"', '<span hidden>View fullsize</span>'),
);
foreach ( $rows as $index => [$attributes, $label] ) {
    foreach ( array(true, false) as $linked ) {
        $source = '<div><a' . ($linked ? ' href="/media/a.webp"' : '') . $attributes . '>' . $label . '<img alt="Original" src="/media/a.webp"></a></div>';
        $result = (new HtmlTransformer())->transform($source)->toArray();
        $blocks = $flatten($result['blocks'] ?? array());
        $images = array_values(array_filter($blocks, static fn (array $block): bool => 'core/image' === $block['blockName']));
        $context = 'row ' . ($index + 1) . ($linked ? ' linked' : ' unlinked');
        $check(1 === count($images), $context . ': one native image');
        $check(array() === array_intersect(array('core/html', 'core/button', 'core/paragraph'), array_column($blocks, 'blockName')), $context . ': no HTML, button or inline image');
        $check(($linked ? '/media/a.webp' : '') === ($images[0]['attrs']['href'] ?? ''), $context . ': destination');
        $check('Original' === ($images[0]['attrs']['alt'] ?? ''), $context . ': original alt');
        if ( $linked && 5 === $index ) {
            // core/image save() serializes only href/class/target/rel on its link,
            // so a link aria-label would make the block invalid in the editor.
            // The image alt is the link's accessible name.
            $check(!str_contains($images[0]['innerHTML'] ?? '', 'aria-label'), $context . ': link markup stays within core/image save()');
        }
    }
}

foreach ( array(
    '<span class="screen-reader-text">View fullsize</span>',
    '<span class="sr-only">View fullsize</span>',
    '<span style="display:none">View fullsize</span>',
    '<span style="visibility:hidden">View fullsize</span>',
    '<span style="position:absolute;clip:rect(0,0,0,0)">View fullsize</span>',
    '<span style="position:absolute;clip-path:inset(50%)">View fullsize</span>',
    '<span hidden>View fullsize</span>',
) as $label ) {
    foreach ( array('', ' href="/photo"') as $href ) {
        $result = (new HtmlTransformer())->transform('<a' . $href . ' role="button" aria-label="Photo">' . $label . '<img src="/photo.webp"></a>')->toArray();
        $images = array_values(array_filter($flatten($result['blocks'] ?? array()), static fn (array $block): bool => 'core/image' === $block['blockName']));
        $check('Photo' === ($images[0]['attrs']['alt'] ?? ''), 'accessible name fills missing alt: ' . $label . $href);
    }
}
$result = (new HtmlTransformer())->transform('<a href="/photo"><span class="sr-only">View fullsize</span><img src="/photo.webp"></a>')->toArray();
$check(str_contains($result['serialized_blocks'] ?? '', 'alt="View fullsize"'), 'screen-reader text fills missing alt');
foreach ( array('Visible caption') as $extra ) {
    $result = (new HtmlTransformer())->transform('<a href="/photo"><img src="/photo.webp">' . $extra . '</a>')->toArray();
    $check(!in_array('core/image', array_column($flatten($result['blocks'] ?? array()), 'blockName'), true), 'visible caption is not discarded as a hidden label');
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Visually hidden image link labels passed.\n";

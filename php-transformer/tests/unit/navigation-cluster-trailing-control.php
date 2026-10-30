<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-cluster-trailing-control.html');
$find = static function (array $blocks, string $name) use (&$find): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        array_push($found, ...$find($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

foreach ( array(
    'button' => $source,
    'signalled anchor' => str_replace('<button class="btn" type="button">Donate</button>', '<a href="/donate/" style="padding:8px;background:navy;color:white">Donate</a>', $source),
    'neutral classes' => str_replace(array('navbar', 'collapse', 'btn'), array('site-row', 'panel', 'action'), $source),
) as $case => $html ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $blocks = $result['blocks'];
    $images = $find($blocks, 'core/image');
    $menus = $find($blocks, 'core/navigation');
    $buttons = $find($blocks, 'core/button');
    $assert(1 === count($images), "$case: brand is an image");
    $brandClass = 'neutral classes' === $case ? 'site-row-brand' : 'navbar-brand';
    $assert(1 === preg_match('/<a[^>]*class="[^"]*' . $brandClass . '[^"]*"[^>]*>/', $result['serialized_blocks']), "$case: brand link keeps source classes");
    $assert(1 === count($menus) && 3 === count($find($menus, 'core/navigation-link')), "$case: list alone is navigation with three links");
    $assert(1 === count($buttons) && str_contains(json_encode($buttons[0]), 'Donate'), "$case: Donate is a button");
    $clusters = array_values(array_filter($find($blocks, 'core/group'), static fn (array $block): bool => str_contains($block['attrs']['className'] ?? '', 'neutral classes' === $case ? 'site-row-panel' : 'navbar-collapse')));
    $assert(1 === count($clusters), "$case: collapsible wrapper survives");
    $children = $clusters[0]['innerBlocks'];
    $assert('core/navigation' === $children[0]['blockName'] && 1 === count($find(array_slice($children, 1), 'core/button')), "$case: CTA stays after menu inside wrapper");
}

// Meaningful trailing content is not a control, and controls outside a
// brand carrier do not widen menu recognition.
foreach ( array(
    str_replace('<button class="btn" type="button">Donate</button>', '<p>Support our work</p>', $source),
    preg_replace('/<a class="navbar-brand".*?<\/a>/s', '', $source),
) as $html ) {
    if ( null === $html ) {
        continue;
    }
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $carriers = array_filter($find($result['blocks'], 'core/group'), static fn (array $block): bool => str_contains($block['attrs']['className'] ?? '', 'blocks-engine-brand-navigation-carrier'));
    $assert(array() === $carriers, 'ambiguous or non-carrier shape does not gain carrier treatment');
}

echo "Navigation cluster trailing control regression passed\n";

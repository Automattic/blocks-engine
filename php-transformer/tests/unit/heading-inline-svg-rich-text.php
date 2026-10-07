<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A heading whose text ends in an inline icon `<svg>` must take the same
 * RichText path as a paragraph: the icon becomes a materialized inline image
 * object and the heading stays a native core/heading, not a core/html island.
 */

$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5"><path d="M7 7h10v10"></path><path d="M7 17 17 7"></path></svg>';

$blockNames = static function (array $blocks): array {
    $names = array();
    $visit = static function (array $items) use (&$visit, &$names): void {
        foreach ( $items as $block ) {
            $names[] = (string) ($block['blockName'] ?? '');
            $visit($block['innerBlocks'] ?? array());
        }
    };
    $visit($blocks);
    return $names;
};
$findBlock = static function (array $blocks, string $name) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $nested = $findBlock($block['innerBlocks'] ?? array(), $name);
        if ( null !== $nested ) {
            return $nested;
        }
    }
    return null;
};

$failures = array();

// 1. Every heading level keeps a native heading with the icon as an inline image object.
foreach ( range(1, 6) as $level ) {
    $result = ( new HtmlTransformer() )->transform('<h' . $level . ' class="flex items-center gap-2"> Project title ' . $icon . ' </h' . $level . '>')->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $heading = $findBlock($result['blocks'] ?? array(), 'core/heading');
    $content = (string) ($heading['attrs']['content'] ?? '');
    $svgAssets = array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? ''));
    if ( null === $heading
        || $level !== (int) ($heading['attrs']['level'] ?? 0)
        || str_contains($markup, '<!-- wp:html')
        || str_contains($markup, '<svg')
        || ! str_contains($content, 'Project title')
        || ! str_contains($content, '<img src="assets/materialized-svg/')
        || 1 !== count($svgAssets)
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = 'h' . $level . ' with an inline icon SVG stays a native core/heading with a materialized inline image: ' . $markup;
    }
}

// 2. A card link wrapping a heading, text and a list: the heading keeps the icon and receives the card href like its siblings.
$card = ( new HtmlTransformer() )->transform(
    '<div class="group"><a href="https://example.com/" target="_blank">'
    . '<h3 class="mb-1 flex items-center gap-2 text-xl font-semibold"> Project title ' . $icon . ' </h3>'
    . '<div class="mb-2 text-sm"><p>First role</p><p>Second role</p></div>'
    . '<ul><li>Built the thing</li></ul>'
    . '</a></div>'
)->toArray();
$cardMarkup = (string) ($card['serialized_blocks'] ?? '');
$cardHeading = $findBlock($card['blocks'] ?? array(), 'core/heading');
$cardHeadingContent = (string) ($cardHeading['attrs']['content'] ?? '');
if ( null === $cardHeading
    || in_array('core/html', $blockNames($card['blocks'] ?? array()), true)
    || str_contains($cardMarkup, '<svg')
    || ! str_contains($cardHeadingContent, '<img src="assets/materialized-svg/')
    || ! preg_match('/<a\b[^>]*href="https:\/\/example\.com\/"[^>]*>\s*Project title/', $cardHeadingContent)
    || 'pass' !== ($card['source_reports']['wp_block_validity']['status'] ?? '') ) {
    $failures[] = 'Card-link heading with an inline icon keeps the icon, the link and a native heading block: ' . $cardMarkup;
}

// 3. A heading made only of an icon is not dropped once the icon is an inline image.
$iconOnly = ( new HtmlTransformer() )->transform('<h2>' . $icon . '</h2>')->toArray();
$iconOnlyMarkup = (string) ($iconOnly['serialized_blocks'] ?? '');
if ( str_contains($iconOnlyMarkup, '<!-- wp:html')
    || str_contains($iconOnlyMarkup, '<svg')
    || ! str_contains($iconOnlyMarkup, '<img src="assets/materialized-svg/')
    || array() === ($iconOnly['blocks'] ?? array()) ) {
    $failures[] = 'Icon-only heading keeps a block with the materialized icon: ' . $iconOnlyMarkup;
}

// 4. An unsafe SVG still falls back to core/html and leaves no half-materialized assets behind (same transaction as paragraphs).
$unsafe = ( new HtmlTransformer() )->transform('<h2>Title ' . $icon . ' <svg><script>alert(1)</script></svg></h2>')->toArray();
if ( 'core/html' !== ($unsafe['blocks'][0]['blockName'] ?? '')
    || ! str_contains((string) ($unsafe['blocks'][0]['attrs']['content'] ?? ''), '<svg')
    || array() !== ($unsafe['assets'] ?? array()) ) {
    $failures[] = 'Heading with an unsafe SVG falls back to core/html and restores materialized assets.';
}

// 5. Paragraphs are unchanged: the same icon paragraph still materializes (the shared primitive).
$paragraph = ( new HtmlTransformer() )->transform('<p> Project title ' . $icon . ' </p>')->toArray();
if ( 'core/paragraph' !== ($paragraph['blocks'][0]['blockName'] ?? '') || ! str_contains((string) ($paragraph['blocks'][0]['attrs']['content'] ?? ''), '<img src="assets/materialized-svg/') ) {
    $failures[] = 'Paragraph with an inline icon SVG still materializes the icon.';
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Heading inline SVG rich text passed.\n";

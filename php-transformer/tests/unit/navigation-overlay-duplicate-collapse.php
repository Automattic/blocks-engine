<?php
declare(strict_types=1);

/**
 * A nav that authors a desktop link cluster and a viewport-collapsed overlay
 * copy of the same destinations must emit the in-flow cluster once. Collapsing
 * that duplicate is what lets a brand+cluster carrier keep justify-between
 * as two flex children (wordmark | menu) instead of spreading every overlay
 * link across the bar.
 *
 * A genuinely visible second group is not a duplicate: both clusters occupy
 * layout. Identification is resolved display at the reference viewport plus
 * an equivalent destination-link signature — not overlay class names.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

/** @param array<int, array<string, mixed>> $blocks */
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $findBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }

    return $found;
};

$css = '.hidden{display:none}.flex{display:flex}.items-center{align-items:center}'
    . '.justify-between{justify-content:space-between}.gap-4{gap:1rem}'
    . '.fixed{position:fixed}.inset-0{inset:0}.text-white{color:#fff}'
    . '@media (min-width:768px){.md\\:block{display:block}.md\\:hidden{display:none}}';

$overlayNav = '<style>' . $css . '</style>'
    . '<nav class="flex items-center justify-between">'
    . '<a href="/" id="logo">~/site</a>'
    . '<div class="hidden md:block"><ul class="flex gap-4">'
    . '<li><a href="/now">Now</a></li>'
    . '<li><a href="/blog">Archive</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul></div>'
    . '<button type="button" class="md:hidden" aria-label="Toggle Menu"><span></span><span></span><span></span></button>'
    . '<div class="fixed inset-0 md:hidden"><ul>'
    . '<li><a href="/now" class="text-white">Now</a></li>'
    . '<li><a href="/blog" class="text-white">Archive</a></li>'
    . '<li><a href="/contact" class="text-white">Contact</a></li>'
    . '</ul></div></nav>';

$overlay = ( new HtmlTransformer() )->transform($overlayNav)->toArray();
$overlayMarkup = (string) ($overlay['serialized_blocks'] ?? '');
$overlayBlocks = is_array($overlay['blocks'] ?? null) ? $overlay['blocks'] : array();
$overlayLinks = $findBlocks($overlayBlocks, 'core/navigation-link');
$overlayLabels = array_map(
    static fn (array $block): string => (string) (($block['attrs'] ?? array())['label'] ?? ''),
    $overlayLinks
);

$assert(
    1 === count($findBlocks($overlayBlocks, 'core/navigation')),
    'overlay duplicate: exactly one core/navigation is emitted',
    (string) count($findBlocks($overlayBlocks, 'core/navigation'))
);
$assert(
    3 === count($overlayLinks),
    'overlay duplicate: only the three in-flow destinations become navigation links',
    json_encode($overlayLabels)
);
$assert(
    array( 'Now', 'Archive', 'Contact' ) === $overlayLabels,
    'overlay duplicate: in-flow labels are preserved in source order',
    json_encode($overlayLabels)
);
$assert(
    1 === substr_count($overlayMarkup, '"url":"/now"')
        && 1 === substr_count($overlayMarkup, '"url":"/blog"')
        && 1 === substr_count($overlayMarkup, '"url":"/contact"'),
    'overlay duplicate: each destination is serialized once',
    $overlayMarkup
);
$assert(
    str_contains($overlayMarkup, 'blocks-engine-brand-navigation-carrier'),
    'overlay duplicate: collapsing the copy lets the brand+cluster carrier form',
    $overlayMarkup
);
$overlayNavs = $findBlocks($overlayBlocks, 'core/navigation');
$assert(
    'mobile' === ($overlayNavs[0]['attrs']['overlayMenu'] ?? ''),
    'overlay duplicate: the landmark hamburger still projects Core overlayMenu mobile',
    json_encode($overlayNavs[0]['attrs'] ?? array())
);
$assert(
    ! str_contains($overlayMarkup, '~/site') || 1 === substr_count($overlayMarkup, '~/site'),
    'overlay duplicate: the wordmark is not absorbed as extra menu items',
    $overlayMarkup
);

$visibleSecond = ( new HtmlTransformer() )->transform(
    '<style>' . $css . '</style>'
    . '<nav class="flex items-center justify-between">'
    . '<a href="/" id="logo">~/site</a>'
    . '<div class="menu"><ul class="flex gap-4">'
    . '<li><a href="/now">Now</a></li>'
    . '<li><a href="/blog">Archive</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul></div>'
    . '<div class="extras"><ul class="flex gap-4">'
    . '<li><a href="/notes">Notes</a></li>'
    . '<li><a href="/labs">Labs</a></li>'
    . '<li><a href="/press">Press</a></li>'
    . '</ul></div></nav>'
)->toArray();
$visibleMarkup = (string) ($visibleSecond['serialized_blocks'] ?? '');
$visibleBlocks = is_array($visibleSecond['blocks'] ?? null) ? $visibleSecond['blocks'] : array();
$visibleLabels = array_map(
    static fn (array $block): string => (string) (($block['attrs'] ?? array())['label'] ?? ''),
    $findBlocks($visibleBlocks, 'core/navigation-link')
);

$assert(
    in_array('Now', $visibleLabels, true)
        && in_array('Archive', $visibleLabels, true)
        && in_array('Contact', $visibleLabels, true),
    'visible second group: the first cluster remains',
    json_encode($visibleLabels)
);
$assert(
    in_array('Notes', $visibleLabels, true)
        && in_array('Labs', $visibleLabels, true)
        && in_array('Press', $visibleLabels, true),
    'visible second group: the second cluster is not collapsed',
    json_encode($visibleLabels)
);
$assert(
    str_contains($visibleMarkup, '"url":"/notes"')
        && str_contains($visibleMarkup, '"url":"/labs"')
        && str_contains($visibleMarkup, '"url":"/press"'),
    'visible second group: second-cluster destinations survive serialization',
    $visibleMarkup
);

$noBrandOverlay = ( new HtmlTransformer() )->transform(
    '<style>' . $css . '</style>'
    . '<nav class="flex">'
    . '<ul class="hidden md:block">'
    . '<li><a href="/now">Now</a></li>'
    . '<li><a href="/blog">Archive</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul>'
    . '<ul class="md:hidden">'
    . '<li><a href="/now">Now</a></li>'
    . '<li><a href="/blog">Archive</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul></nav>'
)->toArray();
$noBrandLinks = $findBlocks(is_array($noBrandOverlay['blocks'] ?? null) ? $noBrandOverlay['blocks'] : array(), 'core/navigation-link');
$noBrandLabels = array_map(
    static fn (array $block): string => (string) (($block['attrs'] ?? array())['label'] ?? ''),
    $noBrandLinks
);
$assert(
    array( 'Now', 'Archive', 'Contact' ) === $noBrandLabels,
    'list-only overlay duplicate: the collapsed copy is not merged into the in-flow menu',
    json_encode($noBrandLabels)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "navigation overlay duplicate collapse: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "navigation overlay duplicate collapse passed: {$passes} assertions\n";

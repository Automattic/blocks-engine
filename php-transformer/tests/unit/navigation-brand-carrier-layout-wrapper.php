<?php
declare(strict_types=1);

/**
 * A brand carrier must see through the landmark's one layout box.
 *
 * Headers commonly author `<nav><div class="site-wrap nav-inner">brand ·
 * link cluster · CTA</div></nav>`: the inner box owns the row's centred width
 * and its flex distribution. The carrier only read the landmark's direct
 * children, so it found a lone `div` and declined; the landmark then folded
 * brand, every link and the CTA into ONE flat core/navigation and dropped the
 * box. The brand lost its centred offset, the cluster lost the wrapper that
 * hides it at narrow widths, and every text run after the header moved.
 *
 * The carrier now reads the row inside that box and keeps the box as a group
 * between the `<nav>` carrier and its brand, menu and CTA.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

/** @param array<int, array<string, mixed>> $blocks */
$find = static function (array $blocks, string $name) use (&$find): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $find(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }
    return $found;
};

$html = '<style>.site-wrap{width:min(1220px,100% - 68px);margin-inline:auto}'
    . '.nav-inner{justify-content:space-between;align-items:center;gap:20px;display:flex;min-height:72px}'
    . '.brand{color:#151515;gap:6px;font-size:18px;font-weight:700;text-decoration:none;display:flex}'
    . '.nav-links{align-items:center;gap:24px;display:flex}.nav-links a{color:#151515;font-size:11px}'
    . '.nav-cta{background:#c00;color:#fff;height:42px;align-items:center;padding:0 24px;font-size:11px;display:inline-flex}'
    . '@media(max-width:900px){.nav-links{display:none}.nav-cta{margin-left:auto}}</style>'
    . '<main><nav class="site-nav" aria-label="Main navigation"><div class="site-wrap nav-inner">'
    . '<a class="brand" href="#top">ZACH <em>RICHARDS</em></a>'
    . '<div class="nav-links"><a href="#about">About</a><a href="#book">Audiobook</a><a href="#speaking">Speaking</a><a href="#writing">Blog</a></div>'
    . '<a class="nav-cta" href="#book">Get the audiobook</a>'
    . '</div></nav><section id="top"><h1>Hello</h1></section></main>';

foreach ( array( 'with CTA' => $html, 'without CTA' => str_replace('<a class="nav-cta" href="#book">Get the audiobook</a>', '', $html) ) as $label => $source ) {
    $result = ( new HtmlTransformer() )->transform($source, array())->toArray();
    $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();

    $navigations = $find($blocks, 'core/navigation');
    $assert(1 === count($navigations), $label . ': one core/navigation is emitted');
    $navigation = $navigations[0] ?? array();
    $labels = array_map(static fn (array $link): string => (string) ($link['attrs']['label'] ?? ''), $navigation['innerBlocks'] ?? array());
    $assert(array( 'About', 'Audiobook', 'Speaking', 'Blog' ) === $labels, $label . ': the menu holds only the link cluster: ' . json_encode($labels));
    $assert(str_contains((string) ($navigation['attrs']['className'] ?? ''), 'nav-links'), $label . ': the cluster keeps its own class on the menu');
    $assert(array() === $find($navigation['innerBlocks'] ?? array(), 'core/navigation-submenu'), $label . ': the layout box is not mapped to a submenu');
    // Recognizing the outer layout must not bypass the flattened link run's
    // responsive visibility, which ordinary navigation already preserves.
    $carriers = array();
    foreach ( $navigation['innerBlocks'] ?? array() as $link ) {
        $classes = preg_split('/\s+/', (string) ($link['attrs']['className'] ?? '')) ?: array();
        $semantic = array_values(array_filter($classes, static fn (string $class): bool => str_starts_with($class, 'blocks-engine-semantic-')));
        $assert(1 === count($semantic), $label . ': each menu item retains the responsive wrapper carrier');
        $carriers = array_merge($carriers, $semantic);
    }
    $assert(1 === count(array_unique($carriers)), $label . ': the wrapper visibility follows every menu item together');

    $carrier = null;
    foreach ( $find($blocks, 'core/group') as $group ) {
        if ( 'nav' === ($group['attrs']['tagName'] ?? '') ) {
            $carrier = $group;
        }
    }
    $assert(is_array($carrier), $label . ': the landmark stays a <nav> carrier group');
    $inner = $carrier['innerBlocks'] ?? array();
    $assert(1 === count($inner) && 'core/group' === ($inner[0]['blockName'] ?? ''), $label . ': the carrier holds the authored layout box as one group');
    $wrapperClasses = preg_split('/\s+/', (string) ($inner[0]['attrs']['className'] ?? '')) ?: array();
    $assert(in_array('site-wrap', $wrapperClasses, true) && in_array('nav-inner', $wrapperClasses, true), $label . ': the layout box keeps its authored classes');
    $assert(1 === count($find($inner, 'core/navigation')), $label . ': the menu sits inside the layout box');

    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(str_contains($markup, 'class="brand"') && str_contains($markup, 'RICHARDS'), $label . ': the brand survives beside the menu');
    if ( 'with CTA' === $label ) {
        $assert(str_contains($markup, 'Get the audiobook') && ! in_array('Get the audiobook', $labels, true), $label . ': the CTA stays outside the menu');
        $assert(array() === $find($inner, 'core/button'), $label . ': the carried anchor does not introduce site-wide button defaults');
        $assert(str_contains($markup, '<a class="nav-cta" href="#book">Get the audiobook</a>'), $label . ': the CTA keeps its authored link and classes');
    }
}

if ( 0 < $failures ) {
    exit(1);
}
echo "navigation-brand-carrier-layout-wrapper: passed\n";

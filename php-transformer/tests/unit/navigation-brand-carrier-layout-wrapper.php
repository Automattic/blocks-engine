<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A landmark that wraps its brand, menu and CTA in one centred layout row
// (`nav > div.site-wrap > a.brand + div.nav-links + a.nav-cta`) must keep that
// row. Flattening all three into one core/navigation drops the wrapper's width
// and inline margins, so the brand and links render against the viewport edge.
$html = '<style>'
    . '.site-wrap{width:min(1220px,100% - 68px);margin-inline:auto}'
    . '.nav-inner{display:flex;justify-content:space-between;align-items:center;gap:20px;min-height:64px}'
    . '.site-nav{display:flex;align-items:center;min-height:76px;background:#f5f2f0}'
    . '.brand{display:flex;gap:6px;font-size:18px;font-weight:700;color:#151515;text-decoration:none}'
    . '.nav-links{display:flex;align-items:center;gap:24px}'
    . '.nav-links a{font-size:11px;font-weight:700;color:#151515;text-decoration:none}'
    . '.nav-cta{display:inline-flex;align-items:center;height:42px;padding:0 24px;background:#d33;color:#fff;text-transform:uppercase;text-decoration:none}'
    . '</style><main><nav class="site-nav" aria-label="Main navigation"><div class="site-wrap nav-inner">'
    . '<a class="brand" href="#top">ZACH <em>RICHARDS</em></a>'
    . '<div class="nav-links"><a href="#about">About</a><a href="#book">Audiobook</a><a href="#speaking">Speaking</a><a href="#writing">Blog</a></div>'
    . '<a class="nav-cta" href="#book">Get the audiobook</a>'
    . '</div></nav><section id="about"><h2>About</h2><p>Body</p></section></main>';

$blocks = parse_blocks_tree((new HtmlTransformer())->transform($html, array())->toArray()['serialized_blocks']);
$carrier = find_block($blocks, static fn (array $block): bool => 'core/group' === $block['name'] && str_contains($block['attrs']['className'] ?? '', 'site-nav'));
if ( null === $carrier || 'nav' !== ($carrier['attrs']['tagName'] ?? '') ) {
    throw new RuntimeException('The navigation landmark must become a nav carrier group');
}
$row = $carrier['inner'][0] ?? null;
if ( 1 !== count($carrier['inner']) || 'core/group' !== ($row['name'] ?? '') || ! str_contains($row['attrs']['className'] ?? '', 'site-wrap nav-inner') ) {
    throw new RuntimeException('The authored layout row must survive inside the carrier as its own group');
}
$names = array_column($row['inner'], 'name');
if ( array( 'core/paragraph', 'core/navigation', 'core/buttons' ) !== $names ) {
    throw new RuntimeException('The row must keep brand, menu and CTA in authored order, got ' . implode(', ', $names));
}
$labels = array_map(static fn (array $link): string => (string) ($link['attrs']['label'] ?? ''), $row['inner'][1]['inner']);
if ( array( 'About', 'Audiobook', 'Speaking', 'Blog' ) !== $labels ) {
    throw new RuntimeException('Only the link cluster may become menu items, got ' . implode(', ', $labels));
}
echo "navigation-brand-carrier-layout-wrapper: ok\n";

/** @return list<array{name: string, attrs: array<string, mixed>, inner: list<array<string, mixed>>}> */
function parse_blocks_tree(string $serialized): array
{
    preg_match_all('/<!-- (\/?)wp:([a-z0-9\/-]+)(?: (\{.*?\}))? (\/?)-->/s', $serialized, $tokens, PREG_SET_ORDER);
    $root = array( 'inner' => array() );
    $stack = array( &$root );
    foreach ( $tokens as $token ) {
        [ , $closing, $name, $json, $self ] = $token + array( 3 => '', 4 => '' );
        $name = str_contains($name, '/') ? $name : 'core/' . $name;
        if ( '/' === $closing ) {
            array_pop($stack);
            continue;
        }
        $parent = &$stack[count($stack) - 1];
        $parent['inner'][] = array( 'name' => $name, 'attrs' => '' === $json ? array() : (json_decode($json, true) ?: array()), 'inner' => array() );
        if ( '/' !== $self ) {
            $stack[] = &$parent['inner'][count($parent['inner']) - 1];
        }
        unset($parent);
    }
    return $root['inner'];
}

/** @return array<string, mixed>|null */
function find_block(array $blocks, callable $match): ?array
{
    foreach ( $blocks as $block ) {
        if ( $match($block) ) {
            return $block;
        }
        $found = find_block($block['inner'], $match);
        if ( null !== $found ) {
            return $found;
        }
    }
    return null;
}

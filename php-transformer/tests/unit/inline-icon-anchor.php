<?php
declare(strict_types=1);

/**
 * An inline icon that is part of a link's phrasing content must stay inside
 * that authored anchor. hasBlockContentChildren treats every non-text-formatting
 * tag as block content, including svg; the button/link dispatcher now opts an
 * svg child in as inline for this one decision (`$treatSvgAsInline`) so the icon
 * is not hoisted out into a sibling icon-only link. Other replaced elements
 * (video, img used as real content) still count as block content and still get
 * promoted to a native group, per the `html-whole-element-link-dropped-finding`
 * parity fixture.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$svg = '<svg width="14" height="14" viewBox="0 0 24 24"><path d="M5 12h14"/></svg>';

$textIcon = $transform(
    '<style>.inline-flex{display:inline-flex}.items-center{align-items:center}.gap-2{gap:.5rem}</style>'
    . '<a class="inline-flex items-center gap-2" href="/renovations">LEARN MORE ' . $svg . '</a>'
);
$textIconMarkup = (string) ( $textIcon['serialized_blocks'] ?? '' );
$assert(1 === substr_count($textIconMarkup, '<a '), '1: one authored text+icon control materialises as exactly one anchor', $textIconMarkup);
$assert(
    (bool) preg_match('/<a class="inline-flex items-center gap-2" href="\/renovations">LEARN MORE <img /', $textIconMarkup),
    '2: the icon stays inside the authored anchor after SVG materialization',
    $textIconMarkup
);
$assert(! str_contains($textIconMarkup, 'wp:group'), '3: the text+icon link is not split into a wrapper group', $textIconMarkup);
$assert(1 === substr_count($textIconMarkup, '<!-- wp:paragraph'), '4: the text+icon link stays on one paragraph host', $textIconMarkup);
$assert('pass' === ( ( new BlockValidityValidator() )->validateBlocks($textIcon['blocks'] ?? array())['status'] ?? '' ), '5: the text+icon paragraph remains editor-valid');

$iconOnly = $transform(
    '<a href="https://facebook.com" aria-label="Facebook">' . $svg . '</a>'
);
$iconOnlyMarkup = (string) ( $iconOnly['serialized_blocks'] ?? '' );
$assert(1 === substr_count($iconOnlyMarkup, '<a '), '6: an icon-only social anchor stays a single link', $iconOnlyMarkup);
$assert(
    str_contains($iconOnlyMarkup, 'aria-label="Facebook"') && str_contains($iconOnlyMarkup, '<img src="assets/materialized-svg/'),
    '7: an icon-only social anchor keeps its accessible name and materialized icon',
    $iconOnlyMarkup
);
$assert(! str_contains($iconOnlyMarkup, 'LEARN MORE'), '8: an icon-only social anchor is not mixed with unrelated text');

$card = $transform(
    '<a class="card-link" href="/offer"><div><h3>Offer</h3><p>Details</p></div></a>'
);
$cardMarkup = (string) ( $card['serialized_blocks'] ?? '' );
$assert(str_contains($cardMarkup, 'wp:heading') && str_contains($cardMarkup, '<h3'), '9: a block-wrapping card link still lowers its inner structure', $cardMarkup);
$assert(2 === substr_count($cardMarkup, '<a ') && ! str_contains($cardMarkup, '<!-- wp:paragraph {"className":"blocks-engine-synthetic-paragraph"}'), '10: a card wrapper is not flattened into one phrasing anchor', $cardMarkup);

// A wrapped video is real block content, not a decorative icon. The svg
// carve-out must not swallow other replaced elements: this anchor still
// promotes to a native group and the un-preservable link is reported, not
// silently dropped or crammed into a button label.
$video = $transform('<a href="/watch" class="video-card"><video src="/clip.mp4">Transcript</video></a>');
$assert(
    'core/group' === ($video['blocks'][0]['blockName'] ?? ''),
    '11: an anchor wrapping a video still promotes to a native group',
    (string) ($video['serialized_blocks'] ?? '')
);
$assert(
    'core/video' === ($video['blocks'][0]['innerBlocks'][0]['blockName'] ?? ''),
    '12: the wrapped video converts to its native block',
    (string) ($video['serialized_blocks'] ?? '')
);
$assert(
    1 === count($video['source_reports']['html']['dropped_link_wrappers'] ?? array()),
    '13: the un-preservable link is reported rather than silently dropped',
    (string) ($video['serialized_blocks'] ?? '')
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "inline icon anchor tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "inline icon anchor tests: {$passes} passed" . PHP_EOL);

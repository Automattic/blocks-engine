<?php
declare(strict_types=1);

/**
 * Icon+text pill anchors ("Agendar sessão" on lovable.app) declined as buttons.
 *
 * The anchor is an explicit control surface — inline-flex, fixed height, box
 * padding, fill, and full rounding — carrying phrasing content (text plus an
 * optional decorative svg), yet the import split it into a group of two
 * paragraph-links (one holding the materialized svg, one holding a naked
 * anchor), losing the 180×36 pill chrome. Two gates caused the decline:
 *
 *  - `hasStyleSignal()` inspects the static-cascade resolved style only. A
 *    capture serialises a builder's desktop styles behind a width query, so
 *    class-owned padding/fill never reach that view.
 *  - `hasClassSignal()` keys off "btn"/"button" substrings, which utility
 *    class names (`rounded-full bg-primary h-9 px-6`) do not contain.
 *
 * Recognition now re-classifies phrasing-content anchors against the full
 * authored cascade (desktop-applicable conditional rules included).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$transform = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );

$svg = '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';

// The pinned fixture: an inline-styled pill anchor with icon + text.
$inline = $transform(
    '<a class="pill" href="https://wa.me/1" style="display:inline-flex;align-items:center;gap:8px;height:36px;padding:8px 24px;border-radius:9999px;background:orange;color:#fff">' . $svg . 'Agendar sessão</a>'
);
$assert(str_contains($inline, 'wp:buttons') && str_contains($inline, 'wp:button'), '1: inline-styled pill anchor becomes core/buttons > core/button', $inline);
$assert(str_contains($inline, 'Agendar sessão') && str_contains($inline, 'https://wa.me/1'), '2: pill button keeps its label text and href', $inline);
$assert(0 === substr_count($inline, 'wp:paragraph'), '3: no paragraph-link split for the pill anchor', $inline);

// The production shape: pill styling owned by classes inside a width query,
// which the static-cascade view cannot see.
$media = $transform(
    '<style>@media (min-width:1024px){.inline-flex{display:inline-flex}.items-center{align-items:center}.gap-2{gap:8px}.rounded-full{border-radius:9999px}.bg-primary{background:orange}.h-9{height:36px}.px-6{padding-left:24px;padding-right:24px}.text-white{color:#fff}}</style>'
    . '<a class="inline-flex items-center gap-2 rounded-full bg-primary h-9 px-6 text-white" href="https://wa.me/1">' . $svg . 'Agendar sessão</a>'
);
$assert(str_contains($media, 'wp:buttons') && str_contains($media, 'wp:button'), '4: class-owned desktop pill becomes core/buttons > core/button', $media);
$assert(str_contains($media, 'Agendar sessão'), '5: class-owned pill button keeps its label text', $media);
$assert(! str_contains($media, 'wp:group'), '6: class-owned pill does not split into a group of paragraph-links', $media);
$assert(str_contains($media, 'inline-flex items-center gap-2 rounded-full bg-primary h-9 px-6 text-white'), '7: class-owned pill keeps its source classes rendering the fill', $media);

// Same class-owned styling, but only inside a max-width query: the desktop
// reference viewport does not render that surface, so the anchor stays a link.
$mobileOnly = $transform(
    '<style>@media (max-width:768px){.mobile-cta{padding:8px 24px;background:orange;border-radius:9999px}}</style>'
    . '<a class="mobile-cta" href="/about">About</a>'
);
$assert(! str_contains($mobileOnly, 'wp:button'), '8: mobile-only pill styling does not classify a desktop link as a button', $mobileOnly);

// A plain painted link with no control box remains a link.
$plain = $transform('<style>.nav-link{color:#333}</style><a class="nav-link" href="/about">About</a>');
$assert(! str_contains($plain, 'wp:button'), '9: plain text link without a control box stays a link', $plain);

// A pill-styled anchor carrying structural children keeps out of this path.
$card = $transform(
    '<style>@media (min-width:1024px){.card-link{display:block;padding:24px;background:orange;border-radius:12px}}</style>'
    . '<a class="card-link" href="/offer"><div><h3>Offer</h3><p>Details</p></div></a>'
);
$assert(! str_contains($card, 'wp:button'), '10: structural anchor content does not classify as a pill button', $card);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "pill button anchor tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "pill button anchor tests: {$passes} passed" . PHP_EOL);

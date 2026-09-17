<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$transform = static fn (string $html, string $css = ''): array =>
    ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();

// Issue #1751, first evidence set (Weebly hamburger nav trigger): a styling
// hook span whose own tag name is the rightmost compound of an unprojected
// `:after` pseudo-element rule must keep its literal `<span>` tag, or the
// generated-content label the rule paints is orphaned and the trigger
// collapses to zero height. Captured heights from the live DOM comparison:
//   imported (<mark>)      height 0,  computed ::after content: none
//   tag restored to <span> height 15, computed ::after content: "MENU"
//   source reference       height 60, computed ::after content: "MENU"
$hamburger = $transform(
    '<a class="hamburger w-navpane-trigger" aria-label="Menu" href="#"><span></span></a>',
    '.hamburger span { position: relative; display: block; color: #fff; }'
    . '.hamburger span:after { display: block; color: #fff; content: \'MENU\'; }'
);
$hamburgerSerialized = (string) ($hamburger['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/hamburger[^>]*>\s*<span/i', $hamburgerSerialized) && 0 === preg_match('/hamburger[^>]*>\s*<mark/i', $hamburgerSerialized),
    'a span whose own tag name is load-bearing for an unprojected `:after` rule keeps its literal <span> tag instead of becoming <mark>, so the generated-content label the rule paints is never orphaned'
);

// Issue #1751, second evidence set (Lovable/Tailwind social-links grid):
// importing a page with zero source `<mark>` elements produced 24 of them,
// every one a plain `<span class="...">` carrying only ordinary utility
// styling inside a link — never intended as "highlighted" content. Reduced
// to two sibling spans inside an anchor, matching the reported shape.
$socialLink = $transform(
    '<a href="https://open.spotify.com/artist/x" class="block p-6">'
    . '<span class="block text-xs uppercase text-ink-soft">Spotify</span>'
    . '<span class="mt-2 block text-xl text-ink">Harrykahanhai</span>'
    . '</a>',
    '.text-ink-soft { color: #888; } .text-ink { color: #111; }'
);
$socialLinkSerialized = (string) ($socialLink['serialized_blocks'] ?? '');
$assert(
    2 === substr_count($socialLinkSerialized, '<mark ') && 2 === substr_count($socialLinkSerialized, 'role="none"'),
    'plain styled spans repurposed as <mark> carriers for RichText persistence get role="none" so assistive tech does not announce ordinary styled text as highlighted/marked content it never was'
);
$assert(
    str_contains($socialLinkSerialized, 'text-ink-soft" style="color:#888') && str_contains($socialLinkSerialized, 'text-ink" style="color:#111'),
    'the visual styling that motivated the <mark> carrier is still faithfully preserved on the element'
);
$socialLinkValidity = ( new BlockValidityValidator() )->validateBlocks($socialLink['blocks'] ?? array());
$assert(
    'pass' === ($socialLinkValidity['status'] ?? ''),
    'the role="none" attribute does not affect Gutenberg-validity of the resulting markup'
);

// A genuine source `<mark>` (the author's own "this text is highlighted"
// intent) must keep its default accessible role: role="none" is only added
// when this lowering repurposes a different tag (span/font/strong/em/…) as
// a mark carrier, never when the source already wrote <mark> itself.
$genuineMark = $transform(
    '<p>Say <mark class="found">hello</mark> there</p>',
    '.found { background-color: yellow; }'
);
$genuineMarkSerialized = (string) ($genuineMark['serialized_blocks'] ?? '');
$assert(
    1 === substr_count($genuineMarkSerialized, '<mark ') && ! str_contains($genuineMarkSerialized, 'role="none"'),
    'a genuine source <mark> keeps its default accessible "mark" role since the author intended real highlight semantics'
);

// An ordinary styling-hook span with no pseudo-element tag dependency still
// lowers to a <mark> carrier as before (issue #1751 acceptance), now with
// role="none" alongside it.
$ordinaryHook = $transform(
    '<p>Say <span class="callout">hello</span> there</p>',
    '.callout { color: #b00; font-weight: bold; }'
);
$ordinarySerialized = (string) ($ordinaryHook['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/<mark class="callout"[^>]*role="none"[^>]*>hello<\/mark>/i', $ordinarySerialized),
    'an ordinary styling-hook span with no pseudo-element tag dependency still lowers to a <mark role="none"> carrier'
);

if ( 0 < $failures ) {
    exit(1);
}

echo "richtext mark-carrier role unit tests: {$passes} passed\n";

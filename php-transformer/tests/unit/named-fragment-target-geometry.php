<?php
declare(strict_types=1);

/**
 * Empty named fragment targets must keep their capture-time position so hash
 * navigation can scroll (issue #1290), and must keep their captured identity
 * even when that identity is not a CSS-identifier (issue #1625).
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

$transformer = new HtmlTransformer();
$html = '<main>'
    . '<span id="features" aria-hidden="true" style="position: absolute; top: 787px; left: 0px; width: 0px; height: 0px; overflow: hidden; pointer-events: none;"></span>'
    . '<h2>Built for the Conscious Traveler</h2>'
    . '<a href="#features">Features</a>'
    . '</main>';
$out = $transformer->transform($html)->toArray();
$serialized = (string) ( $out['serialized_blocks'] ?? '' );
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}
if ( '' === $css ) {
    foreach ( $out['source_reports'] ?? array() as $report ) {
        if ( is_array($report) ) {
            $css .= json_encode($report);
        }
    }
}

$assert(str_contains($serialized, 'id="features"'), '1: fragment id is preserved', $serialized);
$assert(
    str_contains($css, '787px') && ( str_contains($css, 'position:absolute') || str_contains($css, 'position: absolute') ),
    '2: empty named target keeps absolute top',
    $css !== '' ? substr($css, 0, 1500) : $serialized
);

$filled = $transformer->transform(
    '<main><div id="card" style="position:absolute;top:40px;width:200px;height:80px"><p>Card</p></div></main>'
)->toArray();
$filledCss = '';
foreach ( $filled['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $filledCss .= (string) ( $asset['content'] ?? '' );
    }
}
$assert(
    str_contains((string) ( $filled['serialized_blocks'] ?? '' ), 'Card'),
    '3: non-empty positioned content still converts',
    (string) ( $filled['serialized_blocks'] ?? '' )
);

// -- Issue #1625: capture stamps a fragment target's `id` with the component's
// authored name, not a CSS-identifier slug. "Contact Us" is the exact shape
// Data Liberation writes (`<span id="Contact Us" data-dla-anchor-target="Contact
// Us" ...>`), and the link that reaches it is percent-encoded to match
// (`href="/#Contact%20Us"`). A block's `anchor` support has no CSS-identifier
// constraint — only `save()` byte-parity matters — so the literal captured
// text, spaces included, must survive onto the generated block, or the link
// keeps its destination while the destination itself vanishes.
$multiWordTarget = $transformer->transform(
    '<nav><a href="/#Contact%20Us">Contact</a></nav>'
    . '<main><span id="Contact Us" data-dla-anchor-target="Contact Us" data-dla-anchor-source-id="comp-mmk30y0o" aria-hidden="true" style="position:absolute;top:1200px;left:0;width:0;height:0;overflow:hidden;pointer-events:none"></span>'
    . '<h2>Contact</h2></main>'
)->toArray();
$multiWordMarkup = (string) ( $multiWordTarget['serialized_blocks'] ?? '' );
$multiWordValidity = ( new BlockValidityValidator() )->validateBlocks($multiWordTarget['blocks'] ?? array());
$assert(
    str_contains($multiWordMarkup, 'id="Contact Us"'),
    '4: a fragment target id containing a space is preserved literally, matching the percent-decoded href it is the destination of',
    $multiWordMarkup
);
$assert(
    str_contains($multiWordMarkup, '"anchor":"Contact Us"'),
    '5: the literal captured id reaches the block\'s own anchor attribute, not just its rendered id',
    $multiWordMarkup
);
$assert(
    'pass' === ($multiWordValidity['status'] ?? ''),
    '6: a block carrying a space-containing anchor still round-trips through wp.blocks.validateBlock',
    json_encode($multiWordValidity)
);

// -- The same capture stamps distinct desktop and mobile documents with the
// SAME source component id, disambiguated only by a `--dla-mobile` suffix on
// the mobile copy. Both must survive as their own, independently addressable
// targets: collapsing them onto one anchor would leave one document's link
// pointing at the other document's target, or at nothing once one document is
// hidden by breakpoint.
$responsiveTargets = $transformer->transform(
    '<div class="data-liberation-desktop-document">'
    . '<nav><a href="/#Contact%20Us">Contact</a></nav>'
    . '<main><span id="Contact Us" data-dla-anchor-target="Contact Us" data-dla-anchor-source-id="comp-mmk30y0o" aria-hidden="true" style="position:absolute;top:1200px;left:0;width:0;height:0;overflow:hidden;pointer-events:none"></span>'
    . '<h2>Contact</h2></main></div>'
    . '<div class="data-liberation-mobile-document">'
    . '<nav><a href="/#Contact%20Us--dla-mobile">Contact</a></nav>'
    . '<main><span id="Contact Us--dla-mobile" data-dla-anchor-target="Contact Us" data-dla-anchor-source-id="comp-mmk30y0o" aria-hidden="true" style="position:absolute;top:900px;left:0;width:0;height:0;overflow:hidden;pointer-events:none"></span>'
    . '<h2>Contact</h2></main></div>'
)->toArray();
$responsiveMarkup = (string) ( $responsiveTargets['serialized_blocks'] ?? '' );
$responsiveValidity = ( new BlockValidityValidator() )->validateBlocks($responsiveTargets['blocks'] ?? array());
$assert(
    str_contains($responsiveMarkup, 'id="Contact Us"'),
    '7: the desktop document keeps its own target identity',
    $responsiveMarkup
);
$assert(
    str_contains($responsiveMarkup, 'id="Contact Us--dla-mobile"'),
    '8: the mobile document keeps its own, distinctly-suffixed target identity rather than colliding with the desktop target',
    $responsiveMarkup
);
$assert(
    'pass' === ($responsiveValidity['status'] ?? ''),
    '9: both the desktop and mobile fragment targets remain Gutenberg-valid',
    json_encode($responsiveValidity)
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "named fragment target tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "named fragment target tests: {$passes} passed" . PHP_EOL);

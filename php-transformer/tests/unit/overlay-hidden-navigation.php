<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$html = '<nav id="TINY_MENU" class="TINY_MENU" aria-label="Site">'
    . '<div class="fullScreenOverlay"><div class="fullScreenOverlayContent"><div></div></div></div>'
    . '<ul aria-hidden="true" style="display:none">'
    . '<li><a href="/">HOME</a></li>'
    . '<li><a href="/about">About</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul>'
    . '<button type="button" class="S3WS78" aria-haspopup="true">'
    . '<svg viewBox="0 0 17 17"><line x2="100%"></line><line x2="100%"></line><line x2="100%"></line></svg>'
    . '</button>'
    . '</nav>';

$result = ( new HtmlTransformer() )->transform($html)->toArray();
$serialized = (string) ($result['serialized_blocks'] ?? '');

$assert(str_contains($serialized, '<!-- wp:navigation'), 'hidden overlay nav converts to core/navigation');
$assert(str_contains($serialized, '"overlayMenu":"mobile"'), 'hidden overlay nav uses the native mobile overlay');
$assert(! str_contains($serialized, '<!-- wp:button'), 'dead hamburger toggle is not emitted as a core/button');
$assert(str_contains($serialized, 'HOME') && str_contains($serialized, 'About') && str_contains($serialized, 'Contact'), 'hidden overlay nav keeps the link list');

$runtime = ( new HtmlTransformer() )->transform($html, array(
    'runtime_dom_selectors' => array( '.fullScreenOverlay', '.S3WS78' ),
))->toArray();
$runtimeMarkup = (string) ($runtime['serialized_blocks'] ?? '');
$assert(str_contains($runtimeMarkup, '<!-- wp:navigation'), 'runtime-targeted overlay and toggle chrome do not abort list-backed navigation');
$assert(str_contains($runtimeMarkup, '"overlayMenu":"mobile"'), 'runtime-targeted overlay chrome still yields a native mobile overlay menu');

// A content nav shown at every width keeps its links on phones even when a
// closed header drawer lists the same destinations (#4511).
$contentNav = ( new HtmlTransformer() )->transform((string) file_get_contents(dirname(__DIR__) . '/fixtures/content-nav-shared-header-drawer.html'))->toArray();
$overlayByClass = array();
preg_match_all('/<!-- wp:navigation (\{.*?\}) \/?-->/s', (string) ($contentNav['serialized_blocks'] ?? ''), $navigationComments);
foreach ( $navigationComments[1] as $json ) {
    $attrs = json_decode($json, true);
    $overlayByClass[ strtok((string) ($attrs['className'] ?? ''), ' ') ] = $attrs['overlayMenu'] ?? 'mobile';
}
$assert('mobile' === ($overlayByClass['desktop-only'] ?? null), 'the header nav still collapses behind the mobile overlay');
$assert('never' === ($overlayByClass['city-links'] ?? null), 'a content nav visible at every width does not collapse behind the mobile overlay');

if ( 0 !== $failures ) {
    exit(1);
}

echo "overlay-hidden-navigation passed\n";

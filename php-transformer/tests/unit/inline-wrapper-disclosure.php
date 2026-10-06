<?php
declare(strict_types=1);

/**
 * A single toggle plus the collapsed region it controls stays one native
 * `core/details` disclosure when the wrapper around them is an inline `<span>`.
 *
 * A positioned trigger/popover wrapper is commonly a `<span>` (for example a
 * relative inline-block holding a button and an absolutely positioned hidden
 * panel). The wrapper was lowered as a positioned inline carrier instead, so the
 * toggle became a dead button and the collapsed panel — its `hidden` state
 * dropped — rendered as an always-visible overlay.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

$css = '<style>.anchor{position:relative;display:inline-block}.pop{position:absolute;bottom:100%;left:0;width:15rem;z-index:50}.row{display:flex;gap:1rem}</style>';
$widget = static fn (string $wrapperClass): string => '<section class="row"><p>Intro</p>'
    . '<span class="' . $wrapperClass . '"><button type="button" aria-expanded="false" aria-controls="note-1">Note</button>'
    . '<span id="note-1" class="pop" hidden role="region">Collapsed note text.<span>Signature</span></span></span></section>';

foreach ( array( 'plain wrapper' => '<section><p>Intro</p><span class="anchor"><button type="button" aria-expanded="false" aria-controls="note-1">Note</button><span id="note-1" class="pop" hidden role="region">Collapsed note text.<span>Signature</span></span></span></section>', 'css-owned wrapper' => $css . $widget('anchor') ) as $label => $html ) {
    $result = $transform($html);
    $blocks = (string) ( $result['serialized_blocks'] ?? '' );

    $assert(str_contains($blocks, '<!-- wp:details'), $label . ': the span wrapper becomes a native core/details', $blocks);
    $assert(1 === preg_match('/<summary>Note<\/summary>/', $blocks), $label . ': the toggle label becomes the summary', $blocks);
    $assert(! preg_match('/<details[^>]*\sopen[\s>]/', $blocks), $label . ': the disclosure stays closed', $blocks);
    $detailsEnd = strpos($blocks, '</details>');
    $text = strpos($blocks, 'Collapsed note text.');
    $assert(false !== $text && false !== $detailsEnd && $text < $detailsEnd, $label . ': the panel content stays inside the details', $blocks);
    $assert(! str_contains($blocks, 'aria-controls') && ! str_contains($blocks, '<button'), $label . ': no dead toggle button is left behind', $blocks);
    $assert(! str_contains($blocks, 'wp:buttons'), $label . ': the toggle is not lowered to a button block', $blocks);
    $assert(array() === ( $result['fallbacks'] ?? array() ), $label . ': no behavior-loss fallback is recorded', (string) json_encode($result['fallbacks'] ?? array()));
}

// A text run ending in the same widget stays text plus a native disclosure,
// never an opaque core/html island.
$run = $transform('<footer><p>Copyright text <span class="anchor"><button type="button" aria-expanded="false" aria-controls="pn">Note</button><span id="pn" hidden role="region">Panel text</span></span></p></footer>');
$runBlocks = (string) ( $run['serialized_blocks'] ?? '' );
$assert(! str_contains($runBlocks, '<!-- wp:html'), 'paragraph run: no core/html island', $runBlocks);
$assert(str_contains($runBlocks, '<!-- wp:details') && str_contains($runBlocks, '<summary>Note</summary>'), 'paragraph run: the widget becomes core/details', $runBlocks);
$assert(str_contains($runBlocks, 'Copyright text'), 'paragraph run: the leading text is kept as a paragraph', $runBlocks);
$assert(! str_contains($runBlocks, '<button'), 'paragraph run: no dead toggle button', $runBlocks);

// Framework-hydrated text runs carry comment-node separators between text
// fragments; they must not defeat the lowering.
$hydrated = $transform('<footer><div class="row"><p>&copy; <!---->2026<!----> Text here. <span class="anchor"><button type="button" aria-label="Note" aria-expanded="false" aria-controls="pn">Note</button><span id="pn" class="pop" hidden role="region">Panel text.<span class="block">Signed</span></span></span></p><div><a href="/t">Terms</a></div></div></footer>');
$hydratedBlocks = (string) ( $hydrated['serialized_blocks'] ?? '' );
$assert(! str_contains($hydratedBlocks, '<!-- wp:html'), 'hydrated paragraph run: no core/html island', $hydratedBlocks);
$assert(str_contains($hydratedBlocks, '<!-- wp:details') && str_contains($hydratedBlocks, '2026'), 'hydrated paragraph run: text kept and widget is core/details', $hydratedBlocks);

// A capture-owned local-disclosure runtime addresses the panel by attribute.
// Once the widget is a native details block, that selector is superseded and
// the runtime dependency contract must not report a missing DOM target.
$runtimeScript = 'document.addEventListener("click",function(e){var t=e.target.closest("[aria-controls]");var p=t&&t.parentElement.querySelector("[data-dla-local-disclosure]");if(p)p.hidden=!p.hidden;});';
$artifact = ( new ArtifactCompiler() )->compile(array('files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><script data-dla-local-disclosure-runtime="true">' . $runtimeScript . '</script></head><body><footer><p>Copyright <span class="anchor"><button type="button" aria-expanded="false" aria-controls="pn">Note</button><span id="pn" hidden role="region" data-dla-local-disclosure="true">Panel text</span></span></p></footer></body></html>'),
)))->toArray();
$parity = $artifact['source_reports']['runtime_dependency_parity'] ?? array();
$missing = array_values(array_filter($parity['findings'] ?? array(), static fn (array $finding): bool => 'runtime_dependency_target_missing' === ($finding['code'] ?? '') && ! isset($finding['disposition'])));
$assert(str_contains((string) ($artifact['serialized_blocks'] ?? ''), '<!-- wp:details'), 'artifact: the widget is a native details block', (string) ($artifact['serialized_blocks'] ?? ''));
$assert(array() === $missing, 'artifact: the capture runtime selector is superseded, not a missing target', (string) json_encode($missing));

if ( $failures > 0 ) {
    exit(1);
}
echo "inline-wrapper-disclosure: ok\n";

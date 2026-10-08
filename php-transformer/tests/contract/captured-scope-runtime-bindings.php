<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/*
 * A captured document scope is a rendered snapshot whose runtime is declared
 * through attribute bindings. Native owners retain those bindings element by
 * element, so they must not turn a whole widget into one core/html island.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$islands = static fn (array $result, string $kind): array => array_values(array_filter(
    $result['source_reports']['runtime_islands'] ?? array(),
    static fn (array $island): bool => $kind === ($island['kind'] ?? '')
));

$disclosureRuntime = '<script data-capture-disclosure-runtime="true">'
    . 'document.querySelectorAll("[data-dla-dialog-trigger]").forEach(function(trigger){'
    . 'trigger.addEventListener("click",function(){var panel=document.getElementById(trigger.getAttribute("aria-controls"));if(panel){panel.hidden=!panel.hidden;}});});'
    . 'document.querySelectorAll("[data-dla-dialog-close]").forEach(function(close){close.addEventListener("click",function(){close.hidden=true;});});'
    . '</script>';

$card = static fn (int $index): string => '<article class="feed-card"><h2><a href="/post/entry-' . $index . '">Entry ' . $index . '</a></h2>'
    . '<p>Summary for entry ' . $index . '.</p>'
    . '<button type="button" aria-label="More actions" aria-haspopup="menu" data-dla-dialog-trigger="entry-menu-' . $index . '" aria-controls="entry-menu-' . $index . '" aria-expanded="false">More</button></article>';
$feed = '<div class="feed-root blog-surface is-desktop app-desktop">' . $card(1) . $card(2) . $card(3)
    . '<button type="button" hidden data-dla-dialog-close="entry-menu-1">Close</button></div>';
$page = static fn (string $body): string => '<!doctype html><html><head><title>Journal</title>'
    . '<style>.feed-root{display:flex;flex-direction:column;gap:24px}.feed-card{padding:16px;border:1px solid #d0d0d0}</style>'
    . '</head><body>' . $body . $disclosureRuntime . '</body></html>';
$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();

// 1. A builder widget root whose class tokens look like an app root, inside a
// declared capture scope, stays editable: its attribute-bound controls are
// retained by native blocks instead of preserving the subtree as core/html.
$scoped = $compile($page('<div class="captured-document" data-dla-document-scope="" data-dla-device-document="desktop"><main>' . $feed . '</main></div>'));
$scopedMarkup = (string) ($scoped['serialized_blocks'] ?? '');
$assert(0 === substr_count($scopedMarkup, '<!-- wp:html'), 'A captured-scope widget with attribute-bound controls emits no core/html: ' . $scopedMarkup);
$assert(array() === $islands($scoped, 'app_shell'), 'Attribute bindings inside a captured scope are not app-shell evidence: ' . json_encode($islands($scoped, 'app_shell')));
$assert(0 === (int) ($scoped['source_reports']['core_html_fallback_evidence']['totals']['emissions'] ?? -1), 'Core HTML fallback evidence reports no emissions for the captured-scope widget.');
$assert(str_contains($scopedMarkup, '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading"><a href="/post/entry-2">Entry 2</a></h2>'), 'Widget headings materialize as native heading blocks.');
$assert(str_contains($scopedMarkup, '<!-- wp:paragraph --><p>Summary for entry 3.</p>'), 'Widget summaries materialize as native paragraph blocks.');
$assert(3 === substr_count($scopedMarkup, 'data-dla-dialog-trigger="entry-menu-'), 'Every attribute-bound trigger keeps its runtime binding on the frontend.');
$assert(str_contains($scopedMarkup, 'data-dla-dialog-close="entry-menu-1"'), 'The attribute-bound close control keeps its runtime binding.');
$assert(str_contains($scopedMarkup, 'feed-root blog-surface is-desktop app-desktop'), 'The widget root keeps its source classes on a native carrier.');

// 2. The same widget outside a declared capture scope keeps the established
// app-shell treatment; the boundary is the declared scope, not the markup.
$unscoped = $compile($page('<main>' . $feed . '</main>'));
$assert(array() !== $islands($unscoped, 'app_shell'), 'Outside a captured scope the app-root widget remains a runtime app shell.');

// 3. Inside a captured scope, targets addressed by identity (class or id
// selectors) are still app-shell evidence.
$identityAddressed = (new HtmlTransformer())->transform(
    '<div class="captured-document" data-dla-document-scope="" data-dla-device-document="desktop"><main><div class="board app-desktop">'
        . '<div class="cell"><p>Cell one</p></div><div class="cell"><p>Cell two</p></div></div></main></div>',
    array('source' => 'index.html', 'runtime_dom_selectors' => array('.cell'), 'runtime_behavioral_selectors' => array('.cell'))
)->toArray();
$assert(array() !== $islands($identityAddressed, 'app_shell'), 'Identity-addressed runtime targets inside a captured scope still mark an app shell.');

// 4. An image-only custom element host bound by captured attribute runtime
// stays with the media owner, which saves the host verbatim, instead of
// becoming a raw core/html island.
$effectsRuntime = '<script data-capture-view-timeline-runtime="">'
    . 'document.querySelectorAll("[data-dla-native-effects]").forEach(function(node){node.animate([{transform:"translateZ(0)"},{transform:"translateZ(-10px)"}],{fill:"both"});});'
    . '</script>';
$host = '<x-media-frame id="hero-frame" class="frame-host bg-fill" data-dla-native-node="n2" data-dla-native-effects="[{&quot;target&quot;:&quot;n2&quot;}]">'
    . '<img src="https://media.example.test/hero.jpg" alt="" width="320" height="418"></x-media-frame>';
$hostResult = $compile('<!doctype html><html><head><title>Hero</title><style>.frame-host{position:absolute;inset:0}.hero{position:relative;min-height:418px}</style></head><body>'
    . '<div class="captured-document" data-dla-document-scope="" data-dla-device-document="mobile"><main><section class="hero"><div class="hero-media">' . $host . '</div><h1>Welcome</h1></section></main></div>'
    . $effectsRuntime . '</body></html>');
$hostMarkup = (string) ($hostResult['serialized_blocks'] ?? '');
$assert(0 === substr_count($hostMarkup, '<!-- wp:html'), 'A runtime-bound image-only custom element host emits no core/html: ' . $hostMarkup);
$assert(1 === preg_match('/<!-- wp:[a-z0-9-]+\/responsive-media \{"content":"[^"]*x-media-frame[^"]*data-dla-native-effects/', $hostMarkup), 'The bound host is kept verbatim by responsive media with its runtime attributes.');
$assert(str_contains($hostMarkup, '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Welcome</h1>'), 'Content beside the bound host stays native.');
$hostDomIslands = array_filter($islands($hostResult, 'dom'), static fn (array $island): bool => '#hero-frame' === ($island['selector'] ?? ''));
$assert(array() === $hostDomIslands, 'Responsive media retains the runtime DOM contract natively, so no runtime DOM island is recorded for the host.');

fwrite(STDOUT, "captured-scope-runtime-bindings contract passed\n");

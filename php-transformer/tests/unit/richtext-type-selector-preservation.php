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

$transform = static fn (string $html, string $css): array =>
    ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();

// RichText lowering hoists a `<span class="…">`/`<span style="…">` styling
// hook onto a `<mark>` carrier so its class/style survive RichText's format
// allowlist. A sibling pseudo-element rule (`:before`/`:after`) is outside
// the selector subset `AuthorStylesheetProjector` can retarget at that
// carrier, so it rides through unrewritten, still keyed to the literal
// source tag. Renaming the span would break that rule's ability to match —
// reduced from a captured Weebly nav trigger where `.hamburger span:after`
// paints the visible "MENU" label via generated content.
$navTrigger = $transform(
    '<div class="header-wrap"><div id="topBar" class="topbar">'
    . '<a class="hamburger w-navpane-trigger" aria-label="Menu" href="#"><span></span></a>'
    . '</div></div>'
    . '<div class="nav-wrap"><ul class="wsite-menu-default">'
    . '<li><a href="/" class="wsite-menu-item">Home</a></li>'
    . '<li><a href="/about-me/" class="wsite-menu-item">About Me</a></li>'
    . '</ul></div>',
    '.hamburger { position: relative; display: none; padding: 0 20px; width: 100px; }'
    . '.hamburger span { position: relative; display: block; color: #fff; text-align: center; }'
    . '.hamburger span:after { display: block; color: #fff; font-weight: bold; content: \'\MENU\'; }'
);
$serialized = (string) ($navTrigger['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/hamburger[^>]*>\s*<span/i', $serialized),
    'a span whose own tag name is the operative selector for an unprojected pseudo-element rule keeps its literal <span> tag instead of becoming <mark>'
);
$assert(
    0 === preg_match('/hamburger[^>]*>\s*<mark/i', $serialized),
    'the same span is never lowered to <mark>, which would silently orphan the `:after` type selector'
);

// The companion resting-state rule still needs to reach the element: the
// marker attribute AuthorSelectorSemanticPreparer assigned for it survives
// on the untouched span (RichTextMarkerSelector's carrier list matches
// either shape), so `.hamburger span { … }` keeps resolving too.
$assert(
    1 === preg_match('/<span data-blocks-engine-richtext-marker="[^"]+"><\/span>/i', $serialized),
    'the preserved span still carries its RichText marker attribute so the non-pseudo companion rule keeps matching via the span[data-…] carrier form'
);

$blockValidity = ( new BlockValidityValidator() )->validateBlocks($navTrigger['blocks'] ?? array());
$assert(
    'pass' === ($blockValidity['status'] ?? ''),
    'the preserved-span markup stays Gutenberg-valid'
);

// A span with no author-CSS dependency on its own tag name is unaffected:
// class/style still hoist onto a `<mark>` carrier as before.
$ordinaryHook = $transform(
    '<p>Say <span class="callout">hello</span> there</p>',
    '.callout { color: #b00; font-weight: bold; }'
);
$ordinarySerialized = (string) ($ordinaryHook['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/<mark class="callout"[^>]*>hello<\/mark>/i', $ordinarySerialized),
    'an ordinary styling-hook span with no pseudo-element tag dependency still lowers to a <mark> carrier'
);

// A `<strong>`/`<em>`/… RichText format tag is never renamed by this
// lowering (only a `<mark>` carrier nests inside it), so a pseudo-element
// rule keyed to one of those tags is unaffected by this guard either way.
$formatTag = $transform(
    '<p><strong class="tag">Label</strong> text</p>',
    'strong.tag:after { content: "*"; } strong.tag { color: red; }'
);
$formatSerialized = (string) ($formatTag['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/<strong><mark class="tag"/i', $formatSerialized),
    'a native RichText format tag (strong) keeps its own tag name regardless of pseudo-element dependency, since it is never renamed'
);

if ( 0 < $failures ) {
    exit(1);
}

echo "richtext type-selector preservation unit tests: {$passes} passed\n";

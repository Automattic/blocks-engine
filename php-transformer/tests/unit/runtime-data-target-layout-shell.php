<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$names = static function (array $blocks) use (&$names): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( is_array($block) ) {
            $found[] = (string) ($block['blockName'] ?? '');
            $found = array_merge($found, $names(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()));
        }
    }

    return $found;
};

// A script reveals sections by swapping the classes recorded in a JSON data attribute.
$entrance = htmlspecialchars((string) json_encode(array(
    'attributes' => array( 'class' => array(
        'before' => 'reveal grid transition-all duration-700 opacity-0 translate-y-8',
        'after'  => 'reveal grid transition-all duration-700 opacity-100 translate-y-0',
    ) ),
    'rootMargin' => '0px',
    'threshold'  => array( 0.15 ),
    'repeat'     => false,
)), ENT_QUOTES);
$css = '.reveal{max-width:80rem;margin:0 auto}.opacity-0{opacity:0}.opacity-100{opacity:1}.transition-all{transition:all .7s}.translate-y-8{transform:translateY(2rem)}.translate-y-0{transform:none}';
$script = 'document.querySelectorAll("[data-blocks-engine-capture-viewport-entrance]").forEach(function(el){var c=JSON.parse(el.getAttribute("data-blocks-engine-capture-viewport-entrance"));el.setAttribute("class",c.attributes.class.after);});';
$page = static fn (string $body): string => '<!doctype html><html><head><title>Reveal</title><style>' . $css . '</style></head><body><main>' . $body . '</main><script>' . $script . '</script></body></html>';
$entranceAttr = 'data-blocks-engine-capture-viewport-entrance="' . $entrance . '"';

$compiled = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array( 'index.html' => $page(
        '<section id="intro"><div class="reveal grid transition-all duration-700 opacity-100 translate-y-0" ' . $entranceAttr . '><div><img src="a.png" alt="A"></div><div><h2>Heading</h2><p>Body copy</p></div></div></section>'
    ) ),
))->toArray();
$markup = (string) ( $compiled['serialized_blocks'] ?? '' );
$blockNames = $names($compiled['blocks'] ?? array());
$contractFailures = array_values(array_filter($compiled['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dependency_target_missing' === ($diagnostic['code'] ?? '')));

$assert('success' === ($compiled['status'] ?? ''), 'script-addressed container compiles: ' . json_encode($contractFailures));
$assert(! in_array('core/html', $blockNames, true) && ! str_contains($markup, '<!-- wp:html'), 'a script-addressed data attribute on a plain container does not fall back to core/html: ' . $markup);
$assert(str_contains($markup, 'data-blocks-engine-capture-viewport-entrance=') && str_contains($markup, 'translate-y-8'), 'the data attribute and its recorded states survive on the saved markup');
$assert(array() === $contractFailures, 'the runtime dependency gate finds the script-addressed target');
$assert(array() !== array_filter($blockNames, static fn (string $name): bool => str_ends_with($name, '/layout-shell')), 'the attribute rides on an editable layout-shell wrapper');
$assert(in_array('core/heading', $blockNames, true) && in_array('core/paragraph', $blockNames, true) && in_array('core/image', $blockNames, true), 'the wrapped content stays native editable blocks');
$assert('pass' === ( new BlockValidityValidator() )->validateBlocks($compiled['blocks'] ?? array())['status'], 'layout-shell markup stays Gutenberg-valid');
$assert(array() === array_filter($compiled['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dom_contract_fallback' === ($diagnostic['code'] ?? '')), 'no runtime island fallback is recorded for the container');

// The capture activator's own selector must not make its evidence targets runtime islands.
$nestedCapture = '<!doctype html><html><body><div class="a"><div class="b"><p class="preFade fadeIn" data-blocks-engine-capture-viewport-entrance="' . htmlspecialchars((string) json_encode(array('attributes' => array('class' => array('before' => 'preFade', 'after' => 'preFade fadeIn')))), ENT_QUOTES) . '">Hello</p><p>Plain sibling</p></div></div>'
    . '<script data-blocks-engine-capture-viewport-entrances>(function(){for(const r of document.querySelectorAll("[data-blocks-engine-capture-viewport-entrance]")){}})();</script></body></html>';
$captureContext = ( new ArtifactCompiler() )->runtimeContextForSource($nestedCapture, 'index.html', array(array('path' => 'index.html', 'content' => $nestedCapture)));
$captureResult = ( new ArtifactCompiler() )->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $nestedCapture)))->toArray();
$captureNames = $names($captureResult['blocks'] ?? array());
$assert(! in_array('[data-blocks-engine-capture-viewport-entrance]', $captureContext['runtime_dom_selectors'] ?? array(), true), 'capture activator is excluded from source runtime selector analysis');
$assert(2 === count(array_filter($captureNames, static fn (string $name): bool => 'core/paragraph' === $name)) && ! in_array('core/html', $captureNames, true), 'entrance target and sibling paragraphs lower natively inside nested shells: ' . json_encode($captureNames));
$assert(str_contains((string) ($captureResult['serialized_blocks'] ?? ''), '<p class="preFade fadeIn">Hello</p>'), 'entrance target settles to its captured terminal state');

// A two-pane media/text target must not become a core/media-text, which cannot carry the attribute.
$twoPane = ( new HtmlTransformer() )->transform(
    '<style>.two{display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:center}.frame{aspect-ratio:4/5;overflow:hidden;border-radius:1rem}.cover{width:100%;height:100%;object-fit:cover}.copy{max-width:32rem}</style>'
    . '<section id="about"><div class="two" ' . $entranceAttr . '><div class="frame"><img class="cover" src="a.png" alt="Portrait"></div><div class="copy"><h2>Title</h2><p>Body</p></div></div></section>',
    array( 'runtime_dom_selectors' => array( '[data-blocks-engine-capture-viewport-entrance]' ) )
)->toArray();
$twoPaneMarkup = (string) ( $twoPane['serialized_blocks'] ?? '' );
$assert(str_contains($twoPaneMarkup, 'data-blocks-engine-capture-viewport-entrance=') && ! str_contains($twoPaneMarkup, 'wp:media-text') && ! str_contains($twoPaneMarkup, 'wp:html'), 'a two-pane target keeps its attribute instead of becoming media-text: ' . $twoPaneMarkup);

// A target that wraps an unaddressed control keeps its attribute.
$island = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array( 'index.html' => $page(
        '<div class="reveal" ' . $entranceAttr . '><button type="button">Act</button></div>'
    ) ),
))->toArray();
$assert(str_contains((string) ( $island['serialized_blocks'] ?? '' ), 'data-blocks-engine-capture-viewport-entrance='), 'a target wrapping controls still keeps its attribute');

// An empty mount point a script fills keeps its hook on a shell, not a core/html island.
$mount = ( new HtmlTransformer() )->transform(
    '<main><div class="slot" data-blocks-engine-capture-viewport-entrance="{}"></div></main>',
    array( 'runtime_dom_selectors' => array( '[data-blocks-engine-capture-viewport-entrance]' ) )
)->toArray();
$mountMarkup = (string) ( $mount['serialized_blocks'] ?? '' );
$assert(! str_contains($mountMarkup, 'wp:html') && str_contains($mountMarkup, '/layout-shell') && str_contains($mountMarkup, '<div class="slot" data-blocks-engine-capture-viewport-entrance="{}"></div>'), 'an empty script-populated container saves its exact mount point in a layout shell: ' . $mountMarkup);

// A hidden panel a script reveals keeps `hidden` on its shell; its content stays editable blocks.
$panel = ( new HtmlTransformer() )->transform(
    '<main><div class="cart-empty" hidden data-cart-empty><h2>Your cart is empty</h2><p>Nothing here yet.</p><nav><a href="#top">Top</a></nav></div></main>',
    array( 'runtime_dom_selectors' => array( '[data-cart-empty]' ) )
)->toArray();
$panelMarkup = (string) ( $panel['serialized_blocks'] ?? '' );
$panelNames = $names($panel['blocks'] ?? array());
$assert(! in_array('core/html', $panelNames, true) && str_contains($panelMarkup, '<div class="cart-empty" data-cart-empty="" hidden="">'), 'a hidden script-toggled panel saves its hidden hook on a layout shell: ' . $panelMarkup);
$assert(in_array('core/heading', $panelNames, true) && in_array('core/paragraph', $panelNames, true) && in_array('core/navigation-link', $panelNames, true), 'the hidden panel content stays editable blocks with its links: ' . json_encode($panelNames));

// A filter group whose buttons the script finds by their own data hooks keeps every
// button as a native authored button inside the shell.
$filters = ( new HtmlTransformer() )->transform(
    '<main><div class="cat-filters" data-cat-filters role="group"><button type="button" class="cat-btn active" data-cat="all">All</button><button type="button" class="cat-btn" data-cat="Indoor">Indoor</button></div></main>',
    array( 'runtime_dom_selectors' => array( '[data-cat-filters]', '[data-cat]' ) )
)->toArray();
$filtersMarkup = (string) ( $filters['serialized_blocks'] ?? '' );
$assert(! str_contains($filtersMarkup, 'wp:html') && str_contains($filtersMarkup, '<div class="cat-filters" data-cat-filters="" role="group">') && 2 === substr_count($filtersMarkup, '<!-- wp:custom/authored-button') && str_contains($filtersMarkup, 'data-cat="Indoor"'), 'a data-addressed control group stays a shell of native buttons that keep their hooks: ' . $filtersMarkup);

// A control the script finds by class (whose state it rewrites) keeps the group one island.
$classAddressed = ( new HtmlTransformer() )->transform(
    '<main><div data-chip-group><button class="chip is-on">All</button><button class="chip">New</button></div></main>',
    array( 'runtime_dom_selectors' => array( '[data-chip-group]', '.chip' ) )
)->toArray();
$assert(str_contains((string) ( $classAddressed['serialized_blocks'] ?? '' ), '<!-- wp:html --><div data-chip-group>'), 'a group of class-addressed controls stays one runtime island: ' . ( $classAddressed['serialized_blocks'] ?? '' ));

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "runtime data target layout-shell tests passed\n";

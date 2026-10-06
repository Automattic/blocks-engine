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
$script = 'document.querySelectorAll("[data-dla-viewport-entrance]").forEach(function(el){var c=JSON.parse(el.getAttribute("data-dla-viewport-entrance"));el.setAttribute("class",c.attributes.class.after);});';
$page = static fn (string $body): string => '<!doctype html><html><head><title>Reveal</title><style>' . $css . '</style></head><body><main>' . $body . '</main><script>' . $script . '</script></body></html>';
$entranceAttr = 'data-dla-viewport-entrance="' . $entrance . '"';

$compiled = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array( 'index.html' => $page(
        '<section id="intro"><div class="reveal grid transition-all duration-700 opacity-100 translate-y-0" ' . $entranceAttr . '><div><img src="a.png" alt="A"></div><div><h2>Heading</h2><p>Body copy</p></div></div></section>'
    ) ),
))->toArray();
$markup = (string) ( $compiled['serialized_blocks'] ?? '' );
$blockNames = $names($compiled['blocks'] ?? array());
$contractFailures = array_values(array_filter($compiled['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dependency_contract_failed' === ($diagnostic['code'] ?? '')));

$assert('success' === ($compiled['status'] ?? ''), 'script-addressed container compiles: ' . json_encode($contractFailures));
$assert(! in_array('core/html', $blockNames, true) && ! str_contains($markup, '<!-- wp:html'), 'a script-addressed data attribute on a plain container does not fall back to core/html: ' . $markup);
$assert(str_contains($markup, 'data-dla-viewport-entrance=') && str_contains($markup, 'translate-y-8'), 'the data attribute and its recorded states survive on the saved markup');
$assert(array() === $contractFailures, 'the runtime dependency gate finds the script-addressed target');
$assert(array() !== array_filter($blockNames, static fn (string $name): bool => str_ends_with($name, '/layout-shell')), 'the attribute rides on an editable layout-shell wrapper');
$assert(in_array('core/heading', $blockNames, true) && in_array('core/paragraph', $blockNames, true) && in_array('core/image', $blockNames, true), 'the wrapped content stays native editable blocks');
$assert('pass' === ( new BlockValidityValidator() )->validateBlocks($compiled['blocks'] ?? array())['status'], 'layout-shell markup stays Gutenberg-valid');
$assert(array() === array_filter($compiled['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dom_contract_fallback' === ($diagnostic['code'] ?? '')), 'no runtime island fallback is recorded for the container');

// A two-pane media/text target must not become a core/media-text, which cannot carry the attribute.
$twoPane = ( new HtmlTransformer() )->transform(
    '<style>.two{display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:center}.frame{aspect-ratio:4/5;overflow:hidden;border-radius:1rem}.cover{width:100%;height:100%;object-fit:cover}.copy{max-width:32rem}</style>'
    . '<section id="about"><div class="two" ' . $entranceAttr . '><div class="frame"><img class="cover" src="a.png" alt="Portrait"></div><div class="copy"><h2>Title</h2><p>Body</p></div></div></section>',
    array( 'runtime_dom_selectors' => array( '[data-dla-viewport-entrance]' ) )
)->toArray();
$twoPaneMarkup = (string) ( $twoPane['serialized_blocks'] ?? '' );
$assert(str_contains($twoPaneMarkup, 'data-dla-viewport-entrance=') && ! str_contains($twoPaneMarkup, 'wp:media-text') && ! str_contains($twoPaneMarkup, 'wp:html'), 'a two-pane target keeps its attribute instead of becoming media-text: ' . $twoPaneMarkup);

// A target that wraps interactive controls keeps the bounded island treatment.
$island = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array( 'index.html' => $page(
        '<div class="reveal" ' . $entranceAttr . '><button type="button">Act</button></div>'
    ) ),
))->toArray();
$assert(str_contains((string) ( $island['serialized_blocks'] ?? '' ), 'data-dla-viewport-entrance='), 'a target wrapping controls still keeps its attribute');

// An empty script-populated mount point is not editable content and stays a runtime island.
$mount = ( new HtmlTransformer() )->transform(
    '<main><div class="slot" data-dla-viewport-entrance="{}"></div></main>',
    array( 'runtime_dom_selectors' => array( '[data-dla-viewport-entrance]' ) )
)->toArray();
$assert(str_contains((string) ( $mount['serialized_blocks'] ?? '' ), 'wp:html') && ! str_contains((string) ( $mount['serialized_blocks'] ?? '' ), '/layout-shell'), 'an empty script-populated container stays a runtime island');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "runtime data target layout-shell tests passed\n";

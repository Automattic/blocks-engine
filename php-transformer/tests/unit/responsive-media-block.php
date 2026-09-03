<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveMediaBlockGenerator;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$generator = new ResponsiveMediaBlockGenerator();
$definition = $generator->definition('ssi-example');
$assert('ssi-example/responsive-media' === ($definition['block_json']['name'] ?? null), 'one namespaced responsive-media block type is defined');
$assert(false === ($definition['block_json']['supports']['html'] ?? null), 'the companion disables raw HTML editing');
$assert('file:./index.js' === ($definition['block_json']['editorScript'] ?? null), 'the companion declares its editor script');
$assert(!isset($definition['block_json']['render']), 'the companion metadata does not reference a producer-authored render asset');
$assert(array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element') === ($definition['script_dependencies']['index.js'] ?? null), 'the companion declares editor dependencies');
$assert(ResponsiveMediaBlockGenerator::RENDERER === ($definition['renderer'] ?? null) && !isset($definition['render']), 'the companion delegates runtime rendering through an audited identifier without producer-authored PHP');
$payload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), array($definition));
$assert(ResponsiveMediaBlockGenerator::RENDERER === ($payload['blocks'][0]['renderer'] ?? null) && !isset($payload['blocks'][0]['render']), 'the audited renderer identifier survives companion payload normalization');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert(str_contains($editor, "registerBlockType( 'ssi-example/responsive-media'") && str_contains($editor, '! props.isSelected') && str_contains($editor, "display: 'none'") && str_contains($editor, 'TextareaControl') && str_contains($editor, 'save: function() { return null; }') && !str_contains($editor, 'RawHTML'), 'the editor hides the captured HTML control until its block is deliberately selected');
$editorSchemaRunner = <<<'JS'
const vm = require( 'node:vm' );
let settings;
vm.runInNewContext( Buffer.from( process.argv[ 1 ], 'base64' ).toString(), {
    window: { wp: {
        blocks: { registerBlockType: ( name, blockSettings ) => { settings = blockSettings; } },
        blockEditor: {}, components: {}, element: {}
    } }
} );
process.stdout.write( JSON.stringify( settings.attributes ) );
JS;
$editorAttributes = json_decode((string) shell_exec('node -e ' . escapeshellarg($editorSchemaRunner) . ' ' . escapeshellarg(base64_encode($editor))), true);
$assert(($definition['block_json']['attributes'] ?? null) === $editorAttributes, 'the editor registration attribute schema exactly matches generated block metadata');
$assert('content' === ($editorAttributes['content']['role'] ?? null), 'the editor registration marks responsive media HTML as Gutenberg content');
$assert('media' === ($editorAttributes['kind']['default'] ?? null), 'the editor registration carries the typed captured-boundary kind');
$assert('string' === ($definition['block_json']['attributes']['kind']['type'] ?? null) && 'media' === ($definition['block_json']['attributes']['kind']['default'] ?? null), 'producer metadata declares the responsive-media boundary kind schema');

$layoutDefinition = ( new ResponsiveLayoutBlockGenerator() )->definition('ssi-example');
$assert('ssi-example/responsive-layout' === ($layoutDefinition['block_json']['name'] ?? null), 'one namespaced responsive-layout block type is defined');
$layoutEditor = (string) ($layoutDefinition['assets']['index.js'] ?? '');
$assert(str_contains($layoutEditor, '! props.isSelected') && str_contains($layoutEditor, "display: 'none'") && !str_contains($layoutEditor, 'RawHTML'), 'responsive layout hides its captured HTML control until deliberately selected');
$assert(array('content') === array_keys($layoutDefinition['block_json']['attributes'] ?? array()), 'responsive layout declares a dedicated content-only schema');
$assert(ResponsiveLayoutBlockGenerator::RENDERER === ($layoutDefinition['renderer'] ?? null), 'responsive layout delegates rendering through its producer-owned capability');
$layoutEditorAttributes = json_decode((string) shell_exec('node -e ' . escapeshellarg($editorSchemaRunner) . ' ' . escapeshellarg(base64_encode($layoutEditor))), true);
$assert(($layoutDefinition['block_json']['attributes'] ?? null) === $layoutEditorAttributes, 'responsive layout editor registration matches generated block metadata');

$source = '<a class="social" href="/profile" target="_blank" rel="noopener" aria-label="Profile"><picture class="hero"><source media="(min-width: 800px)" type="image/webp" srcset="hero,wide.webp 1200w, hero.webp 600w" sizes="100vw"><img class="avatar" src="hero.jpg" srcset="hero.jpg 1x, hero-2x.jpg 2x" sizes="100vw" width="44" height="44" alt="Profile"></picture></a>';
$result = ( new HtmlTransformer() )->transform($source)->toArray();
$assert('custom/responsive-media' === ($result['blocks'][0]['blockName'] ?? null), 'linked responsive media uses the companion');
$repeated = ( new HtmlTransformer() )->transform($source . $source)->toArray();
$assert(2 === count($repeated['blocks'] ?? array()) && 1 === count($repeated['source_reports']['generated_blocks'] ?? array()), 'multiple instances need one generated definition');
$content = (string) ($result['blocks'][0]['attrs']['content'] ?? '');
foreach (array('media="(min-width: 800px)"', 'type="image/webp"', 'hero,wide.webp 1200w, hero.webp 600w', 'sizes="100vw"', 'href="/profile"', 'target="_blank"', 'rel="noopener"', 'aria-label="Profile"', 'width="44"', 'class="avatar"') as $fragment) {
    $assert(str_contains($content, $fragment), 'responsive companion preserves ' . $fragment);
}

$wrappedSource = '<a href="/profile" aria-label="Profile"><wow-image data-image-info="bounded"><img src="profile.png" width="30" height="30" alt="Profile"></wow-image></a>';
$wrapped = ( new HtmlTransformer() )->transform($wrappedSource)->toArray();
$wrappedContent = (string) ($wrapped['blocks'][0]['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($wrapped['blocks'][0]['blockName'] ?? null), 'an image-only custom-element carrier inside a link uses responsive media');
$assert(str_contains($wrappedContent, '<wow-image') && str_contains($wrappedContent, '<img') && str_contains($wrappedContent, 'href="/profile"'), 'the linked custom carrier retains its bounded source markup for WordPress rendering');

$nestedWrappedSource = '<a href="/profile" aria-label="Profile"><div class="crop" style="overflow:hidden"><wow-image data-image-info="bounded"><img src="profile.png" width="30" height="30" alt="Profile"></wow-image></div></a>';
$nestedWrapped = ( new HtmlTransformer() )->transform($nestedWrappedSource)->toArray();
$nestedWrappedContent = (string) ($nestedWrapped['blocks'][0]['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($nestedWrapped['blocks'][0]['blockName'] ?? null), 'a linked custom image behind one text-free presentation wrapper uses responsive media');
$assert(str_contains($nestedWrappedContent, '<div class="crop"') && str_contains($nestedWrappedContent, '<wow-image'), 'the nested carrier retains its presentation wrapper');

$labeledWrapper = ( new HtmlTransformer() )->transform('<a href="/profile"><div><wow-image><img src="profile.png" alt="Profile"></wow-image><span>Profile</span></div></a>')->toArray();
$assert('custom/responsive-media' !== ($labeledWrapper['blocks'][0]['blockName'] ?? null), 'a linked image wrapper with authored label content is not collapsed into responsive media');

$maskedVideoSource = '<div class="masked-video"><svg viewBox="0 0 600 120"><defs><clipPath id="clip-masked-video"><text x="0" y="0">LET&apos;S TALK</text></clipPath></defs></svg><div class="fill-layers-wrapper" style="clip-path:url(&quot;#clip-masked-video&quot;)"><video src="footer.mp4" autoplay muted loop></video></div></div>';
$maskedVideo = ( new HtmlTransformer() )->transform($maskedVideoSource)->toArray();
$maskedVideoBlock = $maskedVideo['blocks'][0] ?? array();
$maskedVideoContent = (string) ($maskedVideoBlock['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($maskedVideoBlock['blockName'] ?? null), 'a Wix-style video and sibling SVG clip definition remain in one captured media boundary');
$assert(str_contains($maskedVideoContent, '<clippath id="clip-masked-video">') && str_contains($maskedVideoContent, '<video src="footer.mp4" autoplay muted loop>'), 'the captured boundary preserves the SVG definition and media consumer');
$assert(! str_contains((string) ($maskedVideo['serialized_blocks'] ?? ''), '<!-- wp:html') && 'pass' === ($maskedVideo['source_reports']['wp_block_validity']['status'] ?? null), 'the dependent composition remains valid generated-block markup');

$nestedMask = ( new HtmlTransformer() )->transform('<div><div><svg><defs><clipPath id="nested-mask"><rect width="10" height="10"></rect></clipPath></defs></svg></div><video src="nested.mp4" style="clip-path:url(#nested-mask)"></video></div>')->toArray();
$assert('custom/responsive-media' === ($nestedMask['blocks'][0]['blockName'] ?? null), 'a nested definition is discovered within the bounded component');

$localMask = ( new HtmlTransformer() )->transform('<div><svg><defs><clipPath id="local-mask"><rect width="10" height="10"></rect></clipPath></defs><g clip-path="url(#local-mask)"></g></svg><video src="plain.mp4"></video></div>')->toArray();
$assert('custom/responsive-media' !== ($localMask['blocks'][0]['blockName'] ?? null), 'an SVG-local fragment stays on native image materialization');

$mismatchedMask = ( new HtmlTransformer() )->transform('<div><svg><defs><clipPath id="defined-mask"><rect width="10" height="10"></rect></clipPath></defs></svg><video src="plain.mp4" style="clip-path:url(#other-mask)"></video></div>')->toArray();
$assert('custom/responsive-media' !== ($mismatchedMask['blocks'][0]['blockName'] ?? null), 'a mismatched fragment stays on native conversion paths');

$layoutHtml = '<main class="puffin-story"><div class="shell">';
for ($depth = 0; $depth < 21; ++$depth) $layoutHtml .= '<div class="layer-' . $depth . '">';
$layoutHtml .= '<h1>Deep story</h1><section data-hook="post-list" style="padding:20px"><ol><li><button type="button">Read more</button><a href="/story" aria-label="Story">Read the story</a></li></ol><wow-image data-hook="image"><img src="story.jpg" alt="Story" fetchpriority="high"></wow-image><svg viewBox="0 0 10 10" role="img" aria-label="Mark"><defs><link rel="stylesheet" href="/layout.css"><path id="mark" d="M0 0L10 10"></path></defs><use href="#mark"></use></svg></section>';
for ($depth = 0; $depth < 21; ++$depth) $layoutHtml .= '</div>';
$layoutHtml .= '</div></main>';
$layout = ( new HtmlTransformer() )->transform($layoutHtml)->toArray();
$layoutBlock = $layout['blocks'][0] ?? array();
$assert('custom/responsive-layout' === ($layoutBlock['blockName'] ?? null) && ! isset($layoutBlock['attrs']['kind']), 'A deep static layout uses its dedicated companion boundary.');
$assert(str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), '<h1>Deep story</h1>') && str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), '<button type="button">Read more</button>') && str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), '<img src="story.jpg" alt="Story" fetchpriority="high">') && str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), '<wow-image data-hook="image">') && str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), '<svg viewbox="0 0 10 10" role="img" aria-label="Mark">') && str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), 'layer-20'), 'A captured Puffin-like layout retains static lists, controls, inert custom elements, media hints, safe SVG, accessibility, and authored selector identity.');
$assert(! str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), '<link'), 'A captured layout removes inert SVG stylesheet carriers after their CSS asset has been projected.');
$assert('pass' === ($layout['source_reports']['wp_block_validity']['status'] ?? null), 'A captured layout boundary remains valid Gutenberg block markup.');
$layoutPayload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), $layout['source_reports']['generated_blocks'] ?? array());
$assert(array( 'content' ) === array_keys($layoutPayload['blocks'][0]['block_json']['attributes'] ?? array()) && ResponsiveLayoutBlockGenerator::RENDERER === ($layoutPayload['blocks'][0]['renderer'] ?? null) && ! isset($layoutPayload['blocks'][0]['render']), 'The companion payload preserves the dedicated typed layout schema and audited renderer only.');

$shallow = ( new HtmlTransformer() )->transform('<main><div><img src="story.jpg" alt="Story"></div></main>')->toArray();
$assert('custom/responsive-media' !== ($shallow['blocks'][0]['blockName'] ?? null), 'A shallow media main remains on native conversion paths.');

$runtimeLayout = ( new HtmlTransformer() )->transform($layoutHtml, array('runtime_dom_selectors' => array('.puffin-story')))->toArray();
$assert('custom/responsive-layout' !== ($runtimeLayout['blocks'][0]['blockName'] ?? null), 'A declared runtime layout boundary remains addressable instead of being captured.');

$nestedRuntimeLayout = ( new HtmlTransformer() )->transform($layoutHtml, array('runtime_dom_selectors' => array('.layer-20')))->toArray();
$assert('custom/responsive-layout' !== ($nestedRuntimeLayout['blocks'][0]['blockName'] ?? null), 'A declared runtime descendant remains addressable instead of being captured.');

foreach (array(
    'form' => array('<form action="/contact"><input name="email"><button>Send</button></form>', null),
    'table' => array('<table><tr><td>Cell</td></tr></table>', '<!-- wp:table'),
    'details' => array('<details><summary>More</summary><p>Details</p></details>', '<!-- wp:details'),
) as $name => $case) {
    list($unsupported, $nativeMarker) = $case;
    $unsupportedHtml = str_replace('</section>', $unsupported . '</section>', $layoutHtml);
    $unsupportedResult = ( new HtmlTransformer() )->transform($unsupportedHtml)->toArray();
    $assert('custom/responsive-layout' !== ($unsupportedResult['blocks'][0]['blockName'] ?? null), 'A deep layout with unsupported ' . $name . ' semantics is not silently captured.');
    if (is_string($nativeMarker)) {
        $assert(str_contains((string) ($unsupportedResult['serialized_blocks'] ?? ''), $nativeMarker), 'An unsupported deep ' . $name . ' remains on its native conversion path.');
    }
}
$formResult = ( new HtmlTransformer() )->transform(str_replace('</section>', '<form action="/contact"><input name="email"></form></section>', $layoutHtml))->toArray();
$assert(array() !== ($formResult['fallbacks'] ?? array()), 'An unsupported deep form produces an observable conversion finding.');

fwrite(STDOUT, "Responsive media companion tests passed\n");

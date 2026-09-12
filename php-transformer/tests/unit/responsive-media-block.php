<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveMediaBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\SvgArtworkBlockGenerator;

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
$assert(array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-server-side-render') === ($definition['script_dependencies']['index.js'] ?? null), 'the companion declares editor dependencies');
$assert(ResponsiveMediaBlockGenerator::RENDERER === ($definition['renderer'] ?? null) && !isset($definition['render']), 'the companion delegates runtime rendering through an audited identifier without producer-authored PHP');
$payload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), array($definition));
$assert(ResponsiveMediaBlockGenerator::RENDERER === ($payload['blocks'][0]['renderer'] ?? null) && !isset($payload['blocks'][0]['render']), 'the audited renderer identifier survives companion payload normalization');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert(str_contains($editor, "registerBlockType( 'ssi-example/responsive-media'") && str_contains($editor, 'ServerSideRender') && str_contains($editor, "httpMethod: 'POST'") && str_contains($editor, 'InspectorControls') && str_contains($editor, 'TextareaControl') && str_contains($editor, 'save: function() { return null; }') && !str_contains($editor, "display: 'none'") && !str_contains($editor, 'RawHTML'), 'the editor presents an audited preview over POST and keeps captured HTML controls in the inspector');
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
$assert(str_contains($layoutEditor, 'ServerSideRender') && str_contains($layoutEditor, "httpMethod: 'POST'") && str_contains($layoutEditor, 'InspectorControls') && str_contains($layoutEditor, 'TextareaControl') && !str_contains($layoutEditor, "display: 'none'") && !str_contains($layoutEditor, 'RawHTML'), 'responsive layout presents an audited preview over POST and keeps captured HTML controls in the inspector');
$assert(array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-server-side-render') === ($layoutDefinition['script_dependencies']['index.js'] ?? null), 'responsive layout declares its preview dependency');
$assert(array('content') === array_keys($layoutDefinition['block_json']['attributes'] ?? array()), 'responsive layout declares a dedicated content-only schema');
$assert(ResponsiveLayoutBlockGenerator::RENDERER === ($layoutDefinition['renderer'] ?? null), 'responsive layout delegates rendering through its producer-owned capability');
$layoutEditorAttributes = json_decode((string) shell_exec('node -e ' . escapeshellarg($editorSchemaRunner) . ' ' . escapeshellarg(base64_encode($layoutEditor))), true);
$assert(($layoutDefinition['block_json']['attributes'] ?? null) === $layoutEditorAttributes, 'responsive layout editor registration matches generated block metadata');

$svgDefinition = ( new SvgArtworkBlockGenerator() )->definition('ssi-example');
$svgEditor = (string) ($svgDefinition['assets']['index.js'] ?? '');
$svgEditorAttributes = json_decode((string) shell_exec('node -e ' . escapeshellarg($editorSchemaRunner) . ' ' . escapeshellarg(base64_encode($svgEditor))), true);
$assert('ssi-example/svg-artwork' === ($svgDefinition['block_json']['name'] ?? null) && SvgArtworkBlockGenerator::RENDERER === ($svgDefinition['renderer'] ?? null), 'SVG artwork declares one namespaced block and audited renderer');
$assert(array('svg') === array_keys($svgDefinition['block_json']['attributes'] ?? array()) && ($svgDefinition['block_json']['attributes'] ?? null) === $svgEditorAttributes, 'SVG artwork metadata and editor share a content-role SVG schema');
$svgPayload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), array($svgDefinition));
$assert(SvgArtworkBlockGenerator::RENDERER === ($svgPayload['blocks'][0]['renderer'] ?? null) && !isset($svgPayload['blocks'][0]['render']), 'SVG artwork payload carries only the audited renderer identifier');

$source = '<a class="social" href="/profile" target="_blank" rel="noopener" aria-label="Profile"><picture class="hero"><source media="(min-width: 800px)" type="image/webp" srcset="hero,wide.webp 1200w, hero.webp 600w" sizes="100vw"><img class="avatar" src="hero.jpg" srcset="hero.jpg 1x, hero-2x.jpg 2x" sizes="100vw" width="44" height="44" alt="Profile"></picture></a>';
$result = ( new HtmlTransformer() )->transform($source)->toArray();
$assert('custom/responsive-media' === ($result['blocks'][0]['blockName'] ?? null), 'linked responsive media uses the companion');
$repeated = ( new HtmlTransformer() )->transform($source . $source)->toArray();
$assert(2 === count($repeated['blocks'] ?? array()) && 1 === count($repeated['source_reports']['generated_blocks'] ?? array()), 'multiple instances need one generated definition');
$content = (string) ($result['blocks'][0]['attrs']['content'] ?? '');
foreach (array('media="(min-width: 800px)"', 'type="image/webp"', 'hero,wide.webp 1200w, hero.webp 600w', 'sizes="100vw"', 'href="/profile"', 'target="_blank"', 'rel="noopener"', 'aria-label="Profile"', 'width="44"', 'class="avatar"') as $fragment) {
    $assert(str_contains($content, $fragment), 'responsive companion preserves ' . $fragment);
}

$editableSource = '<media-frame style="display:block"><img src="profile.png" width="30" height="30" alt="Profile"></media-frame>';
$editable = ( new HtmlTransformer() )->transform($editableSource)->toArray();
$editableAttrs = $editable['blocks'][0]['attrs'] ?? array();
$assert('core/image' === ($editable['blocks'][0]['blockName'] ?? null) && 'Profile' === ($editableAttrs['alt'] ?? null), 'an unlinked, block-displayed inert custom image wrapper promotes to editable core/image');

foreach (array(
    'inline custom host' => '<media-frame><img src="profile.png" width="30" height="30" alt="Profile"></media-frame>',
    'overflow clipping' => '<media-frame style="display:block;overflow:hidden"><img src="profile.png" width="80" height="60" alt="Profile"></media-frame>',
    'crop focus' => '<style>.focus-frame{display:block}.focus-frame img{aspect-ratio:4 / 3;object-fit:cover;object-position:right top}</style><media-frame class="focus-frame"><img src="profile.png" alt="Profile"></media-frame>',
    'wrapper id and target reference' => '<button aria-controls="profile-frame"></button><media-frame id="profile-frame" style="display:block"><img src="profile.png" alt="Profile"></media-frame>',
    'wrapper aria label' => '<media-frame aria-label="Profile image" style="display:block"><img src="profile.png" alt="Profile"></media-frame>',
    'wrapper data hook' => '<media-frame data-hook="profile-image" style="display:block"><img src="profile.png" alt="Profile"></media-frame>',
) as $name => $source) {
    $candidate = ( new HtmlTransformer() )->transform($source)->toArray();
    $assert(in_array('custom/responsive-media', array_column($candidate['blocks'] ?? array(), 'blockName'), true), $name . ' remains responsive media rather than silently losing host semantics');
}

$inlineFlow = ( new HtmlTransformer() )->transform('<p>Before <media-frame><img src="profile.png" width="30" height="30" alt="Profile"></media-frame><svg aria-hidden="true" viewBox="0 0 1 1"><path d="M0 0"></path></svg> after</p>')->toArray();
$assert('core/html' === ($inlineFlow['blocks'][0]['blockName'] ?? null) && str_contains((string) ($inlineFlow['blocks'][0]['attrs']['content'] ?? ''), '<media-frame><img'), 'an inline custom host with adjacent text and icon remains in its original inline carrier');

$wrappedSource = '<a class="profile-link" href="/profile" target="_blank" rel="noopener"><media-frame class="profile-frame" data-image-info="bounded"><img class="profile-image" src="profile.png" width="30" height="30" alt="Profile"></media-frame></a>';
$wrapped = ( new HtmlTransformer() )->transform($wrappedSource)->toArray();
$wrappedContent = (string) ($wrapped['blocks'][0]['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($wrapped['blocks'][0]['blockName'] ?? null), 'a linked custom image wrapper remains responsive media because crop cannot preserve its link presentation');
$assert(str_contains($wrappedContent, '<a class="profile-link" href="/profile" target="_blank" rel="noopener"><media-frame class="profile-frame"') && str_contains($wrappedContent, '<img class="profile-image" src="profile.png" width="30" height="30" alt="Profile">'), 'the retained linked wrapper preserves its link and presentation attributes');

$nestedWrappedSource = '<a href="/profile"><div class="crop" style="overflow:hidden"><media-frame data-image-info="bounded"><img src="profile.png" width="30" height="30" alt="Profile"></media-frame></div></a>';
$nestedWrapped = ( new HtmlTransformer() )->transform($nestedWrappedSource)->toArray();
$nestedWrappedContent = (string) ($nestedWrapped['blocks'][0]['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($nestedWrapped['blocks'][0]['blockName'] ?? null), 'a linked image behind additional presentation topology remains responsive media');
$assert(str_contains($nestedWrappedContent, '<div class="crop"') && str_contains($nestedWrappedContent, '<media-frame'), 'the retained carrier preserves its nested presentation wrapper');

$artDirectedWrapper = ( new HtmlTransformer() )->transform('<a href="/profile"><media-frame><picture><source media="(min-width: 800px)" srcset="profile-wide.png 800w"><img src="profile.png" alt="Profile"></picture></media-frame></a>')->toArray();
$artDirectedContent = (string) ($artDirectedWrapper['blocks'][0]['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($artDirectedWrapper['blocks'][0]['blockName'] ?? null) && str_contains($artDirectedContent, '<source media="(min-width: 800px)" srcset="profile-wide.png 800w">'), 'art-directed custom wrappers remain responsive media because core/image cannot preserve picture source selection');

$srcsetWrapper = ( new HtmlTransformer() )->transform('<a href="/profile"><media-frame><img src="profile.png" srcset="profile.png 1x, profile-2x.png 2x" sizes="30px" alt="Profile"></media-frame></a>')->toArray();
$srcsetContent = (string) ($srcsetWrapper['blocks'][0]['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($srcsetWrapper['blocks'][0]['blockName'] ?? null) && str_contains($srcsetContent, 'srcset="profile.png 1x, profile-2x.png 2x"') && str_contains($srcsetContent, 'sizes="30px"'), 'responsive candidates remain responsive media because core/image cannot serialize srcset or sizes');

$selectorDependentWrapper = ( new HtmlTransformer() )->transform('<style>.media-frame .media-image{border-radius:50%}</style><a href="/profile"><media-frame class="media-frame"><img class="media-image" src="profile.png" alt="Profile"></media-frame></a>')->toArray();
$assert('custom/responsive-media' === ($selectorDependentWrapper['blocks'][0]['blockName'] ?? null), 'a custom wrapper whose descendant selector would change remains responsive media');

$deferredHost = ( new HtmlTransformer() )->transform('<wow-image class="_wowImage" data-image-info="{&quot;alignType&quot;:&quot;center&quot;,&quot;imageData&quot;:{&quot;name&quot;:&quot;Logo.png&quot;,&quot;url&quot;:&quot;https://cdn.example.test/logo.png&quot;,&quot;alt&quot;:&quot;Logo.png&quot;}}"><picture><img alt="Logo.png"></picture></wow-image>')->toArray();
$deferredContent = (string) ($deferredHost['blocks'][0]['attrs']['content'] ?? $deferredHost['serialized_blocks'] ?? '');
$assert(str_contains($deferredContent, 'src="https://cdn.example.test/logo.png"'), 'a custom image host with JSON image metadata fills a missing img src');
$assert(! str_contains($deferredContent, '<picture><img alt="Logo.png"></picture>') || str_contains($deferredContent, '<img alt="Logo.png" src="https://cdn.example.test/logo.png">') || str_contains($deferredContent, '<img src="https://cdn.example.test/logo.png" alt="Logo.png">'), 'the recovered source is serialized onto the captured img');

$emptyPicture = ( new HtmlTransformer() )->transform('<wow-image class="_wowImage"><picture><img alt="Logo.png"></picture></wow-image>')->toArray();
$emptyContent = (string) ($emptyPicture['blocks'][0]['attrs']['content'] ?? $emptyPicture['serialized_blocks'] ?? '');
$assert(! str_contains($emptyContent, '<picture><img alt="Logo.png"></picture>'), 'a picture with no src and no recoverable metadata is not emitted');

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

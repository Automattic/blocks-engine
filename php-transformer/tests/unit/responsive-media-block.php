<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ResponsiveMediaBlockGenerator;

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
$assert(str_contains($editor, "registerBlockType( 'ssi-example/responsive-media'") && str_contains($editor, 'TextareaControl') && str_contains($editor, 'save: function() { return null; }') && !str_contains($editor, 'RawHTML'), 'the editor registers an editable dynamic block with no unsafe markup preview');
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
$assert(str_contains($wrappedContent, '<wow-image') && str_contains($wrappedContent, '<img') && str_contains($wrappedContent, 'href="/profile"'), 'the linked custom carrier retains its bounded source markup for SSI rendering');

$nestedWrappedSource = '<a href="/profile" aria-label="Profile"><div class="crop" style="overflow:hidden"><wow-image data-image-info="bounded"><img src="profile.png" width="30" height="30" alt="Profile"></wow-image></div></a>';
$nestedWrapped = ( new HtmlTransformer() )->transform($nestedWrappedSource)->toArray();
$nestedWrappedContent = (string) ($nestedWrapped['blocks'][0]['attrs']['content'] ?? '');
$assert('custom/responsive-media' === ($nestedWrapped['blocks'][0]['blockName'] ?? null), 'a linked custom image behind one text-free presentation wrapper uses responsive media');
$assert(str_contains($nestedWrappedContent, '<div class="crop"') && str_contains($nestedWrappedContent, '<wow-image'), 'the nested carrier retains its presentation wrapper');

$labeledWrapper = ( new HtmlTransformer() )->transform('<a href="/profile"><div><wow-image><img src="profile.png" alt="Profile"></wow-image><span>Profile</span></div></a>')->toArray();
$assert('custom/responsive-media' !== ($labeledWrapper['blocks'][0]['blockName'] ?? null), 'a linked image wrapper with authored label content is not collapsed into responsive media');

$layoutHtml = '<main class="story"><div class="shell">';
for ($depth = 0; $depth < 21; ++$depth) $layoutHtml .= '<div class="layer-' . $depth . '">';
$layoutHtml .= '<a href="/story" aria-label="Story"><img src="story.jpg" alt="Story"></a>';
for ($depth = 0; $depth < 21; ++$depth) $layoutHtml .= '</div>';
$layoutHtml .= '</div></main>';
$layout = ( new HtmlTransformer() )->transform($layoutHtml)->toArray();
$layoutBlock = $layout['blocks'][0] ?? array();
$assert('custom/responsive-media' === ($layoutBlock['blockName'] ?? null) && 'layout' === ($layoutBlock['attrs']['kind'] ?? null), 'A deep semantic media main uses the existing companion as a typed layout boundary.');
$assert(str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), 'href="/story"') && str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), 'aria-label="Story"') && str_contains((string) ($layoutBlock['attrs']['content'] ?? ''), 'layer-20'), 'A captured layout boundary retains links, accessibility, and authored selector identity.');
$assert('pass' === ($layout['source_reports']['wp_block_validity']['status'] ?? null), 'A captured layout boundary remains valid Gutenberg block markup.');
$layoutPayload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), $layout['source_reports']['generated_blocks'] ?? array());
$assert(array( 'content', 'kind' ) === array_keys($layoutPayload['blocks'][0]['block_json']['attributes'] ?? array()) && ResponsiveMediaBlockGenerator::RENDERER === ($layoutPayload['blocks'][0]['renderer'] ?? null) && ! isset($layoutPayload['blocks'][0]['render']), 'The companion payload preserves the bounded typed layout schema and audited renderer only.');

$shallow = ( new HtmlTransformer() )->transform('<main><div><img src="story.jpg" alt="Story"></div></main>')->toArray();
$assert('custom/responsive-media' !== ($shallow['blocks'][0]['blockName'] ?? null), 'A shallow media main remains on native conversion paths.');

$runtimeLayout = ( new HtmlTransformer() )->transform($layoutHtml, array('runtime_dom_selectors' => array('.story')))->toArray();
$assert('custom/responsive-media' !== ($runtimeLayout['blocks'][0]['blockName'] ?? null), 'A declared runtime layout boundary remains addressable instead of being captured.');

$nestedRuntimeLayout = ( new HtmlTransformer() )->transform($layoutHtml, array('runtime_dom_selectors' => array('.layer-20')))->toArray();
$assert('custom/responsive-media' !== ($nestedRuntimeLayout['blocks'][0]['blockName'] ?? null), 'A declared runtime descendant remains addressable instead of being captured.');

fwrite(STDOUT, "Responsive media companion tests passed\n");

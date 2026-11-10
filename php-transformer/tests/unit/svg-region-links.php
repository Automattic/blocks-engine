<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
};
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100" width="200" height="100" aria-label="Area map"><title>Choose an area</title><a href="/other/index.html" aria-label="West"><rect width="100" height="100" fill="red"/></a><a href="/other/index.html#east" target="_blank" rel="noopener"><path d="M100 0H200V100H100Z" fill="blue"/></a></svg>';
$result = (new HtmlTransformer())->transform($svg)->toArray();
$block = $result['blocks'][0] ?? array();
$assert('custom/svg-artwork' === ($block['blockName'] ?? ''), 'region links lower to the existing named artwork block');
$markup = $block['attrs']['svg'] ?? '';
$assert(2 === substr_count($markup, '<a ') && str_contains($markup, 'aria-label="Area map"') && str_contains($markup, '<title>Choose an area</title>'), 'all regions and source labels survive');
$assert(!str_contains($result['serialized_blocks'], '<!-- wp:html'), 'linked artwork needs no HTML block');
$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$assert('blocks-engine/svg-artwork/v1' === ($definition['renderer'] ?? ''), 'the importer receives the existing audited artwork renderer');
$assert('SVG Artwork' === ($definition['block_json']['title'] ?? ''), 'the artwork is selectable as a named media block');
$editor = $definition['assets']['index.js'] ?? '';
$assert(str_contains($editor, 'dangerouslySetInnerHTML: { __html: props.attributes.svg') && str_contains($editor, 'save: function() { return null; }'), 'the editor previews the same SVG with no static save markup to invalidate');

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Map</title></head><body><main><h1>Areas</h1>' . $svg . '</main></body></html>',
    'other/index.html' => '<html><head><title>Other</title></head><body><main><h1 id="east">Other area</h1><p>Details</p></main></body></html>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($compiled);
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');
$home = json_encode($pages['index.html'], JSON_UNESCAPED_SLASHES);
$assert(str_contains($home, '/svg-artwork') && !str_contains($home, 'wp:html'), 'public site plan retains the typed block without core/html');
$assert(!str_contains($home, '/other/index.html') && str_contains($home, '/other#east'), 'site-plan link resolution rewrites region destinations and retains fragments');
$canonical = $pages['index.html']['canonical_block_markup'];
preg_match('/<!-- wp:custom\/svg-artwork (\{.*?\}) \/-->/s', $canonical, $match);
$resolved = json_decode($match[1] ?? '{}', true)['svg'] ?? '';
$assert(str_contains($resolved, 'href="/other"') && str_contains($resolved, 'href="/other#east"'), 'both SVG links use the declared WordPress route');

$xlink = (new HtmlTransformer())->transform('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 100"><a xlink:href="/other/"><rect width="100" height="100" fill="red"/></a></svg>')->toArray();
$assert('custom/svg-artwork' === ($xlink['blocks'][0]['blockName'] ?? ''), 'SVG 1.1 xlink:href region links lower to the artwork block too');

$plain = (new HtmlTransformer())->transform('<svg viewBox="0 0 100 100"><rect width="100" height="100" fill="red"/></svg>')->toArray();
$assert('core/image' === ($plain['blocks'][0]['blockName'] ?? ''), 'unlinked artwork stays a native image');
$external = (new HtmlTransformer())->transform('<svg viewBox="0 0 100 100"><a href="/other/"><rect width="100" height="100"/></a><use href="external.svg#shape"/></svg>')->toArray();
$assert('custom/svg-artwork' !== ($external['blocks'][0]['blockName'] ?? ''), 'external resources are not admitted by the region-link exception');
fwrite(STDOUT, "SVG region links tests: passed\n");

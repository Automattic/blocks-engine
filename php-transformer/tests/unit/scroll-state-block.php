<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$config = json_encode(array(
    'thresholdPx' => 80,
    'addClasses' => array('sticky-animate', 'x-c-bg'),
    'removeClasses' => array(),
    'styleTargets' => array(array(
        'selector' => '#logo-1',
        'properties' => array('max-height' => array('rest' => '131px', 'scrolled' => '64px')),
    )),
), JSON_THROW_ON_ERROR);
$configAttr = htmlspecialchars($config, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$source = '<html><body><header id="header_stickynav67826" class="hdr" data-blocks-engine-scroll-state="true" data-blocks-engine-scroll-state-config="' . $configAttr . '">'
    . '<img id="logo-1" style="max-height:131px" src="logo.png" alt="logo">'
    . '</header></body></html>';

$result = (new HtmlTransformer())->transform($source, array())->toArray();
$block = $result['blocks'][0] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$blockName = (string) ($block['blockName'] ?? '');

$assert(str_ends_with($blockName, '/scroll-state'), 'a marked element lowers to the scroll-state companion block, not the generic container');
$assert('header_stickynav67826' === ($block['attrs']['anchor'] ?? null), 'the source id is preserved as the block anchor');
$assert('hdr' === ($block['attrs']['className'] ?? null), 'the source class list is preserved');
$assert('header' === ($block['attrs']['tagName'] ?? null), 'a non-div source tag is remembered so save() restores the original element');
$decodedConfig = json_decode((string) ($block['attrs']['config'] ?? ''), true);
$assert(is_array($decodedConfig) && array('sticky-animate', 'x-c-bg') === ($decodedConfig['addClasses'] ?? null) && 80 === ($decodedConfig['thresholdPx'] ?? null), 'the captured threshold and classes round-trip through the block attribute');

$assert(str_contains($markup, '<header id="header_stickynav67826" class="hdr" data-blocks-engine-scroll-state="true"'), 'saved markup restores the original tag, id, and class with the runtime marker');
$assert(str_contains($markup, 'data-blocks-engine-scroll-state-config='), 'saved markup carries the captured evidence for the view script to read');
$assert(str_contains($markup, '<img'), 'the logo child block survives inside the wrapper');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'scroll-state serialization is editor-valid');
$assert($blockName === ((new Runtime())->parseBlocks((new Runtime())->serializeBlocks(array($block)))[0]['blockName'] ?? null), 'the scroll-state block persists through parse and serialize');

$definition = $result['source_reports']['generated_blocks'][0] ?? array();
$view = (string) ($definition['view_js'] ?? '');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert($blockName === ($definition['block_json']['name'] ?? null) && 'file:./view.js' === ($definition['block_json']['viewScript'] ?? null), 'the generated companion registers a plain view script asset (no Interactivity API dependency required)');
$assert(str_contains($editor, "registerBlockType( '" . $blockName . "'"), 'the editor script registers the canonical block name');
$assert(str_contains($view, "data-blocks-engine-scroll-state=\"true\"") && str_contains($view, 'window.scrollY > threshold') && str_contains($view, 'requestAnimationFrame') && str_contains($view, 'classList.toggle') && str_contains($view, 'style.setProperty') && str_contains($view, "':scope'"), 'the runtime is generic: it reads scrollY against a captured threshold and replays captured class/style diffs, including :scope self-targets');

// A plain container without the marker is unaffected.
$plain = (new HtmlTransformer())->transform('<html><body><header id="ordinary" class="hdr"><img id="logo-2" src="logo.png" alt="logo"></header></body></html>', array())->toArray();
$assert('blocks-engine/scroll-state' !== ($plain['blocks'][0]['blockName'] ?? null), 'an unmarked header converts through the ordinary generic path');

fwrite(STDOUT, "Scroll state companion tests passed\n");

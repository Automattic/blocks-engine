<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\AssetMaterializationState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAssetStage;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $element): array => array());
$state = new AssetMaterializationState('', array());
$session->installAssetMaterializationState($state);
$stage = new StylesheetAssetStage($session);

$projection = array(
    'path' => '/css/site.css', 'source_path' => 'source/site.css',
    'content' => '  .site{color:red}  ', 'source_hash' => 'original',
    'media' => 'print', 'type' => 'text/css',
);
$stage->materialize(array(' @layer reset; ', '.before{display:block}'), array('.unused{}'), array('.after{}'), array($projection), true, true);
$stage->generated(array('.editor{}'), 'editor-static-state', 'after-author', 'editor-static-state', 'editor');
$assets = $state->assets();
if (4 !== count($assets)) {
    throw new RuntimeException('Expected before-author, projected author, after-author and editor assets.');
}
foreach ($assets as $asset) {
    if ('css' !== $asset['kind'] || 'stylesheet' !== $asset['role'] || 'text/css' !== $asset['mime_type'] || 'text/css' !== $asset['media_type']
        || false !== $asset['binary'] || 'utf-8' !== $asset['encoding'] || $asset['path'] !== $asset['target_path']
        || strlen($asset['content']) !== $asset['bytes'] || hash('sha256', $asset['content']) !== $asset['hash']) {
        throw new RuntimeException('Stylesheet asset schema changed.');
    }
}
if ("@layer reset; \n\n.before{display:block}\n" !== $assets[0]['content']
    || 'before-author' !== $assets[0]['stylesheet_placement'] || 'engine-support' !== $assets[0]['source']
    || $assets[0]['hash'] !== $assets[0]['source_hash']
    || 'assets/css/engine-support-before-author-' . substr($assets[0]['hash'], 0, 16) . '.css' !== $assets[0]['path']) {
    throw new RuntimeException('Generated pre-author stylesheet identity changed.');
}
if ('css/site.css' !== $assets[1]['path'] || ".site{color:red}\n" !== $assets[1]['content']
    || 'author-css' !== $assets[1]['source'] || 'author' !== $assets[1]['stylesheet_placement']
    || 'source/site.css' !== $assets[1]['source_path'] || 'original' !== $assets[1]['source_hash']
    || 'print' !== $assets[1]['media'] || 'text/css' !== $assets[1]['type']) {
    throw new RuntimeException('Projected author stylesheet metadata changed.');
}
if ('after-author' !== $assets[2]['stylesheet_placement'] || 'editor' !== $assets[3]['stylesheet_target']) {
    throw new RuntimeException('Generated stylesheet placement or editor target changed.');
}

$session->installAssetMaterializationState(new AssetMaterializationState('', array()));
$stage->materialize(array(), array(' .inline{} '), array(), array($projection), false, true);
$inline = $session->assetMaterializationState()->assets();
if (1 !== count($inline) || 'author' !== $inline[0]['stylesheet_placement']
    || ".inline{}\n" !== $inline[0]['content'] || 'assets/css/source-author-' . substr($inline[0]['hash'], 0, 16) . '.css' !== $inline[0]['path']) {
    throw new RuntimeException('Inline author stylesheet or per-run state changed.');
}

fwrite(STDOUT, "Stylesheet asset stage contract passed\n");

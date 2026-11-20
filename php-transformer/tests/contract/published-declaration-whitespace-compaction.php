<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/php-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeAssetWrites;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;

// Exercise the public site-plan write interface: publication must reduce the
// generated carrier payload without feeding different evidence to semantic passes.
$source = '.carrier{ --tokens:  red  ; outline-offset :  3px ;content: "a; b" ;width:calc(100% - 2px) }';
$compact = '.carrier{ --tokens:  red  ;outline-offset:3px;content:"a; b";width:calc(100% - 2px)}';
$assets = array();
foreach (array('engine-support', 'wordpress-compat', 'files', 'inline-style') as $origin) {
    $assets[] = array('kind' => 'css', 'source' => $origin, 'source_path' => $origin . '.css', 'target_path' => $origin . '.css', 'content' => $source);
}
$assets[] = array('kind' => 'css', 'source' => 'engine-support', 'stylesheet_target' => 'frontend', 'source_path' => 'document.css', 'target_path' => 'document.css', 'content' => $source);
$writes = (new ThemeAssetWrites())->writes($assets, new AssetReferenceCanonicalizer(array()));
foreach ($writes as $index => $write) {
    $expected = $index < 2 ? $compact : $source;
    if ($write['payload']['data'] !== $expected) throw new RuntimeException('Publication must compact only generated carrier delimiters: ' . $write['target_path']);
    if ($assets[$index]['content'] !== $source) throw new RuntimeException('Source evidence changed.');
}
// Opaque blocks must not lose token boundaries, comments or CSS escapes.
foreach (array('.x{color: /* boundary */ red}', '.x{--rules:{color:red};color: red}', '.x{content:"\\61 ";color: red}') as $opaque) {
    $asset = $assets[0];
    $asset['content'] = $opaque;
    $write = (new ThemeAssetWrites())->writes(array($asset), new AssetReferenceCanonicalizer(array()))[0];
    if ($write['payload']['data'] !== $opaque) throw new RuntimeException('Opaque declaration block changed.');
}
echo "Published declaration whitespace compaction: ok\n";

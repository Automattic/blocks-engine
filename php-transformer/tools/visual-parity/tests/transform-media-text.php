<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$sourcePath = __DIR__ . '/fixtures/media-text-source.html';
$targetPath = $argv[1] ?? $root . '/tmp/visual-parity-media-text-target.html';
$source = file_get_contents($sourcePath);
if ( false === $source ) {
    throw new RuntimeException('Unable to read media-text source fixture.');
}

$result = ( new HtmlTransformer() )->transform($source)->toArray();
if ( 'failed' === ($result['status'] ?? null) ) {
    throw new RuntimeException('Media-text source fixture transform failed.');
}

$mediaTextCount = 0;
$countMediaText = static function (array $blocks) use (&$countMediaText, &$mediaTextCount): void {
    foreach ( $blocks as $block ) {
        if ( 'core/media-text' === ($block['blockName'] ?? null) ) {
            ++$mediaTextCount;
        }
        if ( is_array($block['innerBlocks'] ?? null) ) {
            $countMediaText($block['innerBlocks']);
        }
    }
};
$countMediaText($result['blocks'] ?? array());
if ( 1 !== $mediaTextCount ) {
    throw new RuntimeException(sprintf('Expected one core/media-text block; found %d.', $mediaTextCount));
}

$stylesheet = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( 'stylesheet' !== ($asset['role'] ?? null) || 'text/css' !== ($asset['mime_type'] ?? null) ) {
        continue;
    }
    $stylesheet .= (string) ($asset['content'] ?? '') . "\n";
}

$coreMediaTextCss = <<<'CSS'
@media (max-width: 600px) {
  .wp-block-media-text.is-stacked-on-mobile {
    grid-template-columns: 100% !important;
  }
}
CSS;

$rendered = ( new Runtime() )->renderBlocks($result['blocks'] ?? array());
$target = '<!doctype html>' . "\n"
    . '<html lang="en">' . "\n"
    . '<head>' . "\n"
    . '  <meta charset="utf-8">' . "\n"
    . '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
    . '  <title>Media Text Visual Parity Target</title>' . "\n"
    . '  <style>' . "\n" . $coreMediaTextCss . "\n" . $stylesheet . '  </style>' . "\n"
    . '</head>' . "\n"
    . '<body>' . "\n" . $rendered . "\n" . '</body>' . "\n"
    . '</html>' . "\n";

$targetDirectory = dirname($targetPath);
if ( ! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory) ) {
    throw new RuntimeException('Unable to create media-text target directory.');
}
if ( false === file_put_contents($targetPath, $target) ) {
    throw new RuntimeException('Unable to write media-text target fixture.');
}

fwrite(STDOUT, sprintf("media-text target written: %s\n", $targetPath));

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-inline-layout.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$css = implode('', array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? '')), 'content'));
if ( ! preg_match('/>\.wp-block-button__link\{[^}]*justify-content:center!important;[^}]*width:100%!important;[^}]*box-sizing:content-box!important/', $css) ) {
    fwrite(STDERR, "FAIL: inline layout must reach the padded native link, retaining the source content-box width\n");
    exit(1);
}
echo "Inline button layout passed\n";

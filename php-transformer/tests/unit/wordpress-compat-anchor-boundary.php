<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-anchor-class-boundary.html');
if (!is_string($html)) throw new RuntimeException('Could not read navigation anchor class-boundary fixture.');
preg_match('/<style>(.*?)<\/style>/s', $html, $style);
$css = (new WordPressCompatCss())->css((string) ($style[1] ?? ''), array(array('kind' => 'html', 'content' => $html)), array());
if (str_contains($css, '__medi.wp-') || str_contains($css, 'navigationblock-navigation')) {
    throw new RuntimeException('Navigation anchor rewrite corrupted a class selector.');
}
if (!str_contains($css, '.wp-block-navigation-item__content')) {
    throw new RuntimeException('Standalone anchor selector was not projected.');
}
echo "WordPress compatibility anchor selector boundaries passed\n";

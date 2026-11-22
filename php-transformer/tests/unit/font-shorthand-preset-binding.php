<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = '<style>body{font-family:Arial,sans-serif}.copy{font:italic 700 18px/1.5 Arial,sans-serif!important}</style><p class="copy">Copy</p>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))
    ->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column($plan['assets'], 'content'));
if (1 !== preg_match('/font:italic 700 18px\/1.5 var\(--wp--preset--font-family--[a-z0-9-]+\)!important/', $css)) {
    throw new RuntimeException('The shorthand family must follow its preset without changing the prefix or priority: ' . $css);
}
echo "Font shorthand preset binding passed\n";

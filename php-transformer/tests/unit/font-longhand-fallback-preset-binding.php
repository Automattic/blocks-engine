<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Longhand binding keeps its established primary-family contract, including
// the authored stack as fallback. Exercise the public compiler.
$source = '<style>body{font-family:"Example Face",Arial,sans-serif}.copy{font-family:"Example Face",Georgia,serif!important}</style>'
    . '<p>Body copy with enough representative text to establish the ordinary body stack rather than the short exception.</p>'
    . '<p>More ordinary body copy.</p><p class="copy">Exception</p>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))
    ->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column($plan['assets'], 'content'));
if (!preg_match('/font-family:var\(--wp--preset--font-family--[a-z0-9-]+,"Example Face",Georgia,serif\)!important/', $css)) {
    throw new RuntimeException('A longhand must bind the primary family and retain its source fallback: ' . $css);
}
echo "Font longhand fallback preset binding passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A shared primary face does not make two stacks interchangeable when that
// face is unavailable. Exercise the public compiler, not the binding helper.
$source = '<style>body{font-family:"Example Face",Arial,sans-serif}.copy{font-family:"Example Face",Georgia,serif!important}</style>'
    . '<p>Body copy with enough representative text to establish the ordinary body stack rather than the short exception.</p>'
    . '<p>More ordinary body copy.</p><p class="copy">Exception</p>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))
    ->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column($plan['assets'], 'content'));
if (!str_contains($css, 'font-family:"Example Face",Georgia,serif!important')) {
    throw new RuntimeException('A longhand with a different fallback stack must remain literal: ' . $css);
}
echo "Font longhand fallback preset binding passed\n";

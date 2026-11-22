<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = '<style>body{font-family:Arial,sans-serif}.copy{font:italic 700 18px/1.5 Arial,sans-serif!important}</style><p class="copy">Copy</p>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))
    ->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column($plan['assets'], 'content'));
if (1 !== preg_match('/font:italic 700 18px\/1.5 var\(--wp--preset--font-family--[a-z0-9-]+,Arial,sans-serif\)!important/', $css)) {
    throw new RuntimeException('The shorthand family must follow its preset without changing the prefix or priority: ' . $css);
}
// CSS assets are also consumed without theme.json; the public compiler
// must retain the literal family when the preset variable is unavailable.
if (!str_contains($css, ',Arial,sans-serif)!important')) {
    throw new RuntimeException('Standalone CSS must retain the source family fallback');
}
// A shared primary face is not proof of identical rendering: the face
// may be unavailable and the distinct fallback is then observable.
$source = '<style>body{font-family:"Example Face",Arial,sans-serif}.copy{font:italic 700 18px/1.5 "Example Face",Georgia,serif!important}</style><p>Body copy with enough representative text to establish the ordinary body stack rather than the short exception.</p><p>More ordinary body copy.</p><p class="copy">Exception</p>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))
    ->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column($plan['assets'], 'content'));
if (!str_contains($css, 'font:italic 700 18px/1.5 "Example Face",Georgia,serif!important')) {
    throw new RuntimeException('A shorthand with a different fallback stack must remain literal: ' . $css);
}
echo "Font shorthand preset binding passed\n";

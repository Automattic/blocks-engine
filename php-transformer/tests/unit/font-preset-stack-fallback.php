<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A bound font stack the preset already holds is not restated as the var()
// fallback on every rule (#6145): theme.json always defines the preset. A
// stack that differs from the preset stays the fallback.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$stack = '"Montserrat",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif';
$source = '<style>body{font-family:' . $stack . '}'
    . '.title{font-family:' . $stack . ';color:#3c47aa}'
    . '.lead{font-family:' . $stack . ';font-weight:700}'
    . '.alt{font-family:"Montserrat",Georgia}</style>'
    . '<p class="title">Title</p><p class="lead">Lead copy</p><p class="alt">Alt copy</p>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))
    ->toArray()['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column($plan['assets'], 'content'));
$assert(1 === preg_match('/\.title\s*\{[^}]*font-family:var\(--wp--preset--font-family--([a-z0-9-]+)\)/', $css, $match), 'The class font binds its preset without restating the stack: ' . $css);
$assert(!str_contains($css, 'var(--wp--preset--font-family--' . $match[1] . ',"Montserrat",-apple-system'), 'No rule restates the preset stack as its fallback: ' . $css);
$assert(str_contains($css, '-apple-system') || str_contains(json_encode($plan) ?: '', '-apple-system'), 'The stack itself stays in the design system');
echo "Font preset stack fallback passed\n";

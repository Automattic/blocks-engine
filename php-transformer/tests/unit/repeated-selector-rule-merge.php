<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A reset written as two rules for one selector list (Tailwind's preflight
// `*,:before,:after`) must ship that list once, while a rule that competes
// with a later repeat keeps the later rule in place.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="lead">Hello</p><p class="note">World</p></main></body></html>',
    'site.css' => '.lead,.note{--reset-a:1}.note{background:red}.lead,.note{box-sizing:border-box;border-width:0}'
        . '.lead{color:red}.note{color:blue}.lead{color:green}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) $asset['content'], '--reset-a')), 'content'));
if ('' === $css) throw new RuntimeException('Author stylesheet missing');
if (!preg_match('/\{--reset-a:1;box-sizing:border-box;border-width:0\}/', $css)) throw new RuntimeException("Repeated selector list must ship once, folded after its first rule:\n" . $css);
$blue = strpos($css, 'color:blue');
$green = strpos($css, 'color:green');
if (false === $blue || false === $green || $green < $blue) throw new RuntimeException("A repeat that competes with a rule between must stay after it:\n" . $css);
if (!str_contains($css, 'background:red')) throw new RuntimeException('Unrelated rule between repeats must survive');
echo "Repeated selector rules are folded without crossing a competing rule\n";

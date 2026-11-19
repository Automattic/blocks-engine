<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;

// A source that is not WordPress output never carries WordPress's `is-` and
// `has-` state classes before conversion: a framework's own state names
// (`.is-invalid`, `.has-validation`) that no site file names are unreachable
// like any other class. Names a script spells stay live, and so do reveal
// states and the WordPress state of a WordPress source.
$css = '.form-control{color:navy}.form-control.is-invalid{color:salmon}.is-invalid~.feedback{color:plum}'
    . '.has-validation{color:khaki}.is-flagged{color:olive}.menu.is-open{color:teal}.wp-block-x{color:gold}';
$static = '<!doctype html><html><head><script>field.classList.add("is-flagged")</script></head><body><input class="form-control"><ul class="menu"></ul></body></html>';
$pruned = (new UnreachableSelectorPruner(array($static), true))->prune($css);
foreach (array('navy' => true, 'salmon' => false, 'plum' => false, 'khaki' => false, 'olive' => true, 'teal' => true, 'gold' => true) as $needle => $expected) {
    if (str_contains($pruned, $needle) !== $expected) throw new RuntimeException('Static source state class: ' . $needle . ' in ' . $pruned);
}

// WordPress output may receive state classes from WordPress's own scripts.
$wordpress = '<!doctype html><html><body><div class="wp-site-blocks"><input class="form-control wp-block-x"></div></body></html>';
$kept = (new UnreachableSelectorPruner(array($wordpress), true))->prune($css);
if (!str_contains($kept, 'salmon') || !str_contains($kept, 'khaki')) throw new RuntimeException('WordPress source state class pruned: ' . $kept);

// Projected sheets keep WordPress state classes the conversion mints.
$projected = (new UnreachableSelectorPruner(array($static)))->prune('.has-background{color:coral}');
if (!str_contains($projected, 'coral')) throw new RuntimeException('Projected WordPress state class pruned: ' . $projected);

// The compiled theme drops the unused framework state rule.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><form><input class="form-control" name="n"></form></main></body></html>',
    'site.css' => '.form-control{border-color:teal}.form-control.is-invalid{border-color:salmon}',
)))->toArray();
$compiled = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (str_contains($compiled, 'salmon') || !str_contains($compiled, 'teal')) throw new RuntimeException('Compiled theme kept an unused framework state class: ' . $compiled);
echo "Source framework state classes need evidence\n";

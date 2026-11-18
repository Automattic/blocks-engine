<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Bootstrap ships RTLCSS annotations ahead of rules. Their `[ {` brackets are
// comment text, not attribute syntax, and must not keep an absent subject's
// rule (and its projections) in the published theme stylesheet.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main class="present">Home</main></body></html>',
    'site.css' => ".present{color:teal}\n/* rtl:options: {\n  \"autoRename\": true,\n  \"stringMap\":[ {\n    \"name\": \"prev-next\"\n  } ]\n} */\n.absent-control-icon{background-color:salmon}",
)))->toArray();
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))));
foreach (array('teal' => true, 'salmon' => false) as $needle => $expected) {
    if (str_contains($css, $needle) !== $expected) throw new RuntimeException('Unexpected commented selector reachability: ' . $needle);
}
echo "Commented selector pruning passed\n";

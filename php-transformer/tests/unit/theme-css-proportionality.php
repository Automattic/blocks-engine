<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = ':root{--ink:#123456;--gap:12px}body{color:var(--ink)}.items .label{color:red;font-weight:600}';
$previousBytes = null;
foreach (array(4, 12) as $count) {
    $files = array('site.css' => $css);
    for ($i = 0; $i < $count; ++$i) {
        $files['page-' . $i . '.html'] = '<html class="route-' . $i . '"><head><title>Page ' . $i . '</title><link rel="stylesheet" href="/site.css"></head><body><main><h1>Page ' . $i . '</h1><div class="items">' . implode('', array_map(static fn(int $n): string => '<p><span class="label">Page ' . $i . ' label ' . $n . '</span> text</p>', range(1, 30))) . '</div></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-0.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $output = implode('', array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
    echo "$count: " . strlen($output) . " bytes\n";
    // Includes frontend/editor projections, preset fallbacks and UA support CSS.
    // Before text-independent marker sharing this fixture emits over 9 KB.
    if (strlen($output) > 12 * strlen($css)) throw new RuntimeException('Theme CSS must stay proportional to source CSS');
    if (null !== $previousBytes && $previousBytes !== strlen($output)) throw new RuntimeException('Adding root identities must not repeat shared rules');
    $previousBytes = strlen($output);
    if (1 !== substr_count($output, ':root{--ink:')) throw new RuntimeException('Root custom properties must be emitted once');
    if (1 !== substr_count($output, 'mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-')) throw new RuntimeException('Repeated labels must use one shared selector');
}

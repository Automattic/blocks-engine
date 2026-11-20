<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\UnreachableSelectorPruner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/unreachable-analysis/page.html');
$live = '.panel{display:block;color:#234567}.copy{font-size:18px}';
$absent = '';
for ($index = 0; $index < 5000; ++$index) {
    $absent .= '.unused-item-' . $index . '{color:red}';
}
$pruner = new UnreachableSelectorPruner(array($html), true);
$results = array();
foreach (array($live, $live . $absent) as $css) {
    $cache = new HtmlTransformerAnalysisCache();
    $results[] = (new HtmlTransformer(analysisCache: $cache))->transform($html, array(
        'static_css' => $css,
        'site_selector_pruner' => $pruner,
    ))->toArray();
    $rules = array_sum(array_map(static fn (array $analysis): int => count($analysis['rules']), $cache->authorSelectorAnalyses));
    if (2 !== $rules || 2 !== $cache->authorSelectorMatchResultBuilds) {
        throw new RuntimeException('Author preparation must analyze and match only the two reachable selectors: ' . $rules . '/' . $cache->authorSelectorMatchResultBuilds);
    }
    foreach ($cache->styles as $analysis) {
        if (2 !== count($analysis['static']) + count($analysis['conditional']) || str_contains(json_encode($analysis), 'unused-item-')) {
            throw new RuntimeException('Conversion cascade must not consume unreachable selectors.');
        }
    }
}
// Asset provenance hashes intentionally describe the full authored text.
foreach ($results as &$result) {
    $result['projected_css'] = array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content');
    if (array() === $result['projected_css'] || '' === $result['serialized_blocks']) {
        throw new RuntimeException('The fixture must exercise block and stylesheet projection.');
    }
}
unset($result);
foreach (array('serialized_blocks', 'projected_css') as $key) {
    if (!array_key_exists($key, $results[0])) throw new RuntimeException('Missing output: ' . $key);
    if ($results[0][$key] !== $results[1][$key]) throw new RuntimeException('Unreachable rules changed ' . $key);
}
echo "Unreachable selector analysis passed: 5002 authored rules, 2 analyzed selectors.\n";

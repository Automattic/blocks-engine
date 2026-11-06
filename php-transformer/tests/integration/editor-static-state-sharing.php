<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><body><main><p>Home</p><a href="other.html">Other</a></main></body></html>',
        'other.html' => '<html><body><main><p>Other</p></main></body></html>',
    ),
))->toArray();
$shared = '.paint{color:red}@media(min-width:1px){.paint{color:blue}}';
$expected = array();
foreach (array('index.html', 'other.html') as $i => $page) {
    // Repeated shared text after an overriding unique rule must replay, not disappear.
    $css = $shared . '.paint{padding:' . ($i + 3) . 'px}' . $shared;
    $expected[$page] = $css;
    $result['source_reports']['compiled_site']['assets'][] = array(
        'path' => 'assets/css/editor-static-state-' . $i . '.css',
        'kind' => 'css', 'source' => 'editor-static-state', 'content' => $css,
        'stylesheet_placement' => 'after-author', 'stylesheet_target' => 'editor',
        'media' => 'screen', 'compilation' => array('scope' => 'page', 'id' => $page),
    );
}
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
WordPressSitePlan::assertValid($plan);
$written = array();
foreach ($plan['writes'] as $write) $written[$write['target_path']] = $write['payload']['data'] ?? '';
$bytes = 0;
foreach ($plan['assets'] as $asset) if ('editor-static-state' === ($asset['source'] ?? null)) $bytes += strlen($written[$asset['target_path']] ?? '');
$before = array_sum(array_map('strlen', $expected));
if ($bytes >= $before) throw new RuntimeException("No byte savings: {$before} -> {$bytes}");
foreach ($expected as $page => $css) {
    $actual = '';
    foreach (WordPressSitePlan::documentStylesheets($plan, $page) as $sheet) {
        if (!str_contains($sheet['target_path'], 'editor-static-state')) continue;
        if ('screen' !== $sheet['media'] || 'editor' !== $sheet['stylesheet_target']) throw new RuntimeException('Delivery contract changed');
        $actual .= $written[$sheet['target_path']];
    }
    if ($css !== $actual) throw new RuntimeException('Ordered rule sequence changed for ' . $page . ': ' . $actual);
}
echo "Editor static-state sharing: {$before} -> {$bytes} bytes; ordered rules unchanged.\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$shared = array('.paint{color:red}', '@media(min-width:1px){.paint{color:blue}}');
$long = array();
for ($i = 0; $i < 50; $i++) $long[] = '.shared-' . $i . '{color:red}';
$cases = array(
    '50 shared rules across 3 pages' => array($long, $long, $long),
    // Shared runs must replay after unique overrides, even when their paths repeat.
    'repeated shared runs around unique runs' => array(
        array_merge($shared, array('.paint{padding:3px}', '.paint{margin:3px}'), $shared),
        array_merge($shared, array('.paint{padding:4px}', '.paint{margin:4px}'), $shared),
    ),
    // Adjacent shared units with different owners must not merge.
    'overlapping owner sets' => array(
        array_merge($shared, $long, array('.a{color:green}', '.b{color:green}')),
        array_merge($shared, $long),
        array_merge($shared, array('.c{color:blue}', '.d{color:blue}')),
    ),
    // Runs are page-local, not a global ordering of distinct shared rules.
    'different page order' => array(
        array_merge($shared, $long),
        array_merge($long, $shared),
        array_merge($shared, $long),
    ),
    'unshared pages stay intact' => array(
        array('.a{color:red}', '.b{color:red}'),
        array('.c{color:blue}', '.d{color:blue}'),
    ),
);

foreach ($cases as $name => $sequences) {
    $files = $expected = $owners = array();
    foreach ($sequences as $i => $rules) {
        $page = 0 === $i ? 'index.html' : 'page-' . $i . '.html';
        $files[$page] = '<html><body><main><p>Page ' . $i . '</p></main></body></html>';
        $expected[$page] = $rules;
        foreach ($rules as $rule) $owners[$rule][$page] = true;
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    foreach ($expected as $page => $rules) {
        $result['source_reports']['compiled_site']['assets'][] = array(
            'path' => 'assets/css/editor-static-state-' . $page . '.css',
            'kind' => 'css', 'source' => 'editor-static-state', 'content' => implode('', $rules),
            'stylesheet_placement' => 'after-author', 'stylesheet_target' => 'editor',
            'media' => 'screen', 'compilation' => array('scope' => 'page', 'id' => $page),
        );
    }
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    WordPressSitePlan::assertValid($plan);
    $written = array();
    foreach ($plan['writes'] as $write) $written[$write['target_path']] = $write['payload']['data'] ?? '';
    $staticPaths = array();
    foreach ($plan['assets'] as $asset) {
        if ('editor-static-state' === ($asset['source'] ?? null)) $staticPaths[$asset['target_path']] = true;
    }
    $bytes = 0;
    $writtenParts = 0;
    foreach ($staticPaths as $path => $_) {
        $bytes += strlen($written[$path] ?? '');
        if ('' !== ($written[$path] ?? '')) $writtenParts++;
    }
    $before = array_sum(array_map(static fn(array $rules): int => strlen(implode('', $rules)), $expected));
    if ('unshared pages stay intact' === $name) {
        if ($bytes !== $before) throw new RuntimeException($name . ': unshared bytes changed');
    } elseif ($bytes >= $before) {
        throw new RuntimeException($name . ": no byte savings: {$before} -> {$bytes}");
    }
    $counts = array();
    foreach ($expected as $page => $rules) {
        $actual = '';
        $count = 0;
        foreach (ThemeBootstrap::documentStylesheets($plan, $page) as $sheet) {
            if (!isset($staticPaths[$sheet['target_path']])) continue;
            if ('screen' !== $sheet['media'] || 'editor' !== $sheet['stylesheet_target']) {
                throw new RuntimeException($name . ': delivery contract changed');
            }
            $actual .= $written[$sheet['target_path']];
            $count++;
        }
        if (implode('', $rules) !== $actual) throw new RuntimeException($name . ': ordered rule sequence changed for ' . $page);
        $runs = 0;
        $previous = null;
        foreach ($rules as $rule) {
            $set = array_keys($owners[$rule]);
            sort($set, SORT_STRING);
            if ($previous !== $set) $runs++;
            $previous = $set;
        }
        if ($count !== $runs) throw new RuntimeException($name . ": {$page} has {$count} parts for {$runs} owner-set runs");
        $counts[] = $count;
    }
    echo "{$name}: {$before} -> {$bytes} bytes; {$writtenParts} written parts; parts/page " . implode('/', $counts) . ".\n";
}

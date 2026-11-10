<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array('site.css' => '.all-copy{width:601px;padding:13px}.subset-copy{width:602px;padding:14px}.unique-copy{width:603px;padding:15px}.other-subset{width:604px;padding:16px}');
$owners = array();
for ($i = 0; $i < 6; ++$i) {
    $path = $i ? 'page-' . $i . '.html' : 'index.html';
    $owners[] = $path;
    $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><div class="all-copy"><p>All pages</p></div>' . ($i < 3 ? '<div class="subset-copy"><p>Subset</p></div>' : '') . ($i >= 2 && $i <= 4 ? '<div class="other-subset"><p>Other subset</p></div>' : '') . (1 === $i ? '<div class="unique-copy"><p>Unique</p></div>' : '') . '</main></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$support = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'engine-support' === ($asset['source'] ?? '')));
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
echo 'Reduction: ' . count($support) . ' support files, ' . array_sum(array_map(static fn(array $asset): int => strlen($asset['content']), $support)) . ' support bytes, ' . strlen($bootstrap) . " functions.php bytes\n";
foreach (array('all-copy' => $owners, 'subset-copy' => array_slice($owners, 0, 3), 'other-subset' => array_slice($owners, 2, 3), 'unique-copy' => array($owners[1])) as $class => $consumers) {
    $rule = '.' . $class . '{box-sizing:content-box}';
    $sheets = array_values(array_filter($support, static fn(array $asset): bool => str_contains($asset['content'], $rule)));
    $assert(1 === count($sheets), 'Each generated rule is written exactly once: ' . $class);
    $actual = array_column($sheets[0]['scopes'], 'source_path');
    sort($actual);
    sort($consumers);
    $assert(count($consumers) === count($owners) ? array(array('kind' => 'global')) === $sheets[0]['scopes'] : $actual === $consumers, 'Resource scopes equal exact rule consumers: ' . $class);
}
$globalSheets = array_values(array_filter($support, static fn(array $asset): bool => array(array('kind' => 'global')) === $asset['scopes']));
$assert(1 === count($globalSheets), 'All-pages rules share one global resource.');
$assert(str_contains($bootstrap, "if ( true )"), 'Global support also loads on WordPress-native routes.');
$assert(str_contains($bootstrap, "in_array( \$blocks_engine_page_uri, ['page-2','page-3','page-4'], true )"), 'A subset membership condition includes exactly its three owning routes.');
$seen = array();
foreach ($support as $asset) {
    $assert('' !== trim($asset['content']), 'Support resources are never empty.');
    if (1 === count($asset['scopes']) && 'global' !== $asset['scopes'][0]['kind']) $assert($owners[1] === $asset['scopes'][0]['source_path'], 'Pages without unique rules have no unique resource.');
    $tree = new Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTree($asset['content']);
    foreach ($tree->rules() as $row) {
        $rule = trim(substr($asset['content'], $row['start'], $row['end'] - $row['start'] + 1));
        $assert(!isset($seen[$rule]), 'No support rule is written twice.');
        $seen[$rule] = true;
    }
}
foreach ($plan['assets'] as $asset) if (in_array($asset['source'] ?? '', array('engine-support', 'editor-static-state'), true)) {
    $assert('' !== trim($asset['content']), 'Generated CSS resources are never empty.');
    $writes = array_column($plan['writes'], null, 'target_path');
    $assert('' !== trim($writes[$asset['target_path']]['payload']['data'] ?? ''), 'Generated CSS writes are never empty.');
}
$byTarget = array_column($plan['assets'], null, 'target_path');
foreach ($owners as $owner) {
    $phase = 0;
    foreach (WordPressSitePlan::documentStylesheets($plan, $owner) as $sheet) {
        $asset = $byTarget[$sheet['target_path']];
        $next = 'engine-support' !== ($asset['source'] ?? '') ? 1 : ('before-author' === ($asset['stylesheet_placement'] ?? '') ? 0 : 2);
        $assert($next >= $phase, 'Document cascade remains before support, author, after support.');
        $phase = $next;
        if ('engine-support' === ($asset['source'] ?? '')) $assert(in_array('global', array_column($asset['scopes'], 'kind'), true) || in_array($owner, array_column($asset['scopes'], 'source_path'), true), 'No unrelated support loads.');
    }
}
preg_match("/add_action\( 'wp_enqueue_scripts', static function \(\): void \{(.*?)\n\}, 1 \);/s", $bootstrap, $callback);
$assert(1 === substr_count($callback[1] ?? '', WordPressSitePlan::DOCUMENT_ID_EXPRESSION), 'Styles resolve the current document exactly once.');
$realisticFiles = require __DIR__ . '/support-owner-set-realistic-fixture.php';
$realistic = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $realisticFiles))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($realistic);
$writes = array_column($realistic['writes'], null, 'target_path');
$repairs = 0;
foreach ($realistic['assets'] as $asset) {
    if (!in_array($asset['source'] ?? '', array('engine-support', 'editor-static-state'), true)) continue;
    if ('editor-static-state' === $asset['source']) ++$repairs;
    $assert('' !== trim($asset['content']), 'Realistic support and repair resources are never empty.');
    $assert('' !== trim($writes[$asset['target_path']]['payload']['data'] ?? ''), 'Realistic support and repair writes are never empty.');
}
$assert($repairs > 0, 'The realistic fixture exercises editor repair sharing.');
echo "Support owner-set allocation passed\n";

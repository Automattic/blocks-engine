<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

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
$byTarget = array_column($plan['assets'], null, 'target_path');
$markup = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
foreach (array('all-copy' => $owners, 'subset-copy' => array_slice($owners, 0, 3), 'other-subset' => array_slice($owners, 2, 3), 'unique-copy' => array($owners[1])) as $class => $consumers) {
    $rule = '.' . $class . '{box-sizing:content-box}';
    $sheets = array_values(array_filter($support, static fn(array $asset): bool => str_contains($asset['content'], $rule)));
    $assert(1 === count($sheets), 'Each generated rule is written exactly once: ' . $class);
    foreach ($owners as $owner) {
        $loads = in_array($sheets[0]['target_path'], array_column(ThemeBootstrap::documentStylesheets($plan, $owner), 'target_path'), true);
        // Folded support reaches other routes only where it matches nothing.
        $assert(in_array($owner, $consumers, true) ? $loads : (!$loads || !str_contains($markup[$owner], $class)), 'Support loads on its consumers and is inert elsewhere: ' . $class);
    }
}
$assert(1 === count($support), 'Adjacent support sheets with one delivery fold into one resource.');
$assert(str_contains($bootstrap, "if ( true )"), 'Global support also loads on WordPress-native routes.');
$seen = array();
foreach ($support as $asset) {
    $assert('' !== trim($asset['content']), 'Support resources are never empty.');
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
foreach ($owners as $owner) {
    $phase = 0;
    foreach (ThemeBootstrap::documentStylesheets($plan, $owner) as $sheet) {
        $asset = $byTarget[$sheet['target_path']];
        $next = 'engine-support' !== ($asset['source'] ?? '') ? 1 : ('before-author' === ($asset['stylesheet_placement'] ?? '') ? 0 : 2);
        $assert($next >= $phase, 'Document cascade remains before support, author, after support.');
        $phase = $next;
    }
}
preg_match("/add_action\( 'wp_enqueue_scripts', static function \(\): void \{(.*?)\n\}, 1 \);/s", $bootstrap, $callback);
$assert(1 === substr_count($callback[1] ?? '', ThemeBootstrap::DOCUMENT_ID_EXPRESSION), 'Styles resolve the current document exactly once.');
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
// A rule whose selector also matches a route outside its owners folds in
// behind a zero-weight gate on its owners' route classes.
$fold = new ReflectionMethod(WordPressSitePlan::class, 'foldDocumentSupport');
$documents = array();
foreach (array('index.html' => 'be-r-aaaa1111', 'page-1.html' => 'be-r-bbbb2222') as $source => $class) {
    $documents[$source] = array('source_path' => $source, 'canonical_block_markup' => '<div class="shared-x"></div>', 'document_metadata' => array('root_attributes' => array('class' => 'be-r ' . $class)));
}
$sheet = static fn(string $content, array $scopes): array => array('source' => 'engine-support', 'kind' => 'css', 'content' => $content, 'scopes' => $scopes, 'stylesheet_placement' => 'after-author', 'source_path' => 'assets/css/engine-support-a.css', 'target_path' => 'assets/assets/css/engine-support-a.css');
$folded = $fold->invoke(null, array($sheet('.shared-x{color:red}', array(array('kind' => 'page', 'source_path' => 'index.html'))), $sheet('.y{color:blue}', array(array('kind' => 'global')))), $documents, array_keys($documents));
$assert(1 === count($folded) && str_starts_with($folded[0]['content'], ':where(:root:is(.be-r-aaaa1111)) .shared-x') && array(array('kind' => 'global')) === $folded[0]['scopes'], 'A rule live outside its owners is gated to them: ' . json_encode($folded));
// Template-part markup renders on every route, so it is evidence too.
foreach ($documents as &$document) $document['canonical_block_markup'] = '<p>Body</p>';
unset($document);
$folded = $fold->invoke(null, array($sheet('.shared-x{color:red}', array(array('kind' => 'page', 'source_path' => 'index.html'))), $sheet('.y{color:blue}', array(array('kind' => 'global')))), $documents, array_keys($documents), '<header class="shared-x"></header>');
$assert(str_starts_with($folded[0]['content'], ':where(:root:is(.be-r-aaaa1111)) .shared-x'), 'A rule live in template parts is gated to its owners.');
echo "Support owner-set allocation passed\n";

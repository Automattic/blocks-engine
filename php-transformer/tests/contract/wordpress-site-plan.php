<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$throws = static function (callable $callback, string $message) use ($assert): void { try { $callback(); } catch (InvalidArgumentException) { return; } $assert(false, $message); };
$writeMap = static function (array $writes): array { $map = array(); foreach ($writes as $write) $map[$write['target_path']] = $write; return $map; };

$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<header><p>Entry Header</p></header><main><img src="assets/logo.svg" srcset="assets/logo.svg 1x, assets/logo.svg 2x"><h1>Home</h1></main><footer><p>Entry Footer</p></footer>',
        'about.html' => '<header><p>About Chrome</p></header><main><img src="assets/logo.svg"><h1>About</h1></main>',
        'parts/sidebar.html' => '<aside><p>Unbound Sidebar</p></aside>',
        'assets/site.css' => '@font-face{font-family:test;src:url(assets/font.woff2)}main{background:url("assets/logo.svg")}',
        'assets/site.js' => 'window.siteAsset="assets/logo.svg";',
        'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        'assets/font.woff2' => 'font-data',
    ),
);
$first = (new ArtifactCompiler())->compile($artifact)->toArray();
$second = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $first['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Compiler emits a complete site plan: ' . json_encode(array($first['source_reports']['wordpress_site_plan_diagnostics'] ?? array(), $first['source_reports']['compiled_site']['template_parts'] ?? array())));
$writes = $writeMap($plan['writes']);

$assert(WordPressSitePlan::SCHEMA === ($plan['schema'] ?? null), 'Compiler projects the v2 canonical WordPress site plan.');
$assert(isset($writes['style.css'], $writes['theme.json'], $writes['functions.php'], $writes['templates/index.html'], $writes['templates/page.html'], $writes['templates/front-page.html'], $writes['parts/header.html'], $writes['parts/footer.html'], $writes['parts/sidebar.html']), 'Plan declares the complete block-theme scaffold.');
$assert(str_contains((string) $writes['style.css']['payload']['data'], 'Theme Name:'), 'Theme stylesheet has a recognition header.');
$assert(3 === (json_decode((string) $writes['theme.json']['payload']['data'], true)['version'] ?? null), 'Theme configuration is parseable and supported.');
$assert(str_contains((string) $writes['templates/front-page.html']['payload']['data'], '"slug":"header"') && str_contains((string) $writes['templates/front-page.html']['payload']['data'], '"slug":"footer"'), 'Front-page template references extracted header and footer parts.');
$assert(!str_contains((string) $writes['templates/page.html']['payload']['data'], '"slug":"header"') && !str_contains((string) $writes['templates/front-page.html']['payload']['data'], '"slug":"sidebar"'), 'Templates do not bind unproven or unbound parts.');
$pagesBySource = array(); foreach ($plan['pages'] as $page) $pagesBySource[$page['source_path']] = $page;
$assert(!str_contains((string) ($pagesBySource['index.html']['canonical_block_markup'] ?? ''), 'Entry Header') && str_contains((string) ($pagesBySource['about.html']['canonical_block_markup'] ?? ''), 'About Chrome'), 'Only extracted entry shell content is removed from page markup: ' . json_encode($pagesBySource));
$assert('site_reading' === ($plan['operations'][0]['kind'] ?? null) && 'index.html' === ($plan['operations'][0]['front_page_source_path'] ?? null), 'Plan declares deterministic front-page desired state.');
$assert(str_contains((string) ($plan['pages'][0]['canonical_block_markup'] ?? ''), '{{wordpress-site-plan:asset:'), 'Canonical page markup uses declared destination-independent references.');
$assert(!isset($plan['pages'][0]['resolved_block_markup']), 'Canonical markup is explicitly distinct from resolved markup.');
$assert(count($plan['reference_tokens']) === count($plan['assets']), 'Every asset has one deterministic resolver token.');
$assert(true === ($plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null), 'Plan exposes dynamic client asset capability limits.');
$assert($plan === ($second['source_reports']['wordpress_site_plan'] ?? null), 'Canonical WordPress site plans are deterministic.');

$resolver = new WordPressSitePlanResolver();
$resolved = $resolver->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site'));
$resolvedAgain = $resolver->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site/'));
$assert($resolved === $resolvedAgain, 'Resolution is deterministic after theme URI normalization.');
$about = (string) $resolved['pages'][0]['resolved_block_markup'];
$assert(str_contains($about, 'https://example.test/wp-content/themes/site/assets/assets/logo.svg'), 'Nested page markup resolves declared assets to the explicit theme URI.');
$resolvedWrites = $writeMap($resolved['writes']);
$assert(str_contains((string) $resolvedWrites['assets/assets/site.css']['payload']['data'], 'https://example.test/wp-content/themes/site/assets/assets/logo.svg'), 'Stylesheet references resolve through the same declared token.');
$assert(str_contains((string) $resolvedWrites['assets/assets/site.js']['payload']['data'], 'https://example.test/wp-content/themes/site/assets/assets/logo.svg'), 'Script metadata references resolve through the same declared token.');

$destination = sys_get_temp_dir() . '/blocks-engine-site-plan-' . bin2hex(random_bytes(6));
foreach ($resolved['writes'] as $write) {
    $path = $destination . '/' . $write['target_path'];
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
}
foreach (array('style.css', 'theme.json', 'functions.php', 'templates/index.html', 'templates/page.html', 'templates/front-page.html', 'parts/header.html', 'parts/footer.html', 'parts/sidebar.html', 'assets/assets/site.css', 'assets/assets/site.js', 'assets/assets/logo.svg', 'assets/assets/font.woff2') as $required) $assert(is_file($destination . '/' . $required), "Materialization writes {$required}.");
$assert(false === str_contains((string) file_get_contents($destination . '/assets/assets/site.css'), WordPressSitePlan::TOKEN_PREFIX), 'Materialized assets contain no unresolved resolver tokens.');
$runtime = array('pages' => array(), 'front_page' => null);
foreach ($resolved['pages'] as $page) $runtime['pages'][$page['reconciliation_identity']] = $page;
foreach ($resolved['operations'] as $operation) if ('site_reading' === $operation['kind']) $runtime['front_page'] = $runtime['pages'][$operation['front_page_reconciliation_identity']] ?? null;
$assert('index.html' === ($runtime['front_page']['source_path'] ?? null), 'Operations apply verbatim to the deterministic runtime harness.');

$throws(static fn() => $resolver->resolve($plan, array()), 'Resolution rejects missing destination context.');
$throws(static fn() => $resolver->resolve($plan, array('theme_uri' => '/themes/site')), 'Resolution rejects relative destination context.');
$throws(static fn() => $resolver->resolve($plan, array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true)), 'Materializers can reject unproven dynamic client assets.');
foreach (array('https://example.test/theme?x=1', 'https://example.test/theme#x', 'https://user@example.test/theme', 'ftp://example.test/theme', 'https:///theme', 'https://example.test/a/../theme', "https://example.test/theme\n") as $uri) $throws(static fn() => $resolver->resolve($plan, array('theme_uri' => $uri)), 'Resolution rejects ambiguous runtime context.');
$undeclared = $plan; $undeclared['pages'][0]['canonical_block_markup'] .= '{{wordpress-site-plan:asset:asset-0000000000000000}}';
$throws(static fn() => WordPressSitePlan::assertValid($undeclared), 'Validation rejects undeclared tokens.');
$traversal = $plan; $traversal['writes'][0]['target_path'] = '../escape.css';
$throws(static fn() => WordPressSitePlan::assertValid($traversal), 'Validation rejects traversal writes.');
$collision = $plan; $collision['writes'][1]['target_path'] = $collision['writes'][0]['target_path'];
$throws(static fn() => WordPressSitePlan::assertValid($collision), 'Validation rejects colliding writes.');
$caseCollision = $plan; $caseCollision['writes'][1]['target_path'] = 'STYLE.css';
$throws(static fn() => WordPressSitePlan::assertValid($caseCollision), 'Validation rejects case-folded collisions.');
$invalidScaffold = $plan; $invalidScaffold['writes'][0]['kind'] = 'theme_asset';
$throws(static fn() => WordPressSitePlan::assertValid($invalidScaffold), 'Validation rejects malformed scaffold writes.');
$unresolvedLocal = $plan; $unresolvedLocal['pages'][0]['canonical_block_markup'] .= '<img src="images/missing.svg">';
$throws(static fn() => WordPressSitePlan::assertValid($unresolvedLocal), 'Validation rejects unresolved local browser references.');
$invalidCompiledAsset = $first; $invalidCompiledAsset['source_reports']['compiled_site']['assets'][0]['target_path'] = 'C:\\theme\\site.css';
$throws(static fn() => (new WordPressSitePlan())->fromResult($invalidCompiledAsset), 'Projection rejects unsafe compiled asset targets.');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
rmdir($destination);
fwrite(STDOUT, "wordpress-site-plan contract passed\n");

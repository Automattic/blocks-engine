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
        'index.html' => '<main><img src="assets/logo.svg"><h1>Home</h1></main>',
        'about.html' => '<main><img src="assets/logo.svg"><h1>About</h1></main>',
        'parts/header.html' => '<header><img src="assets/logo.svg"><p>Header</p></header>',
        'assets/site.css' => '@font-face{font-family:test;src:url(assets/font.woff2)}main{background:url("assets/logo.svg")}',
        'assets/site.js' => 'window.siteAsset="assets/logo.svg";',
        'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        'assets/font.woff2' => 'font-data',
    ),
);
$first = (new ArtifactCompiler())->compile($artifact)->toArray();
$second = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $first['source_reports']['wordpress_site_plan'] ?? array();
$writes = $writeMap($plan['writes']);

$assert(WordPressSitePlan::SCHEMA === ($plan['schema'] ?? null), 'Compiler projects the v2 canonical WordPress site plan.');
$assert(isset($writes['style.css'], $writes['theme.json'], $writes['functions.php'], $writes['templates/index.html'], $writes['templates/page.html'], $writes['templates/front-page.html'], $writes['parts/header.html']), 'Plan declares the complete block-theme scaffold.');
$assert(str_contains((string) $writes['style.css']['payload']['data'], 'Theme Name:'), 'Theme stylesheet has a recognition header.');
$assert(str_contains((string) ($plan['pages'][1]['canonical_block_markup'] ?? ''), '{{wordpress-site-plan:asset:'), 'Canonical page markup uses declared destination-independent references.');
$assert(!isset($plan['pages'][1]['resolved_block_markup']), 'Canonical markup is explicitly distinct from resolved markup.');
$assert(count($plan['reference_tokens']) === count($plan['assets']), 'Every asset has one deterministic resolver token.');
$assert($plan === ($second['source_reports']['wordpress_site_plan'] ?? null), 'Canonical WordPress site plans are deterministic.');

$resolver = new WordPressSitePlanResolver();
$resolved = $resolver->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site'));
$resolvedAgain = $resolver->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site/'));
$assert($resolved === $resolvedAgain, 'Resolution is deterministic after theme URI normalization.');
$about = (string) $resolved['pages'][1]['resolved_block_markup'];
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
foreach (array('style.css', 'theme.json', 'functions.php', 'templates/index.html', 'templates/page.html', 'templates/front-page.html', 'parts/header.html', 'assets/assets/site.css', 'assets/assets/site.js', 'assets/assets/logo.svg', 'assets/assets/font.woff2') as $required) $assert(is_file($destination . '/' . $required), "Materialization writes {$required}.");
$assert(false === str_contains((string) file_get_contents($destination . '/assets/assets/site.css'), WordPressSitePlan::TOKEN_PREFIX), 'Materialized assets contain no unresolved resolver tokens.');

$throws(static fn() => $resolver->resolve($plan, array()), 'Resolution rejects missing destination context.');
$throws(static fn() => $resolver->resolve($plan, array('theme_uri' => '/themes/site')), 'Resolution rejects relative destination context.');
$undeclared = $plan; $undeclared['pages'][0]['canonical_block_markup'] .= '{{wordpress-site-plan:asset:asset-0000000000000000}}';
$throws(static fn() => WordPressSitePlan::assertValid($undeclared), 'Validation rejects undeclared tokens.');
$traversal = $plan; $traversal['writes'][0]['target_path'] = '../escape.css';
$throws(static fn() => WordPressSitePlan::assertValid($traversal), 'Validation rejects traversal writes.');
$collision = $plan; $collision['writes'][1]['target_path'] = $collision['writes'][0]['target_path'];
$throws(static fn() => WordPressSitePlan::assertValid($collision), 'Validation rejects colliding writes.');
$invalidCompiledAsset = $first; $invalidCompiledAsset['source_reports']['compiled_site']['assets'][0]['target_path'] = 'C:\\theme\\site.css';
$throws(static fn() => (new WordPressSitePlan())->fromResult($invalidCompiledAsset), 'Projection rejects unsafe compiled asset targets.');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
rmdir($destination);
fwrite(STDOUT, "wordpress-site-plan contract passed\n");

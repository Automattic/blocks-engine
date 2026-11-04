<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A breadcrumb trail's list rules address Core's rendered <ol>, not the
// block wrapper that carries the source classes.
foreach (array('index.html', 'wrapped.html') as $fixture) {
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about/index.html' => file_get_contents(dirname(__DIR__) . '/fixtures/breadcrumb-list-rules/' . $fixture),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$markup = '';
foreach ($plan['pages'] ?? array() as $page) {
    if ('about/index.html' === ($page['source_path'] ?? null)) $markup = (string) $page['canonical_block_markup'];
}
$assert(1 === substr_count($markup, '<!-- wp:breadcrumbs '), $fixture . ': The trail renders as one core/breadcrumbs block.');

$css = (string) json_encode($plan);
$assert(1 === preg_match('/\.wp-block-breadcrumbs ol[^{]*\{[^}]*margin-bottom:0/', stripcslashes($css)), $fixture . ': The list rule with margin-bottom:0 addresses Core\'s rendered <ol>.');
$assert(0 === preg_match('/(?<![\w-])\.breadcrumb(?:\:not\([^)]*\))?\{[^}]*margin-bottom:0/', stripcslashes($css)), $fixture . ': The list rule no longer lands on the wrapper that carries the source class.');
}

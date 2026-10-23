<?php
declare(strict_types=1);

// WordPress keeps underscores in a post slug, so a source URL segment written
// with `_` must survive into the plan row slug and the route: a dated
// directory route keeps its `post_name` spelling and its permalink answers
// without a 301 (#3331).

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n")); exit(1); } };
$post = '<html><head><title>mysqli_real_escape_string can be dangerous</title></head><body><main><article><h1>mysqli_real_escape_string can be dangerous</h1><p>Body of the post.</p></article></main></body></html>';
$about = '<html><head><title>About us</title></head><body><main><h1>About us</h1><p>About this site.</p></main></body></html>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1><a href="/2025/03/27/mysqli_real_escape_string-can-be-dangerous/">Post</a><a href="about_us.html">About</a></main>',
    '2025/03/27/mysqli_real_escape_string-can-be-dangerous/index.html' => $post,
    'about_us.html' => $about,
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($compiled);
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');

$dated = $pages['2025/03/27/mysqli_real_escape_string-can-be-dangerous/index.html'] ?? array();
$assert('mysqli_real_escape_string-can-be-dangerous' === ($dated['slug'] ?? null), 'the dated directory route keeps the underscore in its plan row slug', $dated['slug'] ?? null);
$assert('/2025/03/27/mysqli_real_escape_string-can-be-dangerous' === ($dated['route']['path'] ?? null) && 'mysqli_real_escape_string-can-be-dangerous' === ($dated['route']['slug'] ?? null), 'the dated route reproduces the source path segment', array($dated['route']['path'] ?? null, $dated['route']['slug'] ?? null));

$file = $pages['about_us.html'] ?? array();
$assert('about_us' === ($file['slug'] ?? null), 'the file route keeps the underscore in its plan row slug', $file['slug'] ?? null);
$assert('/about_us' === ($file['route']['path'] ?? null) && 'about_us' === ($file['route']['slug'] ?? null), 'the file route reproduces the source file name', array($file['route']['path'] ?? null, $file['route']['slug'] ?? null));

foreach ($plan['routes'] as $route) {
    if ('2025/03/27/mysqli_real_escape_string-can-be-dangerous/index.html' === ($route['source_path'] ?? '')) {
        $assert('mysqli_real_escape_string-can-be-dangerous' === ($route['target_slug'] ?? null), 'the route row targets the underscored post name', $route);
    }
    if ('about_us.html' === ($route['source_path'] ?? '')) {
        $assert('about_us' === ($route['target_slug'] ?? null) && '/about_us' === ($route['target_path'] ?? null), 'the route row targets the underscored file name', $route);
    }
}

fwrite(STDOUT, "document slug keeps underscores tests: passed\n");

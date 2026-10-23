<?php
declare(strict_types=1);

// A post can live under a numeric parent route: `/%year%/%postname%/`
// permalinks give `/2019/<slug>/`, and a captured month archive such as
// `/2020/01/` can be inferred as a post under `/2020/`. PHP stores the
// trimmed parent `2019` as an int array key, and the post-route rewrite
// bootstrap handed it to preg_quote(), which threw "preg_quote(): Argument #1
// ($str) must be of type string, int given" while composing the site plan.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$article = static fn (string $title, string $date): string => '<html><head><title>' . $title . '</title><meta property="og:type" content="article"><meta property="article:published_time" content="' . $date . '"></head><body><main><h1>' . $title . '</h1><p>' . str_repeat('Article body text. ', 40) . '</p></main></body></html>';
$files = array(
    'index.html' => '<main><h1>Home</h1><ul><li><a href="/2019/first/">First</a></li><li><a href="/news/second/">Second</a></li></ul></main>',
    '2019/first/index.html' => $article('First', '2019-05-01T00:00:00Z'),
    'news/second/index.html' => $article('Second', '2019-04-01T00:00:00Z'),
);

$plan = null;
try {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
} catch (Throwable $error) {
    $assert(false, 'site plan with a numeric post parent threw ' . get_class($error) . ': ' . $error->getMessage());
}

if (is_array($plan)) {
    $pages = array_column($plan['pages'], null, 'source_path');
    $assert('post' === ($pages['2019/first/index.html']['post_type'] ?? null) && 'post' === ($pages['news/second/index.html']['post_type'] ?? null), 'fixture articles are planned as posts');
    $bootstrap = '';
    foreach ($plan['writes'] as $write) {
        if ('functions.php' === ($write['target_path'] ?? '')) {
            $bootstrap = (string) ($write['payload']['data'] ?? '');
        }
    }
    $assert(str_contains($bootstrap, "add_rewrite_rule( '^(2019/(?:first))/?\$'"), 'numeric parent gets its post rewrite rule');
    $assert(str_contains($bootstrap, "add_rewrite_rule( '^(news/(?:second))/?\$'"), 'named parent keeps its post rewrite rule');
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "numeric-post-route-parent: passed\n");

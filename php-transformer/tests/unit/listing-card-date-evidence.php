<?php
declare(strict_types=1);

// Entries dated only on their listing cards are posts, dated from the card's
// visible text; a repeated datetime placeholder is ignored (#4167).

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n")); exit(1); } };
$root = dirname(__DIR__) . '/fixtures/listing-card-dates-site';
$load = static function (string $dir, string $prefix = '') use (&$load): array {
    $files = array();
    foreach (scandir($dir) as $name) {
        if ('.' === $name || '..' === $name) continue;
        is_dir($dir . '/' . $name) ? $files += $load($dir . '/' . $name, $prefix . $name . '/') : $files[$prefix . $name] = (string) file_get_contents($dir . '/' . $name);
    }
    return $files;
};
$files = $load($root);
$plan = static function (array $files): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    return array_column((new WordPressSitePlan())->fromCompilerResult($result)['pages'], null, 'source_path');
};

$listing = $files['news/index.html'];
$cases = array(
    'index.html and absolute hrefs' => array($listing, array('alpha' => '2026-08-18T00:00:00Z', 'beta' => '2019-07-01T00:00:00Z', 'gamma' => '2020-03-03T00:00:00Z')),
    'trailing-slash and relative hrefs' => array(
        str_replace(array('/news/alpha/index.html', 'beta/', 'https://example.test/news/gamma/'), array('/news/alpha/', 'beta/index.html', 'gamma/'), $listing),
        array('alpha' => '2026-08-18T00:00:00Z', 'beta' => '2019-07-01T00:00:00Z', 'gamma' => '2020-03-03T00:00:00Z'),
    ),
    'unparseable visible date keeps no timestamp' => array(
        str_replace('March 3, 2020', 'sometime soon', $listing),
        array('alpha' => '2026-08-18T00:00:00Z', 'beta' => '2019-07-01T00:00:00Z', 'gamma' => null),
    ),
);
foreach ($cases as $name => [$html, $expected]) {
    $pages = $plan(array_merge($files, array('news/index.html' => $html)));
    $assert('page' === $pages['news/index.html']['post_type'], "{$name}: the listing stays a page", $pages['news/index.html']['content_decision'] ?? null);
    foreach ($expected as $slug => $timestamp) {
        $page = $pages["news/{$slug}/index.html"];
        $assert('post' === $page['post_type'], "{$name}: {$slug} is a post", $page['content_decision'] ?? null);
        $assert(in_array('listing:card-date', array_column($page['content_decision']['evidence'], 'source'), true), "{$name}: {$slug} cites card evidence", $page['content_decision']);
        $assert($timestamp === ($page['publication_timestamp'] ?? null), "{$name}: {$slug} timestamp", $page['publication_timestamp'] ?? null);
    }
}

$bare = $files;
$bare['news/index.html'] = '<main><h1>News</h1><a href="/news/alpha/">Alpha</a><a href="/news/beta/">Beta</a></main>';
foreach ($plan($bare) as $path => $page) $assert('page' === $page['post_type'], "no card evidence: {$path} is unchanged", $page['content_decision'] ?? null);

fwrite(STDOUT, "listing card date evidence tests: passed\n");

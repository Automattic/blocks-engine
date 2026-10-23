<?php
declare(strict_types=1);

// Source offsets survive alongside, never in place of, the selected UTC instant.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n"));
        exit(1);
    }
};
$meta = static fn(string $date): string => '<meta property="article:published_time" content="' . $date . '">';
$time = static fn(string $date): string => '<time datetime="' . $date . '">Published</time>';
$build = static function (string $head, string $body): array {
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<main><h1>Home</h1><a href="/post/">Post</a></main>',
        'post/index.html' => '<html><head><title>Post</title>' . $head . '</head><body><main><h1>Post</h1>' . $body . '<p>Post body.</p></main></body></html>',
    )))->toArray();
    return (new WordPressSitePlan())->fromCompilerResult($compiled);
};
$cases = array(
    'adamadam' => array($meta('2025-04-22T22:04:36+00:00'), $time('2025-04-23T00:04:36+02:00'), '2025-04-22T22:04:36Z', '2025-04-23T00:04:36+02:00'),
    'negative offset' => array($meta('2025-04-23T02:04:36Z'), $time('2025-04-22T21:04:36-05:00'), '2025-04-23T02:04:36Z', '2025-04-22T21:04:36-05:00'),
    'different instant' => array($meta('2025-04-22T22:04:36Z'), $time('2025-04-24T00:04:36+02:00'), '2025-04-22T22:04:36Z', null),
    'UTC only' => array($meta('2025-04-22T22:04:36+00:00'), $time('2025-04-22T22:04:36Z'), '2025-04-22T22:04:36Z', null),
    'JSON-LD' => array('<script type="application/ld+json">{"@type":"BlogPosting","datePublished":"2025-04-23T00:04:36+02:00"}</script>', '', '2025-04-22T22:04:36Z', '2025-04-23T00:04:36+02:00'),
    'meta offset' => array($meta('2025-04-23T00:04:36.123+02:00'), '', '2025-04-22T22:04:36Z', '2025-04-23T00:04:36+02:00'),
    'microdata offset' => array('', '<time itemprop="datePublished" datetime="2025-04-23T00:04:36+02:00">Published</time>', '2025-04-22T22:04:36Z', '2025-04-23T00:04:36+02:00'),
    'zero before nonzero' => array($meta('2025-04-22T22:04:36Z'), $time('2025-04-22T22:04:36+00:00') . $time('2025-04-23T00:04:36+02:00'), '2025-04-22T22:04:36Z', '2025-04-23T00:04:36+02:00'),
    'unrelated before matching' => array($meta('2025-04-22T22:04:36Z'), $time('2025-04-24T00:04:36+02:00') . $time('2025-04-23T00:04:36+02:00'), '2025-04-22T22:04:36Z', '2025-04-23T00:04:36+02:00'),
    'bare time does not select instant' => array('', $time('2025-04-23T00:04:36+02:00'), null, null),
    'invalid date' => array($meta('2025-04-22T22:04:36Z'), $time('2025-02-30T00:04:36+02:00'), '2025-04-22T22:04:36Z', null),
);
foreach ($cases as $name => [$head, $body, $timestamp, $local]) {
    $plan = $build($head, $body);
    $page = array_column($plan['pages'], null, 'source_path')['post/index.html'];
    $assert($timestamp === ($page['publication_timestamp'] ?? null), "{$name}: UTC selection is unchanged", $page['content_decision']);
    $assert($local === ($page['publication_local_time'] ?? null), "{$name}: expected local representation", $page['publication_local_time'] ?? null);
    if (null === $local) $assert(!array_key_exists('publication_local_time', $page), "{$name}: local field is omitted");
    WordPressSitePlan::assertValid($plan);
    if (null !== $local) {
        $changed = $plan;
        foreach ($changed['pages'] as &$row) unset($row['publication_local_time']);
        unset($row);
        $assert(WordPressSitePlan::planIdentity($changed) !== WordPressSitePlan::planIdentity($plan), "{$name}: local time participates in plan identity");
    }
}
fwrite(STDOUT, "wordpress site plan publication local time tests: passed\n");

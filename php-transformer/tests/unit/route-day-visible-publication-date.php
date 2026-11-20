<?php
declare(strict_types=1);

// A static blog dates each post only twice: a day permalink
// (/YYYY/MM/DD/slug.html) and an unclassed date line in the post header.
// Neither alone is publication evidence, but a permalink day the page also
// shows is: the post takes that day as its date, the evidence names the
// agreement, and the header renders from post data in the single template.
// A visible date that disagrees with the permalink dates nothing.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$post = static fn(string $title, string $date, string $body): string => '<!DOCTYPE html><html lang="en"><head><title>' . $title . ' — example.test</title></head><body><article class="post"><header class="post-header"><h1 class="post-title">' . $title . '</h1><p class="post-meta">' . $date . '</p></header><p>' . $body . '</p></article></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!DOCTYPE html><html><head><title>example.test</title></head><body><main><h1>Notes</h1><nav><a href="/2024/11/30/first-note.html">First note</a><a href="/2023/02/08/second-note.html">Second note</a><a href="/2022/07/01/third-note.html">Third note</a></nav></main></body></html>',
    '2024/11/30/first-note.html' => $post('First note', 'Nov 30, 2024', 'The first observation about things.'),
    '2023/02/08/second-note.html' => $post('Second note', 'Feb 8, 2023', 'Another observation that differs.'),
    '2022/07/01/third-note.html' => $post('Third note', 'Jul 2, 2022', 'A date that disagrees with its path.'),
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');
foreach (array('2024/11/30/first-note.html' => '2024-11-30T00:00:00Z', '2023/02/08/second-note.html' => '2023-02-08T00:00:00Z') as $path => $expected) {
    $page = $pages[$path];
    $assert('post' === $page['post_type'] && $expected === ($page['publication_timestamp'] ?? null), "{$path}: the permalink day shown on the page is the publication date");
    $assert(in_array(array('source' => 'route:dated-day-visible', 'publication_timestamp' => $expected), $page['content_decision']['evidence'], true), "{$path}: the decision records the agreeing evidence");
    $assert(!str_contains($page['canonical_block_markup'], 'post-title') && !str_contains($page['canonical_block_markup'], 'post-meta'), "{$path}: the content no longer carries a static title and date");
    $assert('single' === ($page['route_template'] ?? 'single'), "{$path}: dated posts share the single template");
}
$third = $pages['2022/07/01/third-note.html'];
$assert('post' === $third['post_type'] && !isset($third['publication_timestamp']), 'A visible date that disagrees with the permalink day dates nothing');
$single = array_column($plan['templates'], null, 'slug')['single']['canonical_block_markup'] ?? '';
$assert(str_contains($single, '<!-- wp:post-title {"level":1,"className":"post-title"} /-->') && str_contains($single, '<!-- wp:post-date {"format":"M j, Y","className":"post-meta"} /-->'), 'The single template renders the header from post data');
echo "Route day visible publication date passed.\n";

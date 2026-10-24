<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$build = static fn(array $files): array => (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$article = '<html><head><meta property="og:type" content="article"><meta property="article:published_time" content="2024-03-02T10:30:00Z"></head><body><main><article><h1>Essay</h1><p>The original article.</p></article></main></body></html>';
$files = array(
    'index.html' => '<main><h1>Home</h1><p>Welcome.</p></main>',
    '2024/03/02/essay/index.html' => $article,
    '2024/03/03/another/index.html' => str_replace('Essay', 'Another', $article),
    'category/programming/index.html' => '<main><h1>Programming</h1><a href="/2024/03/02/essay/">Essay</a></main>',
    'tag/tools/index.html' => '<main><h1>Tools</h1><p>Archive.</p></main>',
);
$baseline = $build($files);
$views = array(
    '2024/03/02/essay/comment-page-1/index.html' => array('/2024/03/02/essay', 'comment_pagination'),
    '2024/03/03/another/comment-page-12/index.html' => array('/2024/03/03/another', 'comment_pagination'),
    'category/programming/page/2/index.html' => array('/category/programming', 'listing_pagination'),
    'tag/tools/page/3/index.html' => array('/tag/tools', 'listing_pagination'),
    'page/2/index.html' => array('/', 'listing_pagination'),
);
foreach ($views as $source => [$parent, $reason]) {
    $files[$source] = 'comment_pagination' === $reason ? $article : '<html><head><link rel="canonical" href="' . $parent . '/"><link rel="prev" href="' . $parent . '/"></head><body class="paged"><main><h1>More entries</h1><p>Listing continuation.</p></main></body></html>';
}
$plan = $build($files);
$pages = array_column($plan['pages'], null, 'source_path');
$assert(count($baseline['pages']) === count($pages), 'Pagination creates neither documents nor synthetic ancestors');
foreach ($baseline['pages'] as $parent) {
    $actual = $pages[$parent['source_path']] ?? array();
    foreach (array('post_type', 'content_decision', 'route', 'canonical_block_markup') as $field) {
        $assert(($parent[$field] ?? null) === ($actual[$field] ?? null), 'Parent ' . $field . ' is unchanged: ' . $parent['source_path']);
    }
}
$diagnostics = array_column(array_filter($plan['diagnostics'], static fn(array $row): bool => 'wordpress_site_plan_pagination_suppressed' === ($row['code'] ?? null)), null, 'source_path');
$catalog = array_column($plan['source']['source_documents'], null, 'source_path');
foreach ($views as $source => [$parent, $reason]) {
    $assert(!isset($pages[$source]), 'No pagination document: ' . $source);
    $assert(isset($catalog[$source]), 'Suppressed capture remains in the source catalog');
    $row = $diagnostics[$source] ?? array();
    $assert('/' . substr($source, 0, -strlen('/index.html')) === ($row['source_route'] ?? null) && $parent === ($row['parent_route'] ?? null) && $reason === ($row['reason_code'] ?? null), 'Suppression records source, parent and reason: ' . $source);
}
// Route structure alone suffices, even without canonical/prev or paged evidence.
$bare = $build(array('index.html' => '<main>Home</main>', 'category/plain/page/2/index.html' => '<main>More entries</main>', 'page/2/index.html' => '<main>More entries</main>'));
$assert(1 === count($bare['pages']), 'Bare pagination cannot manufacture a page ancestor');
// Positive page numbers only; similarly named content is not pagination.
$ordinary = $build(array('index.html' => '<main>Home</main>', 'comment-page-0/index.html' => '<main>Zero</main>', 'page-notes/index.html' => '<main>Notes</main>'));
$assert(3 === count($ordinary['pages']), 'Non-pagination content remains materialized');
fwrite(STDOUT, "pagination-routes-project-onto-parent: passed\n");

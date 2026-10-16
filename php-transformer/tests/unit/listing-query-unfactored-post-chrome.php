<?php
declare(strict_types=1);

// A site with two blogs whose posts carry different chrome (a "News" banner
// and sidebar, a "Journal" banner and sidebar) cannot share one single
// template, so article factoring keeps every post page-owned: its content is
// the whole post document. A news listing of full posts must not become a
// Query Loop whose cards render that post content, or every listing item
// repeats the header, banner, sidebar, comment form and footer.

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

$header = '<header class="site-header"><a href="/">Example Mission</a><nav><ul><li><a href="/">Home</a></li><li><a href="/news/">News</a></li><li><a href="/journal/">Journal</a></li></ul></nav></header>';
$footer = '<footer class="site-footer"><p>Example Mission, 1 Main Street</p></footer>';
$sidebar = static fn(string $section): string => '<aside class="sidebar"><h3>' . $section . ' archives</h3><p>' . $section . ' updates from the field.</p></aside>';
$document = static fn(string $title, string $section, string $main): string => '<html><head><title>' . $title . '</title></head><body>' . $header
    . '<div class="banner"><h1 class="banner-title">' . $section . '</h1></div><div class="layout"><main>' . $main . '</main>' . $sidebar($section) . '</div>' . $footer . '</body></html>';
$slug = static fn(string $title): string => strtolower(str_replace(' ', '-', $title));
$article = static fn(string $section, string $title, string $date): string => '<div class="post-item"><div class="post-header"><h2 class="post-title"><a class="post-title-link" href="/' . strtolower($section) . '/' . $slug($title) . '/">' . $title . '</a></h2>'
    . '<p class="post-date"><span class="date-text">' . $date . '</span></p></div>'
    . '<div class="post-content"><p>' . str_repeat($title . ' body text. ', 12) . '</p></div></div>';
$post = static fn(string $section, string $title, string $date): string => $document($title, $section, $article($section, $title, $date) . '<div class="comments"><h3>Leave a Reply</h3><form><textarea name="comment"></textarea><button type="submit">Post</button></form></div>');

$news = array(array('New Center Opens', '4/30/2024'), array('Students Visit', '3/15/2024'), array('Saturday Program', '2/1/2024'));
$journal = array(array('Morning Reflection', '4/20/2024'), array('Evening Prayer', '3/10/2024'));
$files = array(
    'index.html' => $document('Home', 'Home', '<p>Welcome to the example mission.</p>'),
    'news/index.html' => $document('News', 'News', implode('', array_map(static fn(array $row): string => $article('News', $row[0], $row[1]), $news))),
    'journal/index.html' => $document('Journal', 'Journal', implode('', array_map(static fn(array $row): string => $article('Journal', $row[0], $row[1]), $journal))),
);
foreach ($news as $row) $files['news/' . $slug($row[0]) . '/index.html'] = $post('News', $row[0], $row[1]);
foreach ($journal as $row) $files['journal/' . $slug($row[0]) . '/index.html'] = $post('Journal', $row[0], $row[1]);

$plan = null;
try {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
} catch (Throwable $error) {
    $assert(false, 'site plan threw ' . get_class($error) . ': ' . $error->getMessage());
}

if (is_array($plan)) {
    $pages = array_column($plan['pages'], null, 'source_path');
    $assert('post' === ($pages['news/new-center-opens/index.html']['post_type'] ?? null), 'news articles are planned as posts');
    $postsWithChrome = array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null) && str_contains((string) $page['canonical_block_markup'], 'archives</h3>'));
    foreach (array('news/index.html' => $news, 'journal/index.html' => $journal) as $path => $rows) {
        $markup = (string) ($pages[$path]['canonical_block_markup'] ?? '');
        $rendersPostContent = str_contains($markup, '<!-- wp:query') && str_contains($markup, '<!-- wp:post-content');
        $assert(!($rendersPostContent && array() !== $postsWithChrome), $path . ': listing cards do not render post content that still carries the page chrome');
        foreach ($rows as $row) {
            $assert($rendersPostContent || str_contains($markup, $row[0] . ' body text.'), $path . ': listing keeps the full text of ' . $row[0]);
        }
    }
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "listing-query-unfactored-post-chrome: passed\n");

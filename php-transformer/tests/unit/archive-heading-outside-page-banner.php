<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TaxonomyProjection;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
set_error_handler(static function (int $severity, string $message): bool {
    throw new ErrorException($message, 0, $severity);
});

$banner = '<header class="wp-block-template-part"><header class="wp-block-group"><h1 class="wp-block-site-title"><a href="/">Site Name</a></h1></header></header>';
$post = static fn(string $title): string => $banner . '<h1>' . $title . '</h1><a href="/category/programming/" rel="tag">Programming</a>';
$project = static function (string $archiveHtml) use ($post): array {
    $documents = array(
        array('source_path' => '2024/01/01/first/index.html', 'html' => $post('First'), 'metadata' => array('post_type' => 'post')),
        array('source_path' => 'category/programming/index.html', 'html' => $archiveHtml, 'metadata' => array('post_type' => 'page')),
    );
    $routes = array(
        array('source_path' => '2024/01/01/first/index.html', 'target_path' => '/2024/01/01/first'),
        array('source_path' => 'category/programming/index.html', 'target_path' => '/category/programming'),
    );
    return TaxonomyProjection::project($documents, $routes);
};
$listing = '<a href="/2024/01/01/first/">First</a>';

// A block theme's site title is an h1 inside the page banner, ahead of the archive heading.
$blockTheme = $project($banner . '<h1 class="wp-block-query-title">Category: <span>Programming</span></h1>' . $listing);
$assert(1 === count($blockTheme['entities']) && 'Programming' === $blockTheme['entities'][0]['name'], 'The archive is named by the first heading outside the page banner.');

// A classic theme's page header inside main content still names the archive.
$classic = $project($banner . '<main><header class="page-header"><h1 class="page-title">Category: <span>Programming</span></h1></header>' . $listing . '</main>');
$assert(1 === count($classic['entities']) && 'Programming' === $classic['entities'][0]['name'], 'A header inside main content is not the page banner.');

// An archive whose only h1 is the site title has no archive name.
$bannerOnly = $project($banner . '<h2>Programming</h2>' . $listing);
$assert(array() === $bannerOnly['entities'], 'The site title never names an archive.');

// Authors share the archive pipeline but have no taxonomy hierarchy.
$diagnostics = array();
$author = array('kind' => 'author', 'slug' => 'writer', 'name' => 'Writer', 'archive' => array('source_path' => 'author/writer/index.html', 'source_route' => '/author/writer'));
$assert(array($author) === TaxonomyProjection::retainProvenParents(array($author), $diagnostics) && array() === $diagnostics, 'Author entities pass hierarchy retention without a taxonomy key.');

echo "archive-heading-outside-page-banner: passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A category archive captured as desktop and mobile documents keeps both
// copies, so its template binds the shared inline footer once per copy.
$document = static function (string $bodyClass, string $main): string {
    $copy = static fn(string $device, string $nav): string => '<div class="data-blocks-engine-capture-' . $device . '-document ' . $bodyClass . '" data-blocks-engine-capture-document-scope=""><div class="wp-site-blocks"><header class="site-header"><a href="/">Benedetto</a><nav class="' . $nav . '"><a href="/category/blog/">Blog</a></nav></header><main class="wp-block-group">' . $main . '</main><!--#include virtual="/parts/footer.html" --></div></div>';
    return '<!doctype html><html><head><link rel="stylesheet" href="/site.css"></head><body class="' . $bodyClass . '">' . $copy('desktop', 'nav-d') . $copy('mobile', 'nav-m') . '</body></html>';
};
$categories = '<a href="/category/blog/" rel="category tag">Blog</a> <a href="/category/foto/" rel="category tag">Foto</a>';
$post = static fn(string $title, string $date): string => str_replace('<head>', '<head><meta property="og:type" content="article"><meta property="article:published_time" content="' . $date . '">', $document('single', '<article><h1>' . $title . '</h1><p>' . $title . ' body.</p>' . $categories . '</article>'));
$listing = '<ul class="wp-block-post-template"><li class="wp-block-post"><h2><a href="/amsterdam/">Amsterdam</a></h2>' . $categories . '</li><li class="wp-block-post"><h2><a href="/rotterdam/">Rotterdam</a></h2>' . $categories . '</li></ul>';
$files = array(
    'site.css' => '.data-blocks-engine-capture-mobile-document{display:none}@media (max-width:600px){.data-blocks-engine-capture-mobile-document{display:block}.data-blocks-engine-capture-desktop-document{display:none}}.nav-d{display:flex}.nav-m{display:block}',
    'parts/footer.html' => '<footer class="site-footer"><p>© Benedetto</p></footer>',
    'index.html' => $document('home', '<h1>Home</h1><a href="/amsterdam/">Amsterdam</a> <a href="/rotterdam/">Rotterdam</a>'),
    'amsterdam/index.html' => $post('Amsterdam', '2026-05-29T11:11:39+00:00'),
    'rotterdam/index.html' => $post('Rotterdam', '2026-04-16T03:38:34+00:00'),
    'category/blog/index.html' => $document('archive category', '<h1>Blog</h1>' . $listing),
    'category/foto/index.html' => $document('archive category', '<h1>Foto</h1>' . $listing),
);
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (2 !== substr_count($templates['category-blog'] ?? '', '"slug":"footer"')) throw new RuntimeException('Each captured archive document binds the shared footer at its own position.');

echo "taxonomy-archive-device-document-shell: passed\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$header = '<header id="SITE_HEADER" class="SITE_HEADER">'
    . '<h1><a href="/">Rachel Braun</a></h1>'
    . '<h4><a href="/">UX Designer</a></h4>'
    . '<div class="menu">'
    . '<div><h1><a href="/">Work</a></h1></div>'
    . '<div><h1><a href="/about">About</a></h1></div>'
    . '<div><h1><a href="/resume">Resume</a></h1></div>'
    . '<div><h1><a href="/contact">Contact</a></h1></div>'
    . '</div></header>';

$transformed = (new HtmlTransformer())->transform(
    '<!doctype html><html><body>' . $header . '<main><h2>Home</h2></main></body></html>',
    array('source' => 'index.html')
);
$markup = $transformed->serializedBlocks;
$assert(
    str_contains($markup, '<!-- wp:navigation')
        && str_contains($markup, '"label":"Work"')
        && str_contains($markup, '"label":"About"')
        && str_contains($markup, '"label":"Contact"'),
    'A header landmark whose items are heading links becomes core/navigation.'
);

$pages = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><title>Rachel Braun UX Design</title></head><body>' . $header . '<main><h2>Home</h2></main></body></html>',
        'bulletin-board/index.html' => '<!doctype html><html><head><title>Rachel Braun UX Design</title></head><body>' . $header . '<main><p>Case study</p></main></body></html>',
        'diabetes-capstone/index.html' => '<!doctype html><html><head><title>Site</title></head><body>' . $header . '<main><h1>Diabetes Capstone&nbsp;</h1></main></body></html>',
        'about.html' => '<!doctype html><html><head><title>About Northline</title></head><body>' . $header . '<main><p>About the studio.</p></main></body></html>',
    ),
))->toArray()['source_reports']['wordpress_site_plan']['pages'] ?? array();
$bySource = array();
foreach ($pages as $page) {
    $bySource[$page['source_path'] ?? ''] = $page['title'] ?? '';
}
$assert('Rachel Braun UX Design' === ($bySource['index.html'] ?? null), 'The entry page keeps its document title.');
$assert('Bulletin Board' === ($bySource['bulletin-board/index.html'] ?? null), 'A nested page whose title matches the site chrome title uses its path title.');
$assert('Diabetes Capstone' === ($bySource['diabetes-capstone/index.html'] ?? null), 'Content headings win and trailing nbsp is stripped.');
$assert('About Northline' === ($bySource['about.html'] ?? null), 'A nested page keeps a unique document title when it differs from the entry title.');

fwrite(STDOUT, "header-link-cluster-navigation contract passed\n");

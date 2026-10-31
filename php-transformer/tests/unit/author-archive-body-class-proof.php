<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array('archive author author-jane author-1' => true, 'archive author author-1' => false) as $classes => $expected) {
    $archive = '<!doctype html><html><body class="' . $classes . '"><main><h1>Author: Jane Doe</h1><article><h2><a href="/2025/01/01/first/">First story</a></h2><p>First summary.</p></article><article><h2><a href="/2025/01/02/second/">Second story</a></h2><p>Second summary.</p></article><a href="/about/">About</a></main></body></html>';
    $fixture = array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<main><h1>Home</h1></main>',
        array('path' => 'author/jane/index.html', 'content' => $archive),
        array('path' => '2025/01/01/first/index.html', 'content' => '<article><h1>First story</h1><time datetime="2025-01-01">January 1, 2025</time><p>Full first story without a byline.</p></article>'),
        array('path' => '2025/01/02/second/index.html', 'content' => '<article><h1>Second story</h1><time datetime="2025-01-02">January 2, 2025</time><p>Full second story without a byline.</p></article>'),
        array('path' => 'about/index.html', 'content' => '<main><h1>About</h1><p>An unrelated page.</p></main>'),
    ));
    $plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
    if (!$expected) {
        $assert(array() === $plan['author_entities'], 'Without the route-matching body class, byline-less posts cannot prove an author.');
        continue;
    }
    $assert(1 === count($plan['author_entities']) && 'jane' === $plan['author_entities'][0]['slug'], 'WordPress author body classes prove a byline-less author archive.');
    $assert(array('2025/01/01/first/index.html', '2025/01/02/second/index.html') === $plan['author_entities'][0]['membership_source_paths'], 'Both dated posts, but not the unrelated page, belong to Jane.');

    // Each pagination view must supply its own proof, not borrow the root's classes.
    $fixture['files'][0]['content'] = str_replace('<article><h2><a href="/2025/01/02/second/">Second story</a></h2><p>Second summary.</p></article>', '', $archive);
    foreach (array(true, false) as $provenPage) {
        $fixture['files'][4] = array('path' => 'author/jane/page/2/index.html', 'content' => $provenPage ? $archive : str_replace('author-jane', 'author-other', $archive));
        $paginated = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
        $members = $paginated['author_entities'][0]['membership_source_paths'];
        $assert(($provenPage ? array('2025/01/01/first/index.html', '2025/01/02/second/index.html') : array('2025/01/01/first/index.html')) === $members, 'Pagination contributes byline-less posts only with route-matching author body classes.');
    }
}
echo "author-archive-body-class-proof: passed\n";

<?php
declare(strict_types=1);

/**
 * Pages are named the way the site names them. When most document titles
 * repeat one site-name segment ("About | Example"), the remaining segment is
 * the page's own name; the first content heading is only a fallback. Joined
 * block spans in a hero heading otherwise produce names like "TOMMERRITT".
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};
$titles = static function (array $files): array {
    $result = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'index.html', 'files' => $files ))->toArray();
    return array_column($result['source_reports']['wordpress_site_plan']['pages'] ?? array(), 'title', 'source_path');
};
$page = static fn (string $title, string $h1): string => '<!doctype html><html><head><title>' . $title . '</title></head><body><main><h1>' . $h1 . '</h1><p>Body</p></main></body></html>';

$named = $titles(array(
    'index.html' => $page('Home | Example Studio', '<span class="block">EXAMPLE</span><span class="block">STUDIO</span>'),
    'about.html' => $page('About | Example Studio', 'Meet the team'),
    'contact.html' => $page('Contact — Example Studio', 'Get in touch'),
));
$assert('Home' === ($named['index.html'] ?? null), 'the entry page takes its own title segment', json_encode($named));
$assert('About' === ($named['about.html'] ?? null), 'a page takes its own title segment, not its heading', json_encode($named));
$assert('Contact' === ($named['contact.html'] ?? null), 'any common separator splits the site name', json_encode($named));

$branded = $titles(array(
    'index.html' => $page('A | Site | Tagline', 'Welcome'),
    'about.html' => $page('B | Site | Tagline', 'Meet the team'),
    'contact.html' => $page('C | Site | Tagline', 'Get in touch'),
));
$assert('A' === ($branded['index.html'] ?? null), 'a multi-segment shared site-name run is stripped whole on the entry page', json_encode($branded));
$assert('B' === ($branded['about.html'] ?? null), 'a multi-segment shared site-name run is stripped whole', json_encode($branded));
$assert('C' === ($branded['contact.html'] ?? null), 'each page keeps only its own segment', json_encode($branded));

$spa = $titles(array(
    'index.html' => $page('Example Studio', 'Welcome'),
    'about.html' => $page('Example Studio', 'Meet the team'),
));
$assert('Meet the team' === ($spa['about.html'] ?? null), 'identical titles (an app that never updates its title) keep heading names', json_encode($spa));

$plain = $titles(array(
    'index.html' => $page('Welcome home', 'Welcome'),
    'about.html' => $page('Our story', 'Meet the team'),
));
$assert('Meet the team' === ($plain['about.html'] ?? null), 'titles without a shared site-name segment keep heading names', json_encode($plain));

$sharedHero = $titles(array(
    'index.html' => $page('Creative Studio', 'Artistry Unleashed'),
    'f/finding-your-voice/index.html' => $page('Finding Your Voice at Creative Studio', 'Artistry Unleashed'),
));
$assert('Finding Your Voice at Creative Studio' === ($sharedHero['f/finding-your-voice/index.html'] ?? null), 'a repeated homepage hero must not replace a distinct article document title', json_encode($sharedHero));

$unchangedTitle = $titles(array(
    'index.html' => $page('Creative Studio', 'Artistry Unleashed'),
    'about.html' => $page('Creative Studio', 'Artistry Unleashed'),
));
$assert('Artistry Unleashed' === ($unchangedTitle['about.html'] ?? null), 'a generic shared document title does not replace the author heading', json_encode($unchangedTitle));

// The reduced site from #3424, including both site-name edges and every
// supported separator. Repeated home titles must not outvote the site name.
foreach ( array('|', '-', '–', '—', '·') as $separator ) {
    foreach ( array('leading', 'trailing') as $edge ) {
        $documentTitle = static fn (string $name): string => 'leading' === $edge
            ? 'Jane Doe ' . $separator . ' ' . $name
            : $name . ' ' . $separator . ' Jane Doe';
        $homeTitle = $documentTitle('Home ' . $separator . ' Welcome');
        $files = array(
            'index.html' => $page($homeTitle, 'Welcome'),
            'about/index.html' => $page($documentTitle('About ' . $separator . ' My story'), 'About'),
            'blog/index.html' => '<title>' . $documentTitle('Blog ' . $separator . ' News') . '</title><main><h2>Blog</h2><h3><a href="../index.php/index-query-1.html">Opening night</a></h3><h3><a href="../index.php/index-query-2.html">Bronze casting</a></h3></main>',
            'index.php/index-query-1.html' => '<title>' . $homeTitle . '</title><header><h1>Jane Doe</h1></header><main><h3>Opening night</h3><h1>Later heading</h1></main>',
            'index.php/index-query-2.html' => '<title>' . $homeTitle . '</title><main><h3>Bronze casting</h3></main>',
        );
        $expected = array(
            'index.html' => 'Home Welcome', // The entry owns its title; copies do not.
            'about/index.html' => 'About My story',
            'blog/index.html' => 'Blog News',
            'index.php/index-query-1.html' => 'Opening night',
            'index.php/index-query-2.html' => 'Bronze casting',
        );
        foreach ( array(false, true) as $withoutHeading ) {
            if ( $withoutHeading ) {
                $files['index.php/index-query-2.html'] = '<title>' . $homeTitle . '</title><header><h1>Jane Doe</h1></header><main><p>Body</p></main>';
            }
            $named = $titles($files);
            foreach ( $expected as $path => $expectedTitle ) {
                $assert($expectedTitle === ($named[$path] ?? null), "$edge $separator $path (heading absent: " . (int) $withoutHeading . ')', json_encode($named));
            }
        }
    }
}

foreach ( range(1, 6) as $level ) {
    $named = $titles(array(
        'index.html' => $page('Home', 'Welcome'),
        'first.html' => '<title>Shared article title</title><main><h' . $level . '>First article</h' . $level . '><h1>Later heading</h1></main>',
        'second.html' => '<title>Shared article title</title><main><h' . $level . '>Second article</h' . $level . '></main>',
    ));
    $assert('First article' === ($named['first.html'] ?? null), "a non-home shared title uses the first main heading at level $level", json_encode($named));
    $assert('Second article' === ($named['second.html'] ?? null), "each route owns its level $level heading", json_encode($named));
}

// Without a <main> landmark, the route's heading still names it ahead of
// generic inbound link text such as "Read more".
$named = $titles(array(
    'index.html' => '<title>Jane Doe - Home</title><body><header><h1>Jane Doe</h1></header><a href="post-1.html">Read more</a><a href="post-2.html">Read more</a></body>',
    'post-1.html' => '<title>Jane Doe - Home</title><body><header><h1>Jane Doe</h1><nav><h2>Menu</h2></nav></header><div class="content"><h3>Opening night</h3><p>Body</p></div><footer><h4>Contact</h4></footer></body>',
    'post-2.html' => '<title>Jane Doe - Home</title><body><header><h1>Jane Doe</h1></header><div><h3>Bronze casting</h3></div></body>',
));
$assert('Opening night' === ($named['post-1.html'] ?? null), 'a landmark-less route is named by its own heading, not chrome or inbound text', json_encode($named));
$assert('Bronze casting' === ($named['post-2.html'] ?? null), 'each landmark-less route owns its heading', json_encode($named));

if ( $failures > 0 ) {
    fwrite(STDERR, "document title page names: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "document title page names: {$passes} passed\n";

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

if ( $failures > 0 ) {
    fwrite(STDERR, "document title page names: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "document title page names: {$passes} passed\n";

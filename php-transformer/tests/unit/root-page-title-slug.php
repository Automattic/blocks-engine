<?php
declare(strict_types=1);

/**
 * The front page is not named from its filename or from an SEO keyword
 * fragment. Other pages are "Page | Example Site", so the shared run is the
 * site name; the root document is "Example Site | keyword phrase". A navigation
 * label that targets the front page is the page name. The slug is home, not
 * the filename index. No tagline is derived.
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
$pages = static function (array $files, string $entrypoint = 'index.html'): array {
    $result = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => $entrypoint, 'files' => $files ))->toArray();
    $bySource = array();
    foreach ( $result['source_reports']['wordpress_site_plan']['pages'] ?? array() as $page ) {
        $bySource[(string) ($page['source_path'] ?? '')] = $page;
    }

    return $bySource;
};
$document = static fn (string $title, string $body): string => '<!doctype html><html><head><title>' . $title . '</title></head><body>' . $body . '</body></html>';
$nav = '<nav><a href="/">Home</a><a href="/about/">About</a></nav>';

$named = $pages(array(
    'index.html' => $document('Example Site | keyword phrase', $nav . '<main><p>Welcome</p></main>'),
    'about/index.html' => $document('About | Example Site', $nav . '<main><p>Studio</p></main>'),
    'contact/index.html' => $document('Contact | Example Site', $nav . '<main><p>Reach us</p></main>'),
));
$assert('Home' === ($named['index.html']['title'] ?? null), 'the front page takes its navigation label, not the SEO fragment', json_encode(array_column($named, 'title', 'source_path')));
$assert('home' === ($named['index.html']['slug'] ?? null), 'the front page slug is home, not the filename index', (string) ($named['index.html']['slug'] ?? ''));
$assert('index' !== ($named['index.html']['slug'] ?? null), 'the front page slug is not index');
$assert('/' === ($named['index.html']['route']['path'] ?? null) && '' === ($named['index.html']['route']['slug'] ?? null), 'the front page route stays / and is not given an index segment', json_encode($named['index.html']['route'] ?? null));
$assert('About' === ($named['about/index.html']['title'] ?? null) && 'about' === ($named['about/index.html']['slug'] ?? null), 'a nested index page keeps its own title and route slug', json_encode($named['about/index.html'] ?? null));
$assert('Contact' === ($named['contact/index.html']['title'] ?? null) && 'contact' === ($named['contact/index.html']['slug'] ?? null), 'a page without a navigation label keeps its title segment', json_encode($named['contact/index.html'] ?? null));

$untitled = $pages(array(
    'index.html' => $document('Example Site | keyword phrase', '<main><p>Welcome</p></main>'),
    'about/index.html' => $document('About | Example Site', '<main><p>Studio</p></main>'),
    'contact/index.html' => $document('Contact | Example Site', '<main><p>Reach us</p></main>'),
));
$assert('keyword phrase' !== ($untitled['index.html']['title'] ?? null), 'without a navigation label the SEO remainder is not the front page title', (string) ($untitled['index.html']['title'] ?? ''));
$assert('Example Site | keyword phrase' === ($untitled['index.html']['title'] ?? null), 'without a navigation label the front page keeps its document title', (string) ($untitled['index.html']['title'] ?? ''));
$assert('home' === ($untitled['index.html']['slug'] ?? null), 'the front page slug is home even when no navigation label names it', (string) ($untitled['index.html']['slug'] ?? ''));
$assert('About' === ($untitled['about/index.html']['title'] ?? null), 'other pages still drop the shared trailing site name', (string) ($untitled['about/index.html']['title'] ?? ''));

foreach (array('', 'website/', 'site/nested/') as $root) {
    $entry = $root . 'index.html';
    $rootDocument = $document('Root document', '<main><p>Distinct root content</p></main>');
    $baseline = $pages(array($entry => $rootDocument), $entry)[$entry];
    $collision = $pages(array(
        $entry => $rootDocument,
        $root . 'home/index.html' => $document('Captured home document', '<main><p>Distinct home content</p></main>'),
        $root . 'home-2/index.html' => $document('Captured fallback document', '<main><p>Reserved fallback</p></main>'),
        $root . 'home-3/item/index.html' => $document('Nested document', '<main><p>Editable descendant</p></main>'),
    ), $entry);
    $assert('home-4' === $collision[$entry]['slug'], 'homepage fallback reserves captured siblings and generated ancestor slugs: ' . $root);
    $assert('/' === $collision[$entry]['route']['path'] && 'home' === $collision[$root . 'home/index.html']['slug'] && '/home' === $collision[$root . 'home/index.html']['route']['path'], 'root and captured home retain distinct canonical routes: ' . $root);
    foreach (array('title', 'content_hash', 'reconciliation_identity', 'canonical_block_markup') as $field) {
        $assert($baseline[$field] === $collision[$entry][$field], 'fallback allocation preserves root ' . $field . ': ' . $root);
    }
    $assert('Captured home document' === $collision[$root . 'home/index.html']['title'], 'fallback allocation preserves the captured sibling title: ' . $root);
    $assert($collision[$entry]['reconciliation_identity'] !== $collision[$root . 'home/index.html']['reconciliation_identity'], 'captured documents retain separate identities: ' . $root);
}

if ( $failures > 0 ) {
    fwrite(STDERR, "root page title slug: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "root page title slug: {$passes} passed\n";

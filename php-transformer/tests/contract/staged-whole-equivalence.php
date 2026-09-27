<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$passes = 0;
$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$passes, &$failures): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}" . ('' !== $detail ? " - {$detail}" : '') . "\n");
};

// Whole compilation and staged compilation are two drivers of one conversion.
// A page's conversion must depend only on that page and the artifact, never on
// which driver ran it. The inner page links a stylesheet the entry page does
// not; the stylesheet decides whether the menu is a navigation at all.
$menu = '<header class="site-header"><nav class="menu"><a href="/index.html">Home</a><a href="/about.html">About</a><a href="/contact.html">Contact</a></nav></header>';
$page = static fn (string $title, string $links): string => '<!doctype html><html><head><title>' . $title . '</title>' . $links . '</head><body>' . $menu . '<main><h1>' . $title . '</h1><p>' . str_repeat($title . ' body. ', 6) . '</p></main></body></html>';
$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'base.css', 'content' => 'body{margin:0}h1{font-size:2rem}'),
        array('path' => 'inner.css', 'content' => '.menu{display:flex;gap:2rem}.menu a{color:#123456;text-decoration:none;font-weight:700}'),
        array('path' => 'index.html', 'content' => $page('Home', '<link rel="stylesheet" href="base.css">')),
        array('path' => 'about.html', 'content' => $page('About', '<link rel="stylesheet" href="base.css"><link rel="stylesheet" href="inner.css">')),
        array('path' => 'contact.html', 'content' => $page('Contact', '<link rel="stylesheet" href="base.css"><link rel="stylesheet" href="inner.css">')),
    ),
);

$compiler = new ArtifactCompiler();
$whole = $compiler->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'] ?? array();
$shared = $compiler->prepareShared($artifact);
$staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toWordPressSitePlanView()['wordpress_site_plan'] ?? array();

$byPath = static function (array $plan): array {
    $pages = array();
    foreach ( $plan['pages'] ?? array() as $page ) $pages[$page['source_path']] = $page['canonical_block_markup'];
    ksort($pages);
    return $pages;
};
$normalize = static fn (string $markup): string => preg_replace('/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/', 'blocks-engine-marker', $markup) ?? $markup;
$wholePages = array_map($normalize, $byPath($whole));
$stagedPages = array_map($normalize, $byPath($staged));

$assert(array_keys($wholePages) === array_keys($stagedPages), 'both drivers plan the same pages', json_encode(array(array_keys($wholePages), array_keys($stagedPages))));
foreach ( $wholePages as $path => $markup ) {
    $assert(
        substr_count($markup, '<!-- wp:navigation ') === substr_count($stagedPages[$path] ?? '', '<!-- wp:navigation '),
        "{$path}: both drivers convert the menu the same way",
        substr_count($markup, '<!-- wp:navigation ') . ' vs ' . substr_count($stagedPages[$path] ?? '', '<!-- wp:navigation ')
    );
}
$slugs = static fn (array $plan): array => array_values(array_map(static fn (array $part): string => (string) $part['slug'], $plan['template_parts'] ?? array()));
$assert($slugs($whole) === $slugs($staged), 'both drivers extract the same template parts', json_encode(array($slugs($whole), $slugs($staged))));
$assert(str_contains($wholePages['about.html'] ?? '', 'color:#123456') || str_contains(implode('', array_column($whole['assets'] ?? array(), 'content')), '#123456'), 'the whole driver applies the stylesheet only the inner pages link');

if ( 0 < $failures ) {
    fwrite(STDERR, "staged/whole equivalence FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "staged/whole equivalence passed: {$passes} assertions\n";

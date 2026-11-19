<?php
declare(strict_types=1);

// Pages repeating a footer compile the same whether each converts it or
// replays the journal another page's worker recorded. Each page's header
// differs, and its logo link and the footer's both carry semantic markers,
// so the footer's recorded state sits beside entries the header wrote.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\DirectoryChromeJournalStore;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\MemoryChromeJournalStore;

$pages = array('index', 'about', 'work', 'contact');
$links = implode('', array_map(static fn (string $page): string => '<li class="menu-item"><a class="menu-link" href="' . $page . '.html">' . ucfirst($page) . ' and more about it</a></li>', $pages));
$columns = '';
foreach (range(1, 4) as $column) {
    $columns .= '<div class="footer-column"><h3 class="footer-title">Column ' . $column . '</h3><ul class="footer-links">';
    foreach (range(1, 10) as $item) $columns .= '<li><a href="https://example.com/' . $column . '/' . $item . '">Footer link ' . $column . '.' . $item . '</a></li>';
    $columns .= '</ul></div>';
}
$footer = '<footer class="site-footer"><div class="footer-grid">' . $columns . '</div><div class="brand"><a class="footer-home" href="https://example.com/"><img src="logo.png" alt="Studio"></a></div><div class="social"><a class="social-link" href="https://example.com/social" aria-label="Social"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M2 2h20v20H2z" fill="currentColor"/></svg></a></div><div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link signup" href="https://example.com/signup">Sign up</a></div></div><p class="legal">Copyright Studio. All rights reserved.</p></footer>';
$css = '.site-header{display:flex;justify-content:space-between;padding:24px;background:#111}.site-nav .menu{display:flex;gap:16px;list-style:none}'
    . '.menu-link{color:#fff;text-decoration:none}.site-footer{background:#222;color:#ddd;padding:48px}.footer-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:24px}'
    . '.footer-title{font-size:18px}.footer-links a{color:#bbb}.legal{margin-top:32px;font-size:12px}'
    // Selectors no block attribute expresses project through generated markers.
    . '.footer-links li:hover > a{color:#fff}.footer-column:nth-child(2) .footer-title::after{content:"*"}.site-footer > .footer-grid h3 + ul a{letter-spacing:1px}'
    . '*,*::before,*::after{box-sizing:border-box}'
    . '.signup{display:inline-block;background:#e33;color:#fff;padding:12px 24px;border-radius:4px}'
    . '.social-link svg{width:24px;height:24px}.social-link:focus-visible{outline:2px solid #fff}';
$files = array(array('path' => 'style.css', 'content' => $css), array('path' => 'logo.png', 'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
foreach ($pages as $page) {
    $files[] = array('path' => $page . '.html', 'content' => '<!doctype html><html><head><title>' . $page . '</title><link rel="stylesheet" href="style.css"></head><body>'
        . '<header class="site-header"><div class="brand"><a class="home-' . $page . '" href="index.html"><img src="logo.png" alt="Studio"></a></div><nav class="site-nav"><ul class="menu">' . $links . '</ul></nav></header>' . $footer . '<main><h3 class="footer-title">The ' . $page . ' page</h3><ul class="footer-links"><li><a class="social-link" href="https://example.com/' . $page . '">More</a></li></ul><div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link signup" href="https://example.com/' . $page . '/signup">Join</a></div></div></main></body></html>');
}
$artifact = array('entrypoint' => 'index.html', 'files' => $files);

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
if (array() === ($shared['analysis']['repeated_subtrees'] ?? array())) throw new RuntimeException('The shared plan names no repeated subtrees.');
$plans = $compiler->preparePages($artifact, $shared);
$directory = sys_get_temp_dir() . '/chrome-journal-' . bin2hex(random_bytes(4));
$compile = static function (callable $store) use ($compiler, $shared, $plans): string {
    $receipts = array();
    // One worker per page, as staged imports run them.
    foreach ($plans as $plan) $receipts[$plan['page_id']] = $compiler->compilePreparedPage($shared, $plan, null, $store());
    // Compare receipts: composing would union each page's stylesheet output,
    // so pages that converted their chrome would mask one that replayed it.
    $untimed = static function (mixed $value) use (&$untimed): mixed {
        if (!is_array($value)) return $value;
        foreach (array('metrics', 'step_timings', 'timings', 'work') as $key) unset($value[$key]);
        return array_map($untimed, $value);
    };
    return json_encode($untimed($receipts), JSON_THROW_ON_ERROR);
};
$separate = $compile(static fn (): MemoryChromeJournalStore => new MemoryChromeJournalStore());
$sharedStore = $compile(static fn (): DirectoryChromeJournalStore => new DirectoryChromeJournalStore($directory));
$journals = glob($directory . '/*.journal') ?: array();
array_map('unlink', $journals);
@rmdir($directory);

if ($separate !== $sharedStore) throw new RuntimeException('Replayed chrome compiled differently from converted chrome.');
// The entry page compiles under its own scope, so it records the footer and
// so does the first other page; the remaining two replay it.
if (2 !== count($journals)) throw new RuntimeException('Expected two footer journals, got ' . count($journals) . '.');
echo "ok\n";

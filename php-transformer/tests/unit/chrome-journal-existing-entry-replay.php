<?php
declare(strict_types=1);

// A repeated section holds an inline SVG icon. The page that records the
// section's journal already materialized the same icon earlier, so the
// recording only adds an occurrence to that asset. A page without the earlier
// icon must still compile the asset whole: replaying the occurrence alone
// left a row with no path, and the site plan rejected it.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\MemoryChromeJournalStore;

$icon = '<svg viewBox="0 0 24 24" width="24" height="24"><path d="M2 2h20v20H2z" fill="currentColor"/></svg>';
$links = '';
foreach (range(1, 30) as $item) $links .= '<li><a href="https://example.com/' . $item . '">Section link number ' . $item . '</a></li>';
$section = '<section class="promo"><ul class="links">' . $links . '</ul><a class="social" href="https://example.com/social" aria-label="Social">' . $icon . '</a></section>';
$files = array(array('path' => 'style.css', 'content' => '.promo{padding:24px}.social svg{width:24px;height:24px}'));
// Pages compile in path order: the entry under its own scope, then "about"
// sights the section, "contact" records it and "work" replays it.
foreach (array('index', 'about', 'contact', 'work') as $page) {
    $lead = 'contact' === $page ? '<a class="social" href="https://example.com/' . $page . '">' . $icon . '</a>' : '';
    $files[] = array('path' => $page . '.html', 'content' => '<!doctype html><html><head><title>' . $page . '</title><link rel="stylesheet" href="style.css"></head><body><div class="lead">' . $lead . '</div><main><p>Body of ' . $page . '</p></main>' . $section . '</body></html>');
}
$artifact = array('entrypoint' => 'index.html', 'files' => $files);

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
if (array() === ($shared['analysis']['repeated_subtrees'] ?? array())) throw new RuntimeException('The shared plan names no repeated subtrees.');
$plans = $compiler->preparePages($artifact, $shared);
$compile = static function (callable $store) use ($compiler, $shared, $plans): array {
    $receipts = array();
    foreach ($plans as $plan) $receipts[$plan['page_id']] = $compiler->compilePreparedPage($shared, $plan, null, $store());
    return $receipts;
};
$untimed = static function (mixed $value) use (&$untimed): mixed {
    if (!is_array($value)) return $value;
    foreach (array('metrics', 'step_timings', 'timings', 'work') as $key) unset($value[$key]);
    return array_map($untimed, $value);
};
$separate = $compile(static fn (): MemoryChromeJournalStore => new MemoryChromeJournalStore());
$sharedStore = new MemoryChromeJournalStore();
$receipts = $compile(static fn (): MemoryChromeJournalStore => $sharedStore);

foreach ($receipts as $receipt) {
    foreach ($receipt['compiled_documents'] as $path => $document) {
        foreach ($document['assets'] ?? array() as $asset) {
            if (!is_string($asset['path'] ?? null)) throw new RuntimeException($path . ' compiled an asset row without a path.');
        }
    }
}
if (json_encode($untimed($separate), JSON_THROW_ON_ERROR) !== json_encode($untimed($receipts), JSON_THROW_ON_ERROR)) throw new RuntimeException('Pages sharing a journal compiled differently from pages converting alone.');
$diagnostics = $compiler->compose($shared, array_values($receipts))->toArray()['source_reports']['wordpress_site_plan_diagnostics'] ?? array();
if (array() !== $diagnostics) throw new RuntimeException('The site plan did not compose: ' . json_encode($diagnostics));
echo "ok\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\ChromeJournalStore;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\MemoryChromeJournalStore;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$footer = '<footer class="site-footer"><h2>Contact our studio</h2>';
foreach (range(1, 40) as $item) $footer .= '<p><a href="https://example.com/contact/' . $item . '">Contact our studio about project number ' . $item . '</a></p>';
$footer .= '<canvas width="20" height="20"></canvas></footer>';
$files = array();
foreach (array('one', 'two', 'three') as $index => $page) {
    // Different content at every ancestor prevents an outer wrapper winning.
    $html = '';
    foreach (range(1, 7) as $depth) $html .= '<div><p>' . $page . ' level ' . $depth . '</p>';
    $html .= $footer . str_repeat('</div>', 7);
    $id = 1000 * ($index + 1);
    $html = preg_replace_callback('/<([a-z][a-z0-9]*)(?=[\s>])/', static function (array $match) use (&$id): string {
        return $match[0] . ' data-blocks-engine-node-id="n' . $id++ . '"';
    }, $html);
    $css = ':root { --footer-color: #333; } .site-footer { color: var(--footer-color); }'
        . ' .' . $page . '-only { color: red; }';
    if ('three' === $page) $css .= ' .site-footer { color: blue; }';
    $html = '<!doctype html><html><head><title>' . $page . '</title><style>' . $css . '</style></head><body>' . $html . '</body></html>';
    $files[] = array('path' => $page . '.html', 'content' => $html);
}
// Keep both tested pages in the same non-entry scope.
$files[] = array('path' => 'index.html', 'content' => '<html><body><h1>Home</h1></body></html>');
$artifact = array('entrypoint' => 'index.html', 'files' => $files);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$assert(array() !== ($shared['analysis']['repeated_subtrees'] ?? array()), 'Deep captured footer must be a repeated subtree.');
$store = new class implements ChromeJournalStore {
    public MemoryChromeJournalStore $inner;
    public int $hits = 0;
    public function __construct() { $this->inner = new MemoryChromeJournalStore(); }
    public function journal(string $key): ?array
    {
        $journal = $this->inner->journal($key);
        if (null !== $journal) ++$this->hits;
        return $journal;
    }
    public function record(string $key, array $journal): void { $this->inner->record($key, $journal); }
    public function sighted(string $key): bool { return $this->inner->sighted($key); }
};
$untimed = static function (mixed $value) use (&$untimed): mixed {
    if (!is_array($value)) return $value;
    foreach (array('metrics', 'step_timings', 'timings', 'work') as $key) unset($value[$key]);
    return array_map($untimed, $value);
};
$compile = static function (array $plan, ?ChromeJournalStore $store = null) use ($compiler, $shared, $untimed): string {
    return json_encode($untimed($compiler->compilePreparedPage($shared, $plan, null, $store)), JSON_THROW_ON_ERROR);
};
$property = new ReflectionProperty(MemoryChromeJournalStore::class, 'journals');
foreach (array('one', 'two') as $page) {
    $plan = $compiler->preparePage($artifact, $shared, $page . '.html');
    $expected = $compile($plan);
    $actual = $compile($plan, $store);
    $assert($expected === $actual, $page . ': shared and unshared receipts must be byte-identical.');
}
// The first page sights the footer's key and the second, whose unrelated CSS
// differs, reaches the same key and records it.
$journals = $property->getValue($store->inner);
$assert(1 === count($journals), 'Pages differing only in unrelated CSS must share one footer key.');
$third = $compiler->preparePage($artifact, $shared, 'three.html');
foreach (array(1, 2) as $_) $assert($compile($third) === $compile($third, $store), 'Relevant footer CSS must preserve the unshared receipt.');
$assert(0 === $store->hits, 'Matching footer CSS must not hit the journal.');
$assert(2 === count($property->getValue($store->inner)), 'Matching footer CSS must reach and record a different key.');
// A hit alone does not prove replay succeeded: make the recorded block visibly
// different and compile page two again. Only a replay can emit this text.
$key = array_key_first($journals);
$journals[$key]['block']['attrs']['className'] = 'journal-replay-proof';
$property->setValue($store->inner, $journals);
$proof = $compile($plan, $store);
$assert(str_contains($proof, 'journal-replay-proof'), 'The second page must be served by the recorded journal, not converted after a failed replay.');
echo "ok\n";

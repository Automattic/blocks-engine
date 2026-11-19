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
// Match the dependency surface of captured Wix posts, not a style-only toy.
$wixCss = '@charset "UTF-8"; @import url("https://static.example.com/site.css");';
foreach (range(1, 107) as $item) $wixCss .= '@font-face { font-family: wix' . $item . '; src: url("https://static.example.com/font' . $item . '.woff2") format("woff2"); }';
foreach (range(1, 51) as $item) $wixCss .= '@keyframes wix' . $item . ' { from { opacity: 0; } to { opacity: 1; } }';
foreach (range(1, 38) as $item) $wixCss .= '@container footer (min-width: ' . $item . 'px) { .site-footer { padding-left: ' . $item . 'px; } }';
foreach (range(1, 12) as $item) {
    $wixCss .= '@property --wix' . $item . ' { syntax: "<color>"; inherits: true; initial-value: teal; }';
    $wixCss .= '@layer wix' . $item . ' { .site-footer { border-color: teal; } }';
}
$wixCss .= '.site-footer { background-image: url("https://static.example.com/footer.jpg"); }';
$files = array();
foreach (array('one', 'two', 'three', 'font', 'animation', 'container', 'property', 'layer', 'import', 'charset', 'image') as $index => $page) {
    // Different content at every ancestor prevents an outer wrapper winning.
    $html = '';
    foreach (range(1, 7) as $depth) $html .= '<div><p>' . $page . ' level ' . $depth . '</p>';
    $html .= $footer . str_repeat('</div>', 7);
    $id = 1000 * ($index + 1);
    $html = preg_replace_callback('/<([a-z][a-z0-9]*)(?=[\s>])/', static function (array $match) use (&$id): string {
        return $match[0] . ' data-blocks-engine-node-id="n' . $id++ . '"';
    }, $html);
    $dependencies = match ($page) {
        'font' => str_replace('font1.woff2', 'changed.woff2', $wixCss),
        'animation' => str_replace('opacity: 0', 'opacity: .5', $wixCss),
        'container' => str_replace('padding-left: 1px', 'padding-left: 99px', $wixCss),
        'property' => str_replace('initial-value: teal', 'initial-value: red', $wixCss),
        'layer' => '@layer wix12, wix1;' . $wixCss,
        'import' => str_replace('/site.css', '/changed.css', $wixCss),
        'charset' => str_replace('UTF-8', 'ISO-8859-1', $wixCss),
        'image' => str_replace('footer.jpg', 'changed.jpg', $wixCss),
        default => $wixCss,
    };
    $css = $dependencies . ':root { --footer-color: #333; } .site-footer { color: var(--footer-color); }'
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
    $journals = $property->getValue($store->inner);
    $assert(1 === count($journals), 'The captured footer must record and reuse one key.');
}
$assert(1 === $store->hits, 'Second captured page must hit the footer journal once (trunk: zero hits).');
$third = $compiler->preparePage($artifact, $shared, 'three.html');
$assert($compile($third) === $compile($third, $store), 'Relevant footer CSS must preserve the unshared receipt.');
$assert(1 === $store->hits, 'Matching footer CSS must not hit the journal.');
$assert(2 === count($property->getValue($store->inner)), 'Matching footer CSS must miss and record a different key.');
foreach (array('font', 'animation', 'container', 'property', 'layer', 'import', 'charset', 'image') as $page) {
    $changed = $compiler->preparePage($artifact, $shared, $page . '.html');
    $before = count($property->getValue($store->inner));
    $assert($compile($changed) === $compile($changed, $store), $page . ': dependency change must preserve the unshared receipt.');
    $assert(1 === $store->hits, $page . ': dependency change must miss.');
    $assert($before + 1 === count($property->getValue($store->inner)), $page . ': dependency change must record a new key.');
}
// A hit alone does not prove replay succeeded: make the recorded block visibly
// different and compile page two again. Only a replay can emit this text.
$key = array_key_first($journals);
$journals[$key]['block']['attrs']['className'] = 'journal-replay-proof';
$property->setValue($store->inner, $journals);
$proof = $compile($plan, $store);
$assert(str_contains($proof, 'journal-replay-proof'), 'The second page must be served by the recorded journal, not converted after a failed replay.');
echo "Captured Wix-shaped chrome: second page 1 hit, dependency changes 0 hits; byte-identical receipts and replay proof passed\n";

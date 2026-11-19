<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal\MemoryChromeJournalStore;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$footer = '<footer class="site-footer"><h2>Contact our studio</h2>';
foreach (range(1, 40) as $item) $footer .= '<p><a href="https://example.com/contact/' . $item . '">Contact our studio about project number ' . $item . '</a></p>';
$footer .= '<canvas width="20" height="20"></canvas></footer>';
$files = array();
foreach (array('one', 'two') as $index => $page) {
    // Different content at every ancestor prevents an outer wrapper winning.
    $html = '';
    foreach (range(1, 7) as $depth) $html .= '<div><p>' . $page . ' level ' . $depth . '</p>';
    $html .= $footer . str_repeat('</div>', 7);
    $id = 1000 * ($index + 1);
    $html = preg_replace_callback('/<([a-z][a-z0-9]*)(?=[\s>])/', static function (array $match) use (&$id): string {
        return $match[0] . ' data-blocks-engine-node-id="n' . $id++ . '"';
    }, $html);
    $html = '<!doctype html><html><head><title>' . $page . '</title></head><body>' . $html . '</body></html>';
    $files[] = array('path' => $page . '.html', 'content' => $html);
}
// Keep both tested pages in the same non-entry scope.
$files[] = array('path' => 'index.html', 'content' => '<html><body><h1>Home</h1></body></html>');
$artifact = array('entrypoint' => 'index.html', 'files' => $files);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$assert(array() !== ($shared['analysis']['repeated_subtrees'] ?? array()), 'Deep captured footer must be a repeated subtree.');
$store = new MemoryChromeJournalStore();
$untimed = static function (mixed $value) use (&$untimed): mixed {
    if (!is_array($value)) return $value;
    foreach (array('metrics', 'step_timings', 'timings', 'work') as $key) unset($value[$key]);
    return array_map($untimed, $value);
};
$compile = static function (array $plan, ?MemoryChromeJournalStore $store = null) use ($compiler, $shared, $untimed): string {
    return json_encode($untimed($compiler->compilePreparedPage($shared, $plan, null, $store)), JSON_THROW_ON_ERROR);
};
$property = new ReflectionProperty(MemoryChromeJournalStore::class, 'journals');
foreach (array('one', 'two') as $page) {
    $plan = $compiler->preparePage($artifact, $shared, $page . '.html');
    $expected = $compile($plan);
    $actual = $compile($plan, $store);
    $assert($expected === $actual, $page . ': shared and unshared receipts must be byte-identical.');
    $journals = $property->getValue($store);
    $assert(1 === count($journals), 'The captured footer must record and reuse one key.');
}
// A hit alone does not prove replay succeeded: make the recorded block visibly
// different and compile page two again. Only a replay can emit this text.
$key = array_key_first($journals);
$journals[$key]['block']['attrs']['className'] = 'journal-replay-proof';
$property->setValue($store, $journals);
$proof = $compile($plan, $store);
$assert(str_contains($proof, 'journal-replay-proof'), 'The second page must be served by the recorded journal, not converted after a failed replay.');
echo "ok\n";

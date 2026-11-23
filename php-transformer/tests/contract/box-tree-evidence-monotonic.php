<?php
declare(strict_types=1);

/**
 * ADR 0006 slice 1 is monotonic: an import without `box-tree/` evidence
 * converts byte-for-byte as it did before evidence could collapse wrappers.
 *
 * `tests/fixtures/box-tree-monotonic/expected.json` was produced by trunk
 * (71c4dcfc) from the fixture's wrapper cases: a padded, painted lane under
 * ancestor-scoped rules, a child-combinator edge, a consumed data attribute,
 * positioned, sized and repeated wrappers, an anchor target and page chrome.
 * The same files with capture node ids but no sidecar, or with a sidecar for
 * another route, convert identically too.
 *
 * If an unrelated conversion change alters this fixture's output, regenerate
 * `expected.json` from a build without box-tree evidence and review that the
 * change is not a wrapper fold.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/box-tree-evidence-fixture.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

function btm_assert(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "box-tree-evidence-monotonic: {$message}\n");
        exit(1);
    }
}

$dir = dirname(__DIR__) . '/fixtures/box-tree-monotonic';
$html = (string) file_get_contents($dir . '/index.html');
$css = (string) file_get_contents($dir . '/s.css');
$expected = json_decode((string) file_get_contents($dir . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);

$compile = static function (string $html, array $extra = array()) use ($css): array {
    $files = array_merge(array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 's.css', 'kind' => 'css', 'content' => $css),
    ), $extra);
    $result = (new ArtifactCompiler())->compile(array('files' => $files))->toArray();
    $assets = array();
    foreach ($result['assets'] as $asset) {
        if ('css' === ($asset['kind'] ?? '') && is_string($asset['content'] ?? null)) $assets[(string) $asset['path']] = $asset['content'];
    }
    ksort($assets);
    return array('serialized_blocks' => (string) $result['serialized_blocks'], 'css' => $assets);
};

btm_assert($expected === $compile($html), 'without box-tree evidence the conversion differs from trunk');

// Capture node ids without a sidecar are identity only, never evidence.
$counter = 0;
$identified = (string) preg_replace_callback('/<(div|p|h2|section|main|header|footer)\b/', static function (array $match) use (&$counter): string {
    return '<' . $match[1] . ' data-blocks-engine-node-id="n' . ++$counter . '"';
}, $html);
btm_assert($expected === $compile($identified), 'capture node ids without evidence changed the conversion');

// A sidecar for another route is not this route's evidence.
$nodes = array();
for ($index = 1; $index <= $counter; ++$index) $nodes['n' . $index] = array();
btm_assert($expected === $compile($identified, array(box_tree_fixture_sidecar('other.html', $identified, $nodes, 'box-tree/other.json'))), 'another route\'s evidence changed the conversion');

fwrite(STDOUT, "box-tree-evidence-monotonic contract passed\n");

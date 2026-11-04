<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ComposeFacts;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex;

/** Distinct pages, identical header/nav/footer, a single shared download. */
function sharedChromeArtifact(int $count): array
{
    $files = array();
    $css = '.chrome{color:red}.chrome a{color:blue}.page{padding:2px}';
    for ($i = 0; $i < 40; ++$i) $css .= '.chrome .item-' . $i . '{padding:2px;color:#123456}';
    $nav = '<header class="chrome"><nav>';
    for ($i = 0; $i < 40; ++$i) $nav .= '<a class="item-' . $i . '" href="p0.html">Link ' . $i . '</a>';
    $nav .= '</nav></header>';
    for ($i = 0; $i < $count; ++$i) {
        $files[] = array('path' => 'p' . $i . '.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><title>Page ' . $i . '</title><link rel="stylesheet" href="base.css"></head><body>' . $nav . '<main class="page page-' . $i . '"><h1>Distinct page ' . $i . '</h1><p>Article ' . $i . ' has its own content.</p></main><footer class="chrome"><p>Shared footer</p></footer></body></html>');
    }
    $files[] = array('path' => 'base.css', 'kind' => 'css', 'content' => $css);
    return array('entrypoint' => 'p0.html', 'compiler_limits' => array('max_files' => $count + 1), 'files' => $files);
}

if (($argv[1] ?? '') === '--artifact') {
    echo json_encode(sharedChromeArtifact((int) ($argv[2] ?? 16)), JSON_THROW_ON_ERROR);
    return;
}

$baseline = null;
foreach (array(4, 16, 64) as $count) {
    $artifact = sharedChromeArtifact($count);
    $facts = new ComposeFacts();
    $documents = array();
    foreach ($artifact['files'] as $file) {
        if ($file['kind'] !== 'html') continue;
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($file['content']);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $documents[$file['path']] = $dom;
    }
    $index = new RouteSelectorMatchIndex($documents, $facts);
    $transformer = new CssStylesheetTransformer($facts);
    foreach ($documents as $owner => $_) {
        // Force the exact production cascade proof, not just document dedupe.
        $delta = $transformer->rulesAbsentFrom(array('.chrome a{color:green}.chrome a{color:blue}'), array('.chrome a{color:blue}'), true, $index, $owner);
        if ($delta !== '.chrome a{color:green}.chrome a{color:blue}') throw new RuntimeException('Cascade bytes changed');
    }
    $shared = $facts->metrics()['compose_route_selector_matches'];
    if ($shared !== 40) throw new RuntimeException('Shared chrome must be matched once: ' . $shared);
    foreach ($documents as $owner => $_) $index->matchedElements('.page', $owner);
    $unique = $facts->metrics()['compose_route_selector_matches'] - $shared;
    if ($unique !== $count) throw new RuntimeException('Page-unique subjects must be matched once per route');
    // Sibling and positional proofs must still observe each real page tree.
    foreach ($documents as $owner => $_) {
        if (count($index->matchedElements('header + main', $owner) ?? array()) !== 1) throw new RuntimeException('Sibling proof lost');
        if (count($index->matchedElements('nav a:nth-child(2)', $owner) ?? array()) !== 1) throw new RuntimeException('Position proof lost');
    }
    $result = (new ArtifactCompiler())->compile($artifact)->toArray();
    $rules = $result['metrics']['compose_rule_unit_collections'];
    if (null !== $baseline && $rules !== $baseline) throw new RuntimeException('Shared rule collection must remain flat');
    $baseline = $rules;
    echo json_encode(array('routes' => $count, 'shared_matches' => $shared, 'unique_matches' => $unique, 'rule_collections' => $rules)) . "\n";
}

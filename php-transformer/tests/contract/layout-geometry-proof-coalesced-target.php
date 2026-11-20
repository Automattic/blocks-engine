<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\LayoutGeometryProof;

// A proof reduces wrapper W into target T. T then coalesces with its single
// child C, so the corrective measured for T must not land on C's box.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$html = '<html><head><style>.banner-wrap{display:block;padding:4px}.header-elements{color:#fff}.section-wrap{display:table}.section{display:table-cell;padding:40px}</style></head><body><main><div class="banner-wrap"><div class="header-elements"><div class="section-wrap"><div class="section"><h1>Hero</h1><p>Copy</p></div></div></div></div></main></body></html>';
$hash = hash('sha256', $html);
$boxes = static fn(): array => array(
    array('viewport' => 390, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 390, 'height' => 200), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 390, 'height' => 200)),
    array('viewport' => 1440, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 200), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 200)),
);
$wrapperSelector = 'main:nth-of-type(1) > div:nth-of-type(1)';
$proof = array('schema' => LayoutGeometryProof::SCHEMA, 'nodes' => array(
    array('id' => 'wrapper', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapperSelector, 'boxes' => $boxes()),
    array('id' => 'target', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapperSelector . ' > div:nth-of-type(1)', 'boxes' => $boxes()),
), 'reductions' => array(array('wrapper' => 'wrapper', 'target' => 'target', 'invariants' => array('selectors' => true, 'runtime' => true, 'semantics' => true, 'viewports' => true), 'corrective_css' => array('declarations' => array(array('property' => 'display', 'value' => 'block'))))));

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html), 'layout_geometry_proof' => $proof))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));

$assert(1 === count($result['source_reports']['layout_geometry_proof'] ?? array()) && !str_contains($markup, 'banner-wrap'), 'The reduction still removes wrapper W.');

// No support-CSS rule that selects a class on C's emitted element may set display:block.
$assert(1 === preg_match('/class="([^"]*\bsection-wrap\b[^"]*)"/', $markup, $element), 'C keeps its own emitted element.');
preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
foreach (preg_split('/\s+/', trim($element[1])) ?: array() as $class) {
    foreach ($rules as $rule) {
        if (!preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $rule[1])) continue;
        $assert(!preg_match('/(^|;)\s*display\s*:\s*block\b/i', trim($rule[2])), 'No support CSS sets display:block on C\'s emitted element (rule: ' . trim($rule[1]) . ').');
    }
}

fwrite(STDOUT, "Layout geometry proof coalesced target contract passed\n");

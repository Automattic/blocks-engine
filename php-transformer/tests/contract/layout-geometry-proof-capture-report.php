<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\LayoutGeometryProof;

// A capture producer ships measured wrapper reductions as the declared root
// `layout-geometry-proof.json` report, not as an inline artifact key. The
// engine's own route-state projection then rewrites navigation attributes in
// the measured document before conversion. Neither step may silently discard
// the proof: the measured wrapper must coalesce exactly as the inline,
// byte-identical contract does.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$nav = static fn(string $current): string => '<header><nav class="site-nav"><a href="/index.html"' . ('home' === $current ? ' class="active"' : '') . '>Home</a><a href="/about/index.html"' . ('about' === $current ? ' class="active"' : '') . '>About</a></nav></header>';
$home = '<!DOCTYPE html><html><body>' . $nav('home') . '<main><div class="proof-shell" data-hook="static-carrier" style="width:80%"><div class="proof-target"><p>Copy</p><p>More copy</p></div></div></main></body></html>';
$about = '<!DOCTYPE html><html><body>' . $nav('about') . '<main><p>About</p></main></body></html>';
$hash = hash('sha256', $home);
$boxes = array(array('viewport' => 1440, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 1152, 'height' => 48), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 1152, 'height' => 48)));
$wrapperSelector = 'main:nth-of-type(1) > div:nth-of-type(1)';
$report = array('schema' => LayoutGeometryProof::SCHEMA, 'nodes' => array(
    array('id' => 'wrapper', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapperSelector, 'boxes' => $boxes),
    array('id' => 'target', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapperSelector . ' > div:nth-of-type(1)', 'boxes' => $boxes),
), 'reductions' => array(array('wrapper' => 'wrapper', 'target' => 'target', 'kind' => 'generic', 'invariants' => array('selectors' => true, 'runtime' => true, 'semantics' => true, 'viewports' => true), 'corrective_css' => array('declarations' => array(array('property' => 'display', 'value' => 'block'))))));
$artifact = array(
    'entrypoint' => 'index.html',
    'entrypoints' => array('index.html', 'about/index.html'),
    'reports' => array('layout-geometry-proof.json'),
    'files' => array(
        'index.html' => $home,
        'about/index.html' => $about,
        'layout-geometry-proof.json' => array('content' => json_encode($report), 'kind' => 'json'),
    ),
);

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$result = $compiler->compose($shared, array_values($compiler->compilePreparedPages($shared, array_values($pages))))->toArray();
$applied = $result['source_reports']['layout_geometry_proof'] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
foreach ($result['pages'] ?? array() as $page) $markup .= (string) ($page['serialized_blocks'] ?? $page['content'] ?? '');

$assert(!in_array('layout_geometry_proof_identity_stale', array_column($result['diagnostics'] ?? array(), 'code'), true), 'The declared capture report validates against the measured source documents.');
$assert(1 === count($applied) && $wrapperSelector === ($applied[0]['wrapper_selector'] ?? null), 'A proof declared as the capture report is applied to its measured wrapper, even after route-state projection rewrites the document.');
$assert(!str_contains($markup, 'proof-shell') && str_contains($markup, 'proof-target') && 1 === preg_match('/be-layout-proof-[a-f0-9]{32}/', $markup), 'The measured wrapper coalesces onto its target with the proof-owned corrective carrier.');

echo "Layout geometry proof capture report contract passed\n";

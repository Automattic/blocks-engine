<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\LayoutGeometryProof;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$html = '<main><div class="proof-shell" data-hook="static-carrier" style="width:80%"><img class="proof-target" src="hero.jpg" alt="Copy"></div></main>';
$hash = hash('sha256', $html);
$boxes = static fn(): array => array(
    array('viewport' => 390, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 312, 'height' => 24), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 312, 'height' => 24)),
    array('viewport' => 1440, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 1152, 'height' => 24), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 1152, 'height' => 24)),
);
$proof = array('schema' => LayoutGeometryProof::SCHEMA, 'nodes' => array(
    array('id' => 'wrapper', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => 'main:nth-of-type(1) > div:nth-of-type(1)', 'boxes' => $boxes()),
    array('id' => 'target', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => 'main:nth-of-type(1) > div:nth-of-type(1) > img:nth-of-type(1)', 'boxes' => $boxes()),
), 'reductions' => array(array('wrapper' => 'wrapper', 'target' => 'target', 'invariants' => array('selectors' => true, 'runtime' => true, 'semantics' => true, 'viewports' => true), 'corrective_css' => array('declarations' => array(array('property' => 'width', 'value' => '80%'))))));
$compile = static fn(array $proof): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html), 'layout_geometry_proof' => $proof))->toArray();

$coalesced = $compile($proof);
$root = $coalesced['blocks'][0] ?? array();
$markup = (string) ($coalesced['serialized_blocks'] ?? '');
$css = implode("\n", array_column($coalesced['assets'] ?? array(), 'content'));
$assert('core/image' === ($root['blockName'] ?? null) && str_contains((string) ($root['attrs']['className'] ?? ''), 'be-layout-proof-') && !str_contains((string) ($root['attrs']['className'] ?? ''), 'proof-shell'), 'Hash-bound structural evidence coalesces a non-neutral wrapper without transferring an author class.');
$assert(preg_match('/be-layout-proof-[a-f0-9]{32}/', $markup, $carrier) && str_contains($css, ':root .' . $carrier[0] . '{width:80%}') && 'pass' === ((new BlockValidityValidator())->validateBlocks($coalesced['blocks'] ?? array())['status'] ?? ''), 'Proof-owned corrective CSS is emitted with valid native serialization.');
$applied = $coalesced['source_reports']['layout_geometry_proof'] ?? array();
$assert(1 === count($applied) && 'main:nth-of-type(1) > div:nth-of-type(1)' === ($applied[0]['wrapper_selector'] ?? null), 'Applied proof provenance retains the stable source-node identity.');

$exact = $proof;
$exact['nodes'][0]['boxes'][0]['simulated']['width'] = 24;
$exact['nodes'][0]['boxes'][1]['simulated']['width'] = 24;
$exact['reductions'][0]['corrective_css']['declarations'] = array();
$exactResult = $compile($exact);
$assert('core/image' === ($exactResult['blocks'][0]['blockName'] ?? null) && !str_contains((string) ($exactResult['blocks'][0]['attrs']['className'] ?? ''), 'be-layout-proof-') && 1 === count($exactResult['source_reports']['layout_geometry_proof'] ?? array()), 'A removed wrapper may change its own box while exact target geometry needs no corrective carrier.');

$withoutEvidence = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$assert('core/group' === (($withoutEvidence['blocks'][0]['blockName'] ?? null)), 'Absent optional evidence retains the non-neutral wrapper.');

$stale = $proof; $stale['nodes'][0]['source_hash'] = str_repeat('0', 64);
$staleResult = $compile($stale);
$assert('core/group' === (($staleResult['blocks'][0]['blockName'] ?? null)) && 'layout_geometry_proof_identity_stale' === ($staleResult['diagnostics'][0]['code'] ?? null), 'Stale source evidence is rejected conservatively.');

$runtime = $proof; $runtime['reductions'][0]['invariants']['runtime'] = false;
$runtimeResult = $compile($runtime);
$assert('core/group' === (($runtimeResult['blocks'][0]['blockName'] ?? null)), 'Missing runtime invariants retain the wrapper.');

$viewport = $proof; $viewport['nodes'][1]['boxes'][1]['simulated']['width'] -= 2;
$viewportResult = $compile($viewport);
$assert('core/group' === (($viewportResult['blocks'][0]['blockName'] ?? null)), 'Viewport geometry disagreement retains the wrapper.');

$unsafeCss = $proof; $unsafeCss['reductions'][0]['corrective_css']['declarations'][0]['value'] = '80%;color:red';
$unsafeCssResult = $compile($unsafeCss);
$assert('core/group' === (($unsafeCssResult['blocks'][0]['blockName'] ?? null)), 'Unsafe corrective CSS retains the wrapper.');

$deepHtml = '<main>' . str_repeat('<div class="layer">', 21) . '<img src="hero.jpg" alt="Copy">' . str_repeat('</div>', 21) . '</main>';
$deepHash = hash('sha256', $deepHtml);
$deepWrapper = 'main:nth-of-type(1) > div:nth-of-type(1)';
$deepTarget = $deepWrapper . ' > div:nth-of-type(1)';
$deepProof = array('schema' => LayoutGeometryProof::SCHEMA, 'nodes' => array(
    array('id' => 'deep-wrapper', 'source_path' => 'deep.html', 'source_hash' => $deepHash, 'selector' => $deepWrapper, 'boxes' => $boxes()),
    array('id' => 'deep-target', 'source_path' => 'deep.html', 'source_hash' => $deepHash, 'selector' => $deepTarget, 'boxes' => $boxes()),
), 'reductions' => array(array('wrapper' => 'deep-wrapper', 'target' => 'deep-target', 'invariants' => array('selectors' => true, 'runtime' => true, 'semantics' => true, 'viewports' => true), 'corrective_css' => array('declarations' => array()))));
$deep = (new ArtifactCompiler())->compile(array('entrypoint' => 'deep.html', 'files' => array('deep.html' => $deepHtml), 'layout_geometry_proof' => $deepProof))->toArray();
$assert(1 === count($deep['source_reports']['layout_geometry_proof'] ?? array()) && !str_contains((string) ($deep['serialized_blocks'] ?? ''), '"kind":"layout"'), 'Explicit measured reductions take precedence over a coarse captured-media layout boundary.');

fwrite(STDOUT, "Layout geometry proof contract passed\n");

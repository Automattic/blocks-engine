<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\LayoutGeometryProof;

// A layout-geometry proof certifies boxes, not inherited state. A wrapper
// whose rules define custom properties that its subtree reads must keep the
// identity that applies them; reducing it would repaint the icon black.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$boxes = static fn(): array => array(
    array('viewport' => 390, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 24, 'height' => 24), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 24, 'height' => 24)),
    array('viewport' => 1440, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 24, 'height' => 24), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 24, 'height' => 24)),
);
$compile = static function (string $css) use ($boxes): array {
    $html = '<style>' . $css . '</style><main><div class="theme-scope"><div class="icon-shell"><a class="icon-link" href="/cart/"><svg viewBox="0 0 10 10" width="24" height="24"><path d="M0 0h10v10H0z"></path></svg></a></div></div></main>';
    $hash = hash('sha256', $html);
    $wrapper = 'main:nth-of-type(1) > div:nth-of-type(1)';
    $proof = array('schema' => LayoutGeometryProof::SCHEMA, 'nodes' => array(
        array('id' => 'wrapper', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapper, 'boxes' => $boxes()),
        array('id' => 'target', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapper . ' > div:nth-of-type(1)', 'boxes' => $boxes()),
    ), 'reductions' => array(array('wrapper' => 'wrapper', 'target' => 'target', 'invariants' => array('selectors' => true, 'runtime' => true, 'semantics' => true, 'viewports' => true), 'corrective_css' => array('declarations' => array(array('property' => 'display', 'value' => 'block'))))));
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html), 'layout_geometry_proof' => $proof))->toArray();
    return array((string) ($result['serialized_blocks'] ?? ''), $result['source_reports']['layout_geometry_proof'] ?? array());
};

[$consumed, $consumedApplied] = $compile('.theme-scope{--icon-paint:51,51,51;display:flex}.icon-link path{fill:rgb(var(--icon-paint,19,0,34))}');
$assert(str_contains($consumed, 'theme-scope') && array() === $consumedApplied, 'A wrapper defining a custom property its subtree reads keeps its identity instead of being reduced by a geometry proof.');

[$unused, $unusedApplied] = $compile('.theme-scope{--unrelated:1;display:flex}.icon-link path{fill:rgb(51,51,51)}');
$assert(!str_contains($unused, 'theme-scope') && 1 === count($unusedApplied), 'A custom property no descendant reads does not block a proven reduction.');

echo "layout-geometry-proof-inherited-custom-property: ok\n";

<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\LayoutGeometryProof;

// A proof-backed wrapper that declines to coalesce must not leave the
// findings of its discarded child conversion behind (#7271).
$html = '<main><div class="shell"><form action="/send" method="post"><label for="n">Name</label><input id="n" name="n" type="text"><label for="m">Message</label><textarea id="m" name="m"></textarea><button type="submit">Send</button></form></div></main>';
$hash = hash('sha256', $html);
$boxes = array(
    array('viewport' => 390, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 312, 'height' => 24), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 312, 'height' => 24)),
    array('viewport' => 1440, 'state' => 'default', 'source' => array('x' => 0, 'y' => 0, 'width' => 1152, 'height' => 24), 'simulated' => array('x' => 0, 'y' => 0, 'width' => 1152, 'height' => 24)),
);
$wrapper = 'main:nth-of-type(1) > div:nth-of-type(1)';
$proof = array('schema' => LayoutGeometryProof::SCHEMA, 'nodes' => array(
    array('id' => 'wrapper', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapper, 'boxes' => $boxes),
    array('id' => 'target', 'source_path' => 'index.html', 'source_hash' => $hash, 'selector' => $wrapper . ' > form:nth-of-type(1)', 'boxes' => $boxes),
), 'reductions' => array(array('wrapper' => 'wrapper', 'target' => 'target', 'invariants' => array('selectors' => true, 'runtime' => true, 'semantics' => true, 'viewports' => true), 'corrective_css' => array('declarations' => array()))));

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html), 'layout_geometry_proof' => $proof))->toArray();
$forms = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)));
if (1 !== count($forms) || array() === ($forms[0]['binding'] ?? array())) {
    fwrite(STDERR, 'Expected one bound form fallback, got ' . count($forms) . ' (' . implode(',', array_map(static fn (array $f): string => array() === ($f['binding'] ?? array()) ? 'unbound' : 'bound', $forms)) . ")\n");
    exit(1);
}
echo "ok\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeScriptEvidenceAnalyzer;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) $failures[] = $message;
};

$analyzer = new RuntimeScriptEvidenceAnalyzer();
$script = 'const button=document.querySelector("#menu");button.addEventListener("click",openMenu);const canvas=document.getElementById("chart");canvas.getContext("2d");document.querySelectorAll("[data-action=save]").forEach((item)=>item.addEventListener("click", save));document.querySelector(".fade-in").classList.add("on");';
$evidence = $analyzer->analyze($script, 'index.html', 'assets/site.js');
$selectors = array_fill_keys($evidence['selectors'], true);
$assert(isset($selectors['#menu']) && isset($selectors['#chart']) && isset($selectors['[data-action]']) && isset($selectors['.fade-in']), 'The analyzer preserves id, attribute, and presentational selectors.');
$dependencies = array_column($evidence['dependencies'], null, 'selector');
$assert(true === $dependencies['#chart']['canvas_api'], 'Canvas API evidence is retained.');
$assert(in_array('click', $dependencies['#menu']['events'], true), 'Assigned event listener evidence is retained.');
$assert(false === $dependencies['.fade-in']['presentation_only'], 'Mutated presentational selectors remain behavioral evidence.');
$assert('index.html' === $evidence['source_path'] && 'assets/site.js' === $evidence['script_path'], 'Evidence carries immutable ownership provenance.');

$minified = $analyzer->analyze('let x=document.getElementById("app");x.appendChild(document.createElement("div"));');
$assert(in_array('#app', $minified['mutation_selectors'], true), 'Minified mutation scripts retain their target.');
$oversized = $analyzer->analyze(str_repeat('x', 1048577), 'index.html', 'assets/large.js');
$assert('runtime_script_analysis_truncated' === $oversized['diagnostics'][0]['code'] && true === $oversized['diagnostics'][0]['fail_closed'], 'Oversized scripts emit a deterministic fail-closed diagnostic.');

$presentation = array_column($analyzer->analyze('document.querySelector(".fade-in");')['dependencies'], null, 'selector');
$assert(true === $presentation['.fade-in']['presentation_only'], 'Canonical unmutated animation selectors remain presentation-only.');
foreach (array('.hero-banner', '#promo-block', '.cart-drawer') as $selector) {
    $dependencies = array_column($analyzer->analyze('document.querySelector("' . $selector . '");')['dependencies'], null, 'selector');
    $assert(false === $dependencies[$selector]['presentation_only'], 'Non-animation selector ' . $selector . ' remains a fail-closed runtime dependency.');
}
$assignedMutation = array_column($analyzer->analyze('const target=document.querySelector(".fade-in");target.classList.add("visible");')['dependencies'], null, 'selector');
$assert(false === $assignedMutation['.fade-in']['presentation_only'], 'Mutation through an assigned selector remains behavioral evidence.');
$idLookup = array_column($analyzer->analyze('document.getElementById("fade-in");')['dependencies'], null, 'selector');
$assert(false === $idLookup['#fade-in']['presentation_only'], 'Presentational IDs reached outside querySelector remain fail-closed runtime dependencies.');
$closestLookup = array_column($analyzer->analyze('target.closest(".fade-in");')['dependencies'], null, 'selector');
$assert(false === $closestLookup['.fade-in']['presentation_only'], 'Presentational closest selectors remain fail-closed runtime dependencies.');

$cachedScript = 'document.querySelector("#runtime-root");';
$analyzer->analyze($cachedScript, 'first.html', 'assets/runtime.js');
$secondOwner = $analyzer->analyze($cachedScript, 'second.html', 'assets/runtime.js');
$assert('second.html' === $secondOwner['source_path'], 'Memoized evidence keeps ownership-specific cache entries.');

if ($failures) throw new RuntimeException(implode("\n", $failures));
fwrite(STDOUT, "runtime-script-evidence-analyzer: {$assertions} assertions\n");

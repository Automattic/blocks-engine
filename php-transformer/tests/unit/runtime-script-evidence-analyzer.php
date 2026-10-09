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
    $assert(isset($selectors['#menu']) && isset($selectors['#chart']) && isset($selectors['[data-action="save"]']) && !isset($selectors['[data-action]']) && isset($selectors['.fade-in']), 'The analyzer preserves id, attribute equality, and presentational selectors.');
$dependencies = array_column($evidence['dependencies'], null, 'selector');
$assert('[data-action="save"]' === ($dependencies['[data-action="save"]']['selector'] ?? null) && !isset($dependencies['[data-action]']), 'dependency selector metadata keeps equality instead of collapsing to presence');
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
$compoundSelectors = $analyzer->analyze('document.querySelectorAll("[data-x-stage][data-x-trigger]");')['selectors'];
$assert(array('[data-x-stage][data-x-trigger]') === $compoundSelectors, 'Adjacent data-attribute selectors remain one compound target instead of independent targets.');

$cachedScript = 'document.querySelector("#runtime-root");';
$analyzer->analyze($cachedScript, 'first.html', 'assets/runtime.js');
$secondOwner = $analyzer->analyze($cachedScript, 'second.html', 'assets/runtime.js');
$assert('second.html' === $secondOwner['source_path'], 'Memoized evidence keeps ownership-specific cache entries.');

$directTheme = $analyzer->themePreferenceOwnership('const key="theme";localStorage.getItem("theme");localStorage.setItem("theme","dark");document.documentElement.classList.toggle("dark");matchMedia("(prefers-color-scheme: dark)");');
$assert(is_array($directTheme) && 'theme' === $directTheme['storageKey'] && array('dark') === $directTheme['rootClassValues'], 'Direct root class ownership and matching literal storage key are recognized.');
$aliasTheme = $analyzer->themePreferenceOwnership('const root=document.documentElement;const key="theme";const config={storageKey:key};localStorage.getItem(key);localStorage.setItem(key,"dark");root.setAttribute("data-theme","dark");matchMedia("(prefers-color-scheme: dark)");');
$assert(is_array($aliasTheme) && 'theme' === $aliasTheme['storageKey'] && 'data-theme' === $aliasTheme['rootAttribute'] && 'dark' === $aliasTheme['rootAttributeValue'], 'Root alias and storageKey-config variable ownership are recognized.');
$nextThemes = $analyzer->themePreferenceOwnership('const provider=({storageKey:l="theme"})=>{let root=document.documentElement;let [mode,setMode]=useState(()=>h(l,"system"));const h=(key,fallback)=>localStorage.getItem(key)||fallback;const apply=value=>{root.classList.remove(...["dark"]);root.classList.add(value)};const change=value=>{setMode(value);apply(value);localStorage.setItem(l,value)};window.matchMedia("(prefers-color-scheme: dark)")};');
$assert(is_array($nextThemes) && 'theme' === $nextThemes['storageKey'] && 'class' === $nextThemes['rootAttribute'], 'A bounded provider getter/setter using the same storageKey and root-owned class mutation proves one preference contract.');
$nextThemesWithStorageHelper = $analyzer->themePreferenceOwnership('const provider=({storageKey:l="theme"})=>{const h=(e,r)=>localStorage.getItem(e)||r;let root=document.documentElement;const [mode,setMode]=useState(()=>h(l,"system"));const change=value=>{setMode(value);root.classList.remove(...["dark"]);root.classList.add(value);localStorage.setItem(l,value)};const media="(prefers-color-scheme: dark)";window.matchMedia(media)};');
$assert(is_array($nextThemesWithStorageHelper) && 'theme' === $nextThemesWithStorageHelper['storageKey'], 'A bounded storage helper call resolves the same declared key used by the root-owned mode transition.');
$nextThemesBundle = $analyzer->themePreferenceOwnership('const provider=({storageKey:l="theme"})=>{let root=document.documentElement;const h=(e,t)=>{try{return localStorage.getItem(e)||t}catch{return t}};let [mode,setMode]=useState(()=>h(l,"system"));const apply=value=>{root.classList.remove(...["light","dark"]);root.classList.add(value)};const choose=value=>{setMode(value);apply(value);localStorage.setItem(l,value)};const scheme="(prefers-color-scheme: dark)";window.matchMedia(scheme)};');
$assert(is_array($nextThemesBundle) && 'theme' === $nextThemesBundle['storageKey'] && 'class' === $nextThemesBundle['rootAttribute'] && array('light', 'dark') === $nextThemesBundle['rootClassValues'], 'A bundled provider storage helper and root-class theme contract are recognized by bounded ownership evidence.');
$negativeTheme = $analyzer->themePreferenceOwnership('localStorage.getItem("cart");localStorage.setItem("cart","x");const value="theme";matchMedia("(prefers-color-scheme: dark)");drawer.classList.toggle("open");');
$assert(null === $negativeTheme, 'Unrelated storage, theme text, OS media, and drawer mutation do not establish root ownership.');
$negativeRootTheme = $analyzer->themePreferenceOwnership('const root=document.documentElement;root.classList.toggle("drawer-open");localStorage.getItem("cart");localStorage.setItem("cart","x");const value="theme";matchMedia("(prefers-color-scheme: dark)");');
$assert(is_array($negativeRootTheme) && 'cart' === $negativeRootTheme['storageKey'] && array('drawer-open') === $negativeRootTheme['rootClassValues'], 'The evidence analyzer does not pretend an unrelated root class token is the CSS-proven theme class.');
$negativeCompoundClass = $analyzer->themePreferenceOwnership('const root=document.documentElement;root.classList.toggle("drawer.open");localStorage.getItem("cart");localStorage.setItem("cart","x");const value="theme";matchMedia("(prefers-color-scheme: dark)");');
$assert(null === $negativeCompoundClass, 'A compound drawer selector is rejected as an invalid root class token rather than treated as theme ownership.');
$truncatedTheme = $analyzer->themePreferenceOwnership(str_repeat('x', 1048577));
$assert(null === $truncatedTheme, 'Oversized theme preference scripts fail closed.');

if ($failures) throw new RuntimeException(implode("\n", $failures));
fwrite(STDOUT, "runtime-script-evidence-analyzer: {$assertions} assertions\n");

<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ThemePreferenceOwnership;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$sourcePath = 'index.html';
$runtimePath = 'assets/theme.js';
$runtime = '(()=>{const root=document.documentElement;const storageKey="theme";const media=window.matchMedia("(prefers-color-scheme: dark)");let preference=localStorage.getItem(storageKey)||"system";const apply=()=>{const resolved="system"===preference?(media.matches?"dark":"light"):preference;root.classList.toggle("dark","dark"===resolved);root.classList.toggle("light","light"===resolved)};apply();localStorage.setItem(storageKey,preference)})();';
$root = array('selector' => 'html', 'attribute' => 'class', 'dark_value' => 'dark', 'light_value' => 'light', 'light_operation' => 'set-theme-class');
$controls = array(
    array('mode' => 'light', 'accessible_name' => 'Light theme', 'icon' => 'sun'),
    array('mode' => 'system', 'accessible_name' => 'System theme', 'icon' => 'monitor'),
    array('mode' => 'dark', 'accessible_name' => 'Dark theme', 'icon' => 'moon'),
);
$rootState = static fn (string $value): array => array('attribute' => 'class', 'value' => $value);
$ownership = array(
    'schema' => ThemePreferenceOwnership::CONTRACT_SCHEMA,
    'source_path' => $sourcePath,
    'group_selector' => 'footer > div > div.flex.justify-between.items-start.gap-8.mb-12 > div.flex.transition-opacity.duration-200.opacity-100',
    'runtime_script_path' => $runtimePath,
    'runtime_script_sha256' => hash('sha256', $runtime),
    'runtime_script_content' => $runtime,
    'storage_key' => 'theme',
    'system_query' => '(prefers-color-scheme: dark)',
    'root' => $root,
    'default_preference' => 'system',
    'default_observations' => array(
        array('storage_value' => null, 'os_scheme' => 'light', 'resolved' => 'light', 'root_state' => $rootState('light')),
        array('storage_value' => null, 'os_scheme' => 'dark', 'resolved' => 'dark', 'root_state' => $rootState('dark')),
    ),
    'controls' => $controls,
    'observed_transitions' => array(
        array('mode' => 'light', 'storage_value' => 'light', 'resolved' => 'light', 'root_state' => $rootState('light')),
        array('mode' => 'dark', 'storage_value' => 'dark', 'resolved' => 'dark', 'root_state' => $rootState('dark')),
        array('mode' => 'system', 'storage_value' => 'system', 'os_scheme' => 'light', 'resolved' => 'light', 'root_state' => $rootState('light')),
        array('mode' => 'system', 'storage_value' => 'system', 'os_scheme' => 'dark', 'resolved' => 'dark', 'root_state' => $rootState('dark')),
    ),
);
$html = '<!doctype html><html class="dark"><head><link rel="stylesheet" href="assets/site.css"></head><body><main><footer><div><div class="flex justify-between items-start gap-8 mb-12"><div class="flex transition-opacity duration-200 opacity-100"><button type="button" aria-label="Light theme"><svg class="lucide lucide-sun"><path d="M1 1"></path></svg></button><button type="button" aria-label="System theme"><svg class="lucide lucide-monitor"><path d="M1 1"></path></svg></button><button type="button" aria-label="Dark theme"><svg class="lucide lucide-moon"><path d="M1 1"></path></svg></button></div></div></div></footer></main><script src="assets/theme.js"></script></body></html>';
$artifact = array(
    'entrypoint' => $sourcePath,
    'files' => array(
        $sourcePath => $html,
        'assets/site.css' => ':root{--background:#fff}:root:not(.dark){--background:#fff}.dark{--background:#111}',
        $runtimePath => $runtime,
    ),
    'runtime_declarations' => array(array(
        'kind' => ThemePreferenceOwnership::DECLARATION_KIND,
        'type' => ThemePreferenceOwnership::DECLARATION_TYPE,
        'source_path' => $sourcePath,
        'payload' => array('schema' => ThemePreferenceOwnership::DECLARATION_SCHEMA, 'ownership' => $ownership),
    )),
);
$find = static function (array $blocks) use (&$find): array {
    foreach ($blocks as $block) {
        if ('custom/theme-toggle' === ($block['blockName'] ?? null)) return $block;
        $nested = $find($block['innerBlocks'] ?? array());
        if (array() !== $nested) return $nested;
    }
    return array();
};
$whole = (new ArtifactCompiler())->compile($artifact);
$wholeBlock = $find($whole->blocks);
$assert('custom/theme-toggle' === ($wholeBlock['blockName'] ?? null)
    && 'system' === ($wholeBlock['attrs']['defaultTheme'] ?? null)
    && 'dark' === ($wholeBlock['attrs']['darkValue'] ?? null)
    && 'light' === ($wholeBlock['attrs']['lightValue'] ?? null), 'normal ArtifactCompiler input delivers the canonical source ownership declaration to source conversion.');
$assert(array() === $whole->fallbacks, 'the canonical source-owned three-button artifact compiles with no fallback blocks.');

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pagePlans = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pagePlans);
$stagedResult = $compiler->compose($shared, $receipts);
$stagedBlock = $find($stagedResult->blocks);
$assert('custom/theme-toggle' === ($stagedBlock['blockName'] ?? null)
    && $stagedBlock['attrs'] === $wholeBlock['attrs'], 'staged page transport retains the same qualified ownership, selected state, and saved canonical control as whole compilation.');
$assert(array() === $stagedResult->fallbacks, 'staged canonical theme control compiles without fallback blocks.');

$wholeView = (new WordPressSitePlanView())->fromResult($whole);
$plan = $wholeView['wordpress_site_plan'];
$declaration = current(array_filter($plan['runtime_declarations'], static fn (array $row): bool => ThemePreferenceOwnership::DECLARATION_KIND === ($row['kind'] ?? null)));
$assert(ThemePreferenceOwnership::CONTRACT_SCHEMA === ($declaration['payload']['ownership']['schema'] ?? null), 'the canonical WordPress site plan carries the validated source ownership declaration.');
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://wp.test/wp-content/themes/nick', 'runtime_capabilities' => array()));
$assert($declaration['payload'] === current(array_filter($resolved['runtime_declarations'], static fn (array $row): bool => ThemePreferenceOwnership::DECLARATION_KIND === ($row['kind'] ?? null)))['payload'], 'plan resolution preserves the source-owned runtime declaration without weakening or regenerating its evidence.');
$exported = (new WordPressSitePlanView())->compact(array_replace($wholeView, array('wordpress_site_plan' => $resolved)));
$reimported = WordPressSitePlanView::materialize($exported);
$roundTripped = current(array_filter($reimported['wordpress_site_plan']['runtime_declarations'], static fn (array $row): bool => ThemePreferenceOwnership::DECLARATION_KIND === ($row['kind'] ?? null)));
$assert($declaration['payload'] === ($roundTripped['payload'] ?? null), 'SSI-facing compact export and reimport retain the exact source-qualified evidence payload and resource hash.');

$unqualified = $artifact;
$unqualified['runtime_declarations'] = array();
$unqualifiedResult = (new ArtifactCompiler())->compile($unqualified);
$assert(null === ($find($unqualifiedResult->blocks)['blockName'] ?? null), 'the same source buttons remain ordinary controls when the canonical artifact declaration is absent.');

fwrite(STDOUT, "theme-preference-ownership-artifact: whole/staged/plan/export/reimport handoff passed\n");

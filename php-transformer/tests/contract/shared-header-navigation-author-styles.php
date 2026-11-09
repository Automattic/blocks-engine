<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationInstanceDiagnostics;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$landmarkless = static fn(string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="menu.css"></head><body><section class="a">logo</section><section class="b">banner</section>' . ('About' === $title ? '<section class="notice">Notice</section>' : '') . '<section class="c"><div class="wrap"><nav class="menu-nav"><ul class="menu"><li><a class="menu-link" href="/index.html">Home</a></li><li><a class="menu-link" href="/about.html">About</a></li></ul></nav></div></section><section class="post"><h1>' . $title . '</h1><p>Page body.</p></section></body></html>';
$landmarked = static fn(string $title): string => '<!doctype html><html><head><link rel="stylesheet" href="menu.css"></head><body><div>Route prelude</div><div class="shell"><header><nav><ul class="menu"><li><a class="menu-link" href="/index.html">Home</a></li><li><a class="menu-link" href="/about.html">About</a></li></ul></nav></header><main><h1>' . $title . '</h1><p>Page body.</p></main></div></body></html>';
foreach (array($landmarked, $landmarkless) as $page) {
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page('Home'), 'about.html' => $page('About'), 'menu.css' => '.menu .menu-link{display:flex;padding:20px;font-size:14px}.menu .menu-link:hover{color:red}')))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
if ($page === $landmarked) $assert(1 === count($headers), 'Both pages factor one shared header.');
$markup = implode('', array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup'));
$css = implode("\n", array_column($plan['assets'], 'content'));
$assert(array() === NavigationInstanceDiagnostics::collect($plan['assets'], $markup), 'Asset assembly finds no orphaned navigation instance.');
preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', $css, $instances);
foreach (array_unique($instances[0]) as $instance) $assert(str_contains($markup, $instance), 'Projected instance must render: ' . $instance);
preg_match('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', $headers[0]['canonical_block_markup'] ?? $markup, $host);
if ($page === $landmarkless) {
    preg_match('~<section class="c">.*?</section>~', $page('Home'), $chromeSource);
    $isolated = (new ArtifactCompiler())->compile(array('entrypoint' => 'chrome.html', 'files' => array('chrome.html' => '<html><head><link rel="stylesheet" href="menu.css"></head><body>' . $chromeSource[0] . '</body></html>', 'menu.css' => '.menu .menu-link{display:flex;padding:20px;font-size:14px}')))->toArray()['source_reports']['wordpress_site_plan'];
    $isolatedMarkup = implode('', array_column(array_merge($isolated['pages'], $isolated['templates'], $isolated['template_parts']), 'canonical_block_markup'));
    $assert(str_contains($isolatedMarkup, $host[0]), 'Landmark-less menu keeps its instance when compiled as isolated chrome.');
}
$assert(isset($host[0]), 'Shared navigation has an instance marker.');
$assert(1 === preg_match('/[^{}]*' . preg_quote($host[0], '/') . '[^{}]*wp-block-navigation-item__content[^{}]*\{[^{}]*display:flex[^{}]*padding:20px[^{}]*font-size:(?:14px|var\\([^{}]*14px\\))/', $css), 'Author link box declarations target the rendered shared instance.');
// Core's rendered host/item shape, using only generated assets (not source CSS).
preg_match('/blocks-engine-navigation-anchor-[a-f0-9]{12}-[0-9]+/', implode('', array_column($plan['menus'], 'block_markup')), $anchor);
$html = '<html><head><style>' . $css . '</style></head><body><nav class="wp-block-navigation ' . $host[0] . '"><ul class="wp-block-navigation__container"><li class="wp-block-navigation-item ' . ($anchor[0] ?? '') . '"><a class="wp-block-navigation-item__content">Home</a></li></ul></nav>';
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $temp = tempnam(sys_get_temp_dir(), 'shared-header-nav-');
    $file = $temp . '.html';
    unlink($temp);
    file_put_contents($file, $html . '<script>const s=getComputedStyle(document.querySelector("a"));document.body.dataset.link=JSON.stringify([s.display,s.paddingTop,s.paddingRight,s.paddingBottom,s.paddingLeft]);</script></body></html>');
    try {
        $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --window-size=1440,900 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        preg_match('/data-link="([^"]+)"/', (string) $output, $computed);
        $actual = json_decode(html_entity_decode($computed[1] ?? '', ENT_QUOTES), true);
        $assert(array('flex', '20px', '20px', '20px', '20px') === $actual, 'Rendered shared link retains its author box: ' . json_encode($actual));
    } finally { unlink($file); }
}
}
$twoMenus = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><head><style>nav a{display:flex;padding:20px}</style></head><body><section class="desktop"><nav class="primary"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></section><section class="mobile"><nav class="secondary"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav></section></body></html>')))->toArray()['source_reports']['wordpress_site_plan'];
$twoMarkup = implode('', array_column(array_merge($twoMenus['pages'], $twoMenus['templates'], $twoMenus['template_parts']), 'canonical_block_markup'));
preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', $twoMarkup, $twoInstances);
$assert(2 === count(array_unique($twoInstances[0])), 'Distinct menu source identities remain distinct on one page.');
$orphan = 'blocks-engine-navigation-instance-0123456789ab-0';
$diagnostics = NavigationInstanceDiagnostics::collect(array(array('kind' => 'css', 'source_path' => 'menu.css', 'content' => '.' . $orphan . ' a{padding:20px} .' . $orphan . ' a:hover{color:red}')), '');
$assert(1 === count($diagnostics) && $orphan === $diagnostics[0]['instance_marker'] && 'warning' === $diagnostics[0]['severity'], 'Missing instances produce one actionable warning per marker.');
$assert(array() === NavigationInstanceDiagnostics::collect(array(array('kind' => 'css', 'content' => '.' . $orphan . ' a{padding:20px}')), '<nav class="' . $orphan . '">'), 'Carried instances are not diagnosed.');
echo "Shared header navigation author styles passed.\n";

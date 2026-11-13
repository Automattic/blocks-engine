<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationInstanceDiagnostics;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$instances = static function (string $text): array { preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', $text, $match); return array_values(array_unique($match[0])); };

// A per-render menu id gives each route's menu its own instance, then one
// shared header renders a single one of them. The others' rules are dead.
$page = static fn(string $title, int $render): string => '<!doctype html><html><head><link rel="stylesheet" href="menu.css"></head><body><div class="shell"><header><nav><ul id="nav-' . $render . '" class="menu"><li><a class="menu-link" href="/index.html">Home</a></li><li><a class="menu-link" href="/about.html">About</a></li></ul></nav></header><main><h1>' . $title . '</h1><p>Page body.</p></main></div></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page('Home', 14), 'about.html' => $page('About', 95), 'menu.css' => '.menu .menu-link{display:flex;padding:20px;font-size:14px}.menu .menu-link:hover{color:red}')))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$markup = implode('', array_column(array_merge($plan['pages'], $plan['templates'], $plan['template_parts']), 'canonical_block_markup')) . implode('', array_column($plan['menus'], 'block_markup'));
$css = implode("\n", array_column($plan['assets'], 'content'));
$rendered = $instances($markup);
$assert(1 === count($rendered), 'One shared header renders one navigation instance.');
$assert($rendered === $instances($css), 'Stylesheets carry rules only for the rendered instance: ' . json_encode($instances($css)));
$assert(1 === preg_match('/' . $rendered[0] . '[^{}]*\{[^{}]*padding:20px/', $css), 'The rendered instance keeps its author rules.');
$assert(array() === array_filter($plan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_orphaned_navigation_instance' === ($diagnostic['code'] ?? '')), 'No orphaned instance is left to diagnose.');

// Only selector arms that require a dead instance go; everything that could
// still match the rendered page stays, at its authored specificity.
$live = 'blocks-engine-navigation-instance-aaaaaaaaaaaa-0';
$dead = 'blocks-engine-navigation-instance-bbbbbbbbbbbb-0';
$cases = array(
    ".wp-block-navigation.$dead a{color:red}" => '',
    ".$live a,.$dead a{color:blue}" => ".$live a{color:blue}",
    ":root :is(.wp-block-navigation.$live,.wp-block-navigation.$dead) li{color:green}" => ":root :is(.wp-block-navigation.$live) li{color:green}",
    "@media (min-width:1px){:is(.$dead,.x) b{color:teal}}" => '@media (min-width:1px){:is(.x) b{color:teal}}',
    "a:not(:where(.$dead *)){color:pink}" => "a:not(:where(.$dead *)){color:pink}",
    "[class*=\"$dead\"]{color:gold}" => "[class*=\"$dead\"]{color:gold}",
    ":is(.$live,#a.$dead) i{color:navy}" => ":is(.$live,#a.$dead) i{color:navy}",
);
foreach ($cases as $input => $expected) {
    $actual = NavigationInstanceDiagnostics::withoutOrphanedRules(array(array('kind' => 'css', 'source_path' => 'menu.css', 'content' => $input)), '<nav class="' . $live . '"></nav>')[0]['content'];
    $assert($expected === trim($actual), "Pruned {$input} to " . json_encode($actual));
}

echo "Navigation orphaned instance pruning contract passed.\n";

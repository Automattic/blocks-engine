<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$document = static fn(string $title): string => '<!doctype html><html><head><style>body{margin:0}.frame{height:100vh;display:flex;flex-direction:column}.frame>main{flex:1}.frame>header{height:72px}.frame>footer{height:48px}</style></head><body><div class="frame"><header class="masthead"><nav><a href="/">Home</a><a href="/about">About</a></nav></header><main><h1>' . $title . '</h1></main><footer class="colophon"><p>Shared footer</p></footer></div></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $document('Home'), 'about.html' => $document('About'), 'team.html' => $document('Team'))))->toArray()['source_reports']['wordpress_site_plan'];
$parts = array_column($plan['template_parts'], null, 'slug');
$assert(isset($parts['header'], $parts['footer']) && 'inline_shared_shell' === $parts['header']['placement']['kind'] && 'inline_shared_shell' === $parts['footer']['placement']['kind'], 'Nested shared header and footer retain occurrence-owned placement.');
$assert(3 === count($parts['header']['placement']['source_paths']) && 3 === count($parts['footer']['placement']['source_paths']), 'Shared ownership accounts for every applicable route.');
$runtime = new Runtime();
foreach ($plan['pages'] as $page) {
    $blocks = $runtime->parseBlocks($page['canonical_block_markup']);
    $found = false;
    $walk = static function (array $blocks) use (&$walk, &$found, $assert, $page): void {
        foreach ($blocks as $block) {
            if (str_contains((string) ($block['attrs']['className'] ?? ''), 'frame')) {
                $children = $block['innerBlocks'];
                $slugs = array_values(array_filter(array_map(static fn(array $child): ?string => $child['attrs']['slug'] ?? null, $children)));
                $assert(array('header', 'footer') === $slugs, $page['source_path'] . ' keeps both references under the original flex parent and in source order.');
                $assert('header' === ($children[0]['attrs']['slug'] ?? null) && 'footer' === ($children[count($children) - 1]['attrs']['slug'] ?? null), 'Page-specific main content remains between shared shell references.');
                $found = true;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($blocks);
    $assert($found, 'The authored layout ancestor survives extraction.');
}
foreach ($plan['templates'] as $template) if (in_array($template['slug'], array('front-page', 'page'), true)) $assert(!str_contains($template['canonical_block_markup'], 'wp:template-part'), 'Templates do not duplicate in-place shared chrome.');

$twoLinkPage = static fn(string $title): string => '<style>a{text-decoration:none}</style><div class="frame"><header><nav><a href="index.html">Home</a><a href="about.html">About</a></nav><details><summary>Menu</summary><nav class="mobile"><a href="index.html">Home</a><a href="about.html">About</a></nav></details></header><main><h1>' . $title . '</h1></main><footer><p>Shared footer</p></footer></div>';
$twoLink = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $twoLinkPage('Home'), 'about.html' => $twoLinkPage('About'))))->toArray()['source_reports']['wordpress_site_plan'];
$assert(isset(array_column($twoLink['template_parts'], null, 'slug')['header']), 'A two-link header does not fragment because current-state cleanup deleted its resting text decoration.');
$assert(1 === count($twoLink['menus']), 'Equivalent primary and details-owned menus share one presentation-preserving entity.');
$assert(str_contains($twoLink['menus'][0]['block_markup'], '"textDecoration":"none"'), 'Resting author presentation survives current-route normalization.');
echo "shared-shell-containment: ok\n";

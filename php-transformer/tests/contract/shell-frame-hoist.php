<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Every route is wrapped in an app root: a layout frame holds a pinned header
// layer, the page's main block and the footer, and a pinned bar follows the
// frame. The shared header and footer are template parts, so the root, the
// frame and the pinned bar belong to the template. The page keeps only main.
$document = static fn (string $title, string $body): string => '<!doctype html><html><head><style>.app{min-height:100vh;display:flex;flex-direction:column}.app>main{flex:1}.top{position:fixed;top:0;left:0;right:0}.cta{position:fixed;bottom:0}</style></head><body><div id="root"><div class="app"><div class="top"><header class="masthead"><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="team.html">Team</a></nav></header></div><main><h1>' . $title . '</h1><p>' . $body . '</p></main><footer class="colophon"><p>Shared footer</p></footer></div><div class="cta"><a href="tel:5551234">Call us</a></div></div></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $document('Home', 'Welcome'),
    'about.html' => $document('About', 'Who we are'),
    'team.html' => $document('Team', 'Meet us'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$parts = array_column($plan['template_parts'], null, 'slug');
$assert(isset($parts['header'], $parts['footer']), 'The shared header and footer are template parts.');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');

foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $assert(!str_contains($markup, 'wp:template-part'), $page['slug'] . ' content holds no template-part block.');
    foreach (array('id="root"', 'app', 'masthead', 'colophon', 'Call us') as $needle) $assert(!str_contains($markup, $needle), $page['slug'] . ' content holds no app wrapper or chrome (' . $needle . ').');
    $assert(str_contains($markup, '>' . ('home' === $page['slug'] ? 'Home' : ucfirst($page['slug'])) . '</h1>') && str_contains($markup, '"tagName":"main"'), $page['slug'] . ' content keeps its own main block.');
}

$runtime = new Runtime();
foreach (array('front-page', 'page') as $slug) {
    $markup = $templates[$slug] ?? '';
    $assert(1 === substr_count($markup, '"slug":"header"') && 1 === substr_count($markup, '"slug":"footer"') && 1 === substr_count($markup, 'wp:post-content'), "The {$slug} template references each part once and has one post-content block.");
    $root = array_values(array_filter($runtime->parseBlocks($markup), static fn (array $block): bool => null !== $block['blockName']));
    $assert(1 === count($root) && 'root' === ($root[0]['attrs']['anchor'] ?? null), "The {$slug} template starts with the app root.");
    $children = array_values(array_filter($root[0]['innerBlocks'], static fn (array $block): bool => null !== $block['blockName']));
    $assert(2 === count($children), "The {$slug} root holds the frame and the pinned bar.");
    $frame = array_values(array_filter($children[0]['innerBlocks'], static fn (array $block): bool => null !== $block['blockName']));
    $names = array_map(static fn (array $block): string => 'core/template-part' === $block['blockName'] ? 'part:' . $block['attrs']['slug'] : $block['blockName'], $frame);
    $assert(3 === count($names) && 'core/group' === $names[0] && 'core/post-content' === $names[1] && 'part:footer' === $names[2], "The {$slug} frame keeps the header layer, post-content and the footer in source order.");
    $assert(str_contains($markup, 'blocks-engine-frame-content') && str_contains($markup, 'Call us'), "The {$slug} template keeps the post-content marker and the pinned bar.");
}

// The theme drops Core's post-content wrapper element for the marked block, so
// the page content stays a direct child of the frame, as in the source.
$functions = '';
foreach ($plan['writes'] as $write) if (str_ends_with((string) $write['target_path'], 'functions.php')) $functions = (string) ($write['payload']['data'] ?? '');
$assert(str_contains($functions, "render_block_core/post-content") && str_contains($functions, 'blocks-engine-frame-content'), 'The theme strips the post-content wrapper for the marked block.');

// A page is left alone when the split is not clearly safe: here the entry page
// has two header references (a desktop and a mobile document).
$pair = static fn (string $variant): string => '<div class="' . $variant . '-document"><div id="root"><div class="app"><header class="masthead"><p>Brand</p></header><main><h1>Home</h1></main><footer class="colophon"><p>Shared footer</p></footer></div></div></div>';
$single = static fn (string $title): string => '<div id="root"><div class="app"><header class="masthead"><p>Brand</p></header><main><h1>' . $title . '</h1></main><footer class="colophon"><p>Shared footer</p></footer></div></div>';
$fallback = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $pair('desktop') . $pair('mobile'), 'about.html' => $single('About'))))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($fallback);
$fallbackTemplates = array_column($fallback['templates'], 'canonical_block_markup', 'slug');
$fallbackPages = array_column($fallback['pages'], 'canonical_block_markup', 'slug');
$assert(!str_contains($fallbackTemplates['front-page'] ?? '', 'wp:template-part') && str_contains($fallbackPages['home'] ?? '', 'wp:template-part'), 'A page that cannot be split safely keeps its references in place.');

echo "shell-frame-hoist: ok\n";

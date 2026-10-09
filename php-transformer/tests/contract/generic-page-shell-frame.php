<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Most inner pages share one frame, one differs, one has no shared chrome. The
// generic page template carries the shared frame, so a page created later in
// WordPress renders the site chrome. The odd pages get their own templates.
$style = '<style>.app{min-height:100vh;display:flex;flex-direction:column}.app>main{flex:1}.top{position:fixed;top:0}.cta{position:fixed;bottom:0}</style>';
$chrome = static fn (string $title): string => '<div id="root"><div class="app"><div class="top"><header class="masthead"><nav><a href="index.html">Home</a><a href="a.html">A</a><a href="b.html">B</a></nav></header></div><main><h1>' . $title . '</h1></main><footer class="colophon"><p>Shared footer</p></footer></div><div class="cta"><a href="tel:5551234">Call</a></div></div>';
$page = static fn (string $body): string => '<!doctype html><html><head>' . $style . '</head><body>' . $body . '</body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page($chrome('Home')),
    'a.html' => $page($chrome('A')),
    'b.html' => $page($chrome('B')),
    'c.html' => $page($chrome('C')),
    'odd.html' => $page('<div class="outer">' . $chrome('Odd') . '</div>'),
    'bare.html' => $page('<main><h1>Bare</h1><p>No shared chrome here.</p></main>'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');

$assert(1 === substr_count($templates['page'] ?? '', '"slug":"header"') && 1 === substr_count($templates['page'] ?? '', '"slug":"footer"') && str_contains($templates['page'], 'wp:post-content') && str_contains($templates['page'], 'Call'), 'The generic page template holds the header, footer and shared frame.');
foreach (array('a', 'b', 'c') as $slug) $assert(!isset($templates['page-' . $slug]), "Page {$slug} uses the generic page template.");
$assert(isset($templates['page-odd']) && str_contains($templates['page-odd'], 'outer') && !str_contains($templates['page'], 'outer'), 'A page with a different frame gets its own template.');
$assert(isset($templates['page-bare']) && !str_contains($templates['page-bare'], 'wp:template-part') && !str_contains($templates['page-bare'], 'Call'), 'A page without the shared chrome gets a plain template of its own.');
foreach ($plan['pages'] as $row) if (in_array($row['slug'], array('a', 'b', 'c', 'odd'), true)) $assert(!str_contains($row['canonical_block_markup'], 'wp:template-part'), $row['slug'] . ' content holds no template part.');

// Frames compare without per-document marker seeds and dialog ids.
$identity = new ReflectionMethod(ShellExtraction::class, 'frameIdentity');
$frame = static fn (string $seed, string $dialog): array => array('opening' => '<!-- wp:group {"className":"min-h-screen blocks-engine-source-div-' . $seed . '-4"} --><div id="blocks-engine-dialog-' . $dialog . '">', 'closing' => '</div><!-- /wp:group -->');
$assert($identity->invoke(null, $frame('aaaaaaaaaaaa', '0123456789abcdef')) === $identity->invoke(null, $frame('bbbbbbbbbbbb', 'fedcba9876543210')), 'Per-page marker seeds and dialog ids do not make frames differ.');
$assert($identity->invoke(null, $frame('aaaaaaaaaaaa', '0123456789abcdef')) !== $identity->invoke(null, array('opening' => '<div class="other">', 'closing' => '</div>')), 'Different structure still differs.');

echo "generic-page-shell-frame: ok\n";

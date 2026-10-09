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
$style = '<style>.app{min-height:100vh;display:flex;flex-direction:column}.app>main{flex:1}.top{position:fixed;top:0}.cta{position:fixed;bottom:0}.btn{display:inline-block;background:#222;color:#fff;padding:8px 16px;border-radius:99px}</style>';
$chrome = static fn (string $title): string => '<div id="root"><div class="app"><div class="top"><header class="masthead"><nav><a href="index.html">Home</a><a href="a.html">A</a><a href="b.html">B</a></nav></header></div><main><h1>' . $title . '</h1></main><footer class="colophon"><p>Shared footer</p></footer></div><div class="cta"><a class="btn" href="tel:5551234">Call</a></div></div>';
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
// The button in the frame carries a marker seeded per document, so each page
// compiled its own. The generic template keeps one set, and the rules aimed at
// those markers are site-wide, so any page rendered in it is styled.
preg_match('/blocks-engine-control-[0-9a-f]{12}-\d+/', $templates['page'], $marker);
$globalRule = array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null) && array(array('kind' => 'global')) === ($asset['scopes'] ?? array()) && isset($marker[0]) && str_contains((string) ($asset['content'] ?? ''), $marker[0]) && str_contains((string) $asset['content'], 'border-radius:99px'));
$assert(isset($marker[0]) && array() !== $globalRule, 'The rules for the frame button are in a site-wide stylesheet.');
$bootstrap = '';
foreach ($plan['writes'] as $write) if (str_ends_with((string) $write['target_path'], 'functions.php')) $bootstrap = (string) ($write['payload']['data'] ?? '');
$assert(1 === preg_match('/if \( ! \$blocks_engine_route_styles \) wp_enqueue_style\( \'blocks-engine-frame-fallback-/', $bootstrap), 'A page without a stylesheet of its own gets the stylesheet of the page that authored the generic frame.');
foreach ($plan['pages'] as $row) if (in_array($row['slug'], array('a', 'b', 'c', 'odd'), true)) $assert(!str_contains($row['canonical_block_markup'], 'wp:template-part'), $row['slug'] . ' content holds no template part.');

// When every inner page has its own frame, the generic template still carries
// one of them, so a page added later is not left without header and footer.
$distinct = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page($chrome('Home')),
    'a.html' => $page($chrome('A')),
    'b.html' => $page('<div class="outer">' . $chrome('B') . '</div>'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$distinctTemplates = array_column($distinct['templates'], 'canonical_block_markup', 'slug');
$assert(1 === substr_count($distinctTemplates['page'] ?? '', '"slug":"header"') && isset($distinctTemplates['page-b']), 'The generic page template keeps the chrome even when inner pages differ.');

// Frames compare without per-document marker seeds and dialog ids.
$dialog = new ReflectionMethod(ShellExtraction::class, 'withSharedDialogId');
$part = array(array('placement' => array('kind' => 'inline_shared_shell'), 'canonical_block_markup' => '<!-- wp:button {"anchor":"blocks-engine-dialog-trigger-0123456789abcdef-1"} -->'));
$moved = $dialog->invoke(null, array('opening' => '<dialog id="blocks-engine-dialog-fedcba9876543210">', 'closing' => 'blocks-engine-dialog-trigger-fedcba9876543210-1', 'content' => 'x'), $part);
$assert(str_contains($moved['opening'], 'blocks-engine-dialog-0123456789abcdef') && str_contains($moved['closing'], 'trigger-0123456789abcdef-1'), 'The frame dialog takes the id of the trigger in the shared header part.');

$identity = new ReflectionMethod(ShellExtraction::class, 'frameIdentity');
$frame = static fn (string $seed, string $dialog): array => array('opening' => '<!-- wp:group {"className":"min-h-screen blocks-engine-source-div-' . $seed . '-4"} --><div id="blocks-engine-dialog-' . $dialog . '">', 'closing' => '</div><!-- /wp:group -->');
$assert($identity->invoke(null, $frame('aaaaaaaaaaaa', '0123456789abcdef')) === $identity->invoke(null, $frame('bbbbbbbbbbbb', 'fedcba9876543210')), 'Per-page marker seeds and dialog ids do not make frames differ.');
$assert($identity->invoke(null, $frame('aaaaaaaaaaaa', '0123456789abcdef')) !== $identity->invoke(null, array('opening' => '<div class="other">', 'closing' => '</div>')), 'Different structure still differs.');

echo "generic-page-shell-frame: ok\n";

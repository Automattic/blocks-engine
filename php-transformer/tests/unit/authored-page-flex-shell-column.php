<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// An app shell stacks its page in a column flex container, sizes its site
// container in rem and narrows the lead paragraph with a source class. Pages
// that share page.html set their title and lead paragraph at the container's
// left edge, which a centered constrained column cannot express. A page
// authored after the import renders its title and body in that column.
$style = '<style>html{font-size:16px}*{box-sizing:border-box}p{margin:0}.shell{display:flex;flex-direction:column;min-height:100vh}.container{width:100%;max-width:80rem;margin-left:auto;margin-right:auto;padding-left:1.25rem;padding-right:1.25rem}@media (min-width:1024px){.container{padding-left:2.5rem;padding-right:2.5rem}}.lead{max-width:36rem}.intro{max-width:42rem;margin-left:auto;margin-right:auto}</style>';
$page = static fn(string $title, string $body): string => '<html><head><title>' . $title . '</title>' . $style . '</head><body><div class="shell"><header><nav><a href="/">Home</a><a href="/about/">About</a><a href="/menu/">Menu</a></nav></header><main><section><div class="container"><h1>' . $title . '</h1><p class="lead">Lead for ' . $title . '.</p></div></section>' . $body . '</main><footer>Footer</footer></div></body></html>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Welcome', '<section><div class="container"><div class="intro"><p>A centered introduction that the front page alone carries.</p></div></div></section>'),
    'about/index.html' => $page('About', ''),
    'menu/index.html' => $page('Menu', ''),
)))->toArray();
$templates = array_column($compiled['source_reports']['wordpress_site_plan']['templates'], 'canonical_block_markup', 'slug');
$fail = static function (string $message) use ($templates): never { fwrite(STDERR, "FAIL: {$message}\n" . json_encode($templates, JSON_PRETTY_PRINT) . "\n"); exit(1); };
if (str_contains($templates['page'] ?? '', 'wp:post-title')) $fail('captured pages keep their content-owned page.html');
$authored = $templates['authored-page'] ?? $fail('new pages need their own template');
// 120px from the left and 576px wide at 1440px; 20px insets at 390px.
foreach (array('<!-- wp:post-title {"level":1} /-->', 'margin-left:clamp(20px,', ',120px)', 'margin-right:clamp(20px,', ',744px)') as $needle) {
    if (!str_contains($authored, $needle)) $fail("new-page template lacks {$needle}");
}
echo "authored-page-flex-shell-column: passed\n";

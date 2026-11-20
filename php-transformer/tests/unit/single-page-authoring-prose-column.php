<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The common site container is wider than the prose. A new page must not
// inherit that container width or an unconstrained app-shell slot.
$html = '<html><head><title>Atelier</title><style>
.wide{max-width:1280px;margin-left:auto;margin-right:auto}
.prose{max-width:960px;margin-left:auto;margin-right:auto}
.grid{display:grid;grid-template-columns:1fr 1fr}
</style></head><body><div><header><nav><a href="#story">Story</a><a href="#contact">Contact</a></nav></header><main>
<section class="wide grid"><div><h1>Atelier</h1><p>Hero description</p></div><p>Another panel</p></section>
<section id="story" class="wide grid"><p>Grid content</p><p>More grid content</p></section>
<section class="prose"><p>A thoughtful story in the prose column.</p></section>
</main><footer><p>Copyright Atelier</p></footer></div></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'] ?? '', '"contentSize":"960px"') || !str_contains($templates['page'] ?? '', '<!-- wp:post-content {"layout":{"type":"constrained"}} /-->')) {
    fwrite(STDERR, "FAIL: new pages must use the observed prose column\n");
    exit(1);
}
if (str_contains($templates['front-page'] ?? '', '"contentSize":"960px"') || !str_contains($plan['pages'][0]['canonical_block_markup'], 'A thoughtful story')) {
    fwrite(STDERR, "FAIL: the captured entry must retain its own content layout\n");
    exit(1);
}
echo "single-page-authoring-prose-column: passed\n";
